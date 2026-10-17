<?php

namespace App\Services\Dispatch;

use App\Models\Appointment;
use App\Models\DoctorMatchRequest;
use App\Models\LabRequest;
use App\Models\Order;
use App\Models\Ride;
use App\Models\User;
use App\Services\Notifications\InAppNotifier;
use App\Services\Rides\RideDispatchService;
use App\Support\DispatchSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Auto close-out of stale requests so nothing "old" ever shows as live:
 *
 *  Rides (terminal "expired" = cancelled + cancelled_by=system):
 *   - requested / searching / no_drivers with no driver for longer than
 *     dispatch_ride_searching_expiry_minutes (default 15; manual-queue rides
 *     get 4x, min 60)                                   -> expired
 *   - scheduled rides whose pickup time passed by more than
 *     dispatch_ride_scheduled_grace_minutes (default 60) with no driver -> expired
 *   - accepted / enroute / arrived / in_progress with no driver ping or
 *     ride update for dispatch_ride_active_stale_hours (default 6)
 *                                         -> expired + super-admin alert
 *  Appointments: confirmed / checked-in, end time passed by
 *   dispatch_appt_autoclose_hours (default 24) -> completed (or no_show per
 *   setting); pending never confirmed and already past -> cancelled;
 *   "matching" older than the window -> cancelled.
 *  Doctor-now: matched consults older than dispatch_doctor_now_matched_hours
 *   whose appointment is still open -> appointment completed.
 *  Orders (pending payment) and unassigned lab requests: OFF by default
 *   (0) — switch on in Dispatch settings.
 */
class CloseOutService
{
    public function __construct(protected RideDispatchService $rides) {}

    /** @return array<string, int> */
    public function run(bool $force = false): array
    {
        if (! $force && ! Cache::add('dispatch:closeout', 1, 50)) {
            return [];
        }
        $out = [];
        foreach (['rides' => fn () => $this->rides(), 'appointments' => fn () => $this->appointments(), 'doctor_now' => fn () => $this->doctorNow(), 'orders' => fn () => $this->orders(), 'labs' => fn () => $this->labs()] as $k => $fn) {
            try {
                $out[$k] = $fn();
            } catch (\Throwable $e) {
                Log::error('[CloseOut] '.$k.' failed: '.$e->getMessage());
                $out[$k] = -1;
            }
        }

        return $out;
    }

    public function rides(): int
    {
        $n = 0;
        $searchMins = DispatchSettings::int('dispatch_ride_searching_expiry_minutes');
        $grace = max(15, DispatchSettings::int('dispatch_ride_scheduled_grace_minutes'));
        $activeHours = DispatchSettings::int('dispatch_ride_active_stale_hours');

        // 1+2: still requested (no driver). Ride::isStale() is the single rule.
        Ride::query()->where('status', Ride::STATUS_REQUESTED)->whereNull('driver_profile_id')
            ->where(function ($q) use ($searchMins, $grace) {
                $q->where(fn ($s) => $s->whereNotNull('scheduled_for')->where('scheduled_for', '<', now()->subMinutes($grace)))
                    ->orWhere(fn ($s) => $s->whereNull('scheduled_for')->where('requested_at', '<', now()->subMinutes(max(5, $searchMins ?: 100000))));
            })
            ->orderBy('id')->limit(200)->get()
            ->each(function (Ride $ride) use (&$n) {
                if (! $ride->isStale()) {
                    return;
                }
                $note = $ride->scheduled_for
                    ? 'Expired: the scheduled pickup time passed without a driver. You were not charged.'
                    : 'Expired: no driver accepted in time. You were not charged.';
                $this->rides->expireRide($ride, $ride->scheduled_for ? 'scheduled_time_passed' : 'no_driver_in_time', $note);
                $this->notifyPatient($ride, 'Shuttle request closed', $ride->scheduled_for
                    ? 'Your scheduled shuttle ('.$ride->ride_ref.') was closed because the pickup time passed without a driver. You were not charged — book again any time.'
                    : 'We could not find a driver for '.$ride->ride_ref.' in time. You were not charged — you can request again with a wider radius.');
                $n++;
            });

        // 3: trips with a driver but no activity for hours.
        if ($activeHours > 0) {
            Ride::query()->whereIn('status', Ride::DRIVER_ACTIVE_STATUSES)->where('updated_at', '<', now()->subHours($activeHours))
                ->with('driver')->orderBy('id')->limit(100)->get()
                ->each(function (Ride $ride) use (&$n) {
                    if (! $ride->isStale()) {
                        return;
                    }
                    $was = str_replace('_', ' ', (string) $ride->status);
                    $this->rides->expireRide($ride, 'no_driver_activity', 'Closed automatically: no trip activity from the driver for several hours.');
                    $this->alertAdmins('Stale trip closed: '.$ride->ride_ref, 'Trip was '.$was.' with no driver activity for hours and was closed automatically. Check with the driver ('.($ride->driver?->user?->name ?? 'unknown').') and the patient.', '/staff/rides/'.$ride->id);
                    $n++;
                });
        }

        return $n;
    }

    public function appointments(): int
    {
        $hours = DispatchSettings::int('dispatch_appt_autoclose_hours');
        if ($hours <= 0) {
            return 0;
        }
        $n = 0;
        $tz = Appointment::CLINIC_TIMEZONE;
        $cutoffLocal = now()->setTimezone($tz)->subHours($hours);
        $target = DispatchSettings::str('dispatch_appt_autoclose_status') === 'no_show' ? Appointment::STATUS_NO_SHOW : Appointment::STATUS_COMPLETED;

        $base = Appointment::query()->where('date', '<=', $cutoffLocal->toDateString());
        $past = function (Appointment $a) use ($cutoffLocal, $tz) {
            $end = \Illuminate\Support\Carbon::parse($a->date->toDateString().' '.substr((string) ($a->end_time ?: $a->start_time), 0, 5), $tz);

            return $end->lt($cutoffLocal);
        };

        (clone $base)->whereIn('status', [Appointment::STATUS_CONFIRMED, Appointment::STATUS_CHECKED_IN])->orderBy('id')->limit(200)->get()
            ->each(function (Appointment $a) use (&$n, $past, $target) {
                if ($past($a)) {
                    $a->update(['status' => $target]);
                    $n++;
                }
            });

        (clone $base)->whereIn('status', [Appointment::STATUS_PENDING, Appointment::STATUS_MATCHING])->orderBy('id')->limit(200)->get()
            ->each(function (Appointment $a) use (&$n, $past) {
                if ($past($a)) {
                    $a->update(['status' => Appointment::STATUS_CANCELLED, 'cancel_reason' => $a->cancel_reason ?: 'Closed automatically: the appointment time passed before it was confirmed.']);
                    app(DispatchService::class)->cancelFor($a, 'time_passed');
                    $n++;
                }
            });

        return $n;
    }

    public function doctorNow(): int
    {
        $hours = DispatchSettings::int('dispatch_doctor_now_matched_hours');
        if ($hours <= 0) {
            return 0;
        }
        $n = 0;
        DoctorMatchRequest::query()->where('status', DoctorMatchRequest::MATCHED)->where('matched_at', '<', now()->subHours($hours))
            ->whereHas('appointment', fn ($a) => $a->whereIn('status', [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED, Appointment::STATUS_CHECKED_IN]))
            ->with('appointment')->orderBy('id')->limit(100)->get()
            ->each(function (DoctorMatchRequest $r) use (&$n) {
                $r->appointment?->update(['status' => Appointment::STATUS_COMPLETED]);
                $n++;
            });

        return $n;
    }

    public function orders(): int
    {
        $hours = DispatchSettings::int('dispatch_order_pending_payment_hours');
        if ($hours <= 0) {
            return 0;
        }

        return Order::query()->where('status', Order::STATUS_PENDING_PAYMENT)->where('payment_status', 'unpaid')
            ->where('created_at', '<', now()->subHours($hours))->orderBy('id')->limit(200)->get()
            ->each(fn (Order $o) => $o->update(['status' => Order::STATUS_CANCELLED]))->count();
    }

    public function labs(): int
    {
        $days = DispatchSettings::int('dispatch_lab_unassigned_days');
        if ($days <= 0) {
            return 0;
        }

        return LabRequest::query()->where('status', LabRequest::STATUS_REQUESTED)->whereNull('third_party_profile_id')
            ->where('created_at', '<', now()->subDays($days))->orderBy('id')->limit(200)->get()
            ->each(fn (LabRequest $r) => $r->update(['status' => LabRequest::STATUS_CANCELLED]))->count();
    }

    protected function notifyPatient(Ride $ride, string $title, string $body): void
    {
        try {
            $user = $ride->patient?->user;
            if ($user) {
                app(InAppNotifier::class)->send($user, 'ride', $title, $body, ['route' => 'ride', 'params' => ['ride_id' => $ride->id], 'url' => '/rides/'.$ride->id.'/track']);
            }
        } catch (\Throwable) {
        }
    }

    protected function alertAdmins(string $title, string $body, string $url): void
    {
        $notifier = app(InAppNotifier::class);
        User::query()->where('role', User::ROLE_SUPER_ADMIN)->where('status', 'active')->pluck('id')
            ->each(fn ($id) => $notifier->send((int) $id, 'ride', $title, $body, ['url' => $url]));
    }
}
