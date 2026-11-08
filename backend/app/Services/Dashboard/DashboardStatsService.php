<?php

namespace App\Services\Dashboard;

use App\Models\Appointment;
use App\Models\DispatchJob;
use App\Models\DispatchOffer;
use App\Models\DoctorMatchOffer;
use App\Models\DoctorMatchRequest;
use App\Models\DoctorProfile;
use App\Models\DriverProfile;
use App\Models\LabRequest;
use App\Models\Order;
use App\Models\PaymentIntent;
use App\Models\Pharmacy;
use App\Models\Ride;
use App\Models\RideCharge;
use App\Models\RideOffer;
use App\Models\User;
use App\Support\DispatchSettings;
use App\Support\DoctorNowSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Live numbers for every landing dashboard (staff / vendor / partner panels,
 * driver console, patient /dashboard) and GET /api/dashboard/stats.
 *
 * One payload per user (super admins share one), cached for TTL seconds so a
 * page polling every 15 s costs at most one set of aggregate queries per
 * user per TTL. Everything is plain arrays/scalars (cache + JSON safe) and
 * scoped: clinic admins see their clinics, doctors themselves, vendors their
 * pharmacies, partners their profile, drivers/patients their own records.
 *
 * Day boundaries are South African local days (UTC+2, no DST).
 */
class DashboardStatsService
{
    public const TZ = 'Africa/Johannesburg';

    public const TTL = 15;

    public const LOW_STOCK = 5;

    public const SERVICE_LABELS = [
        'shuttle' => 'Shuttle rides',
        'doctor_now' => 'Doctor now',
        'appointment' => 'Appointment confirmations',
        'doctor_later' => 'Doctor later',
        'pharmacy_order' => 'Pharmacy orders',
        'script_review' => 'Script reviews',
        'lab_request' => 'Lab requests',
    ];

    public function forUser(User $user, bool $fresh = false): array
    {
        $key = self::cacheKey($user);
        $build = function () use ($user) {
            return [
                'role' => (string) $user->role,
                'generated_at' => now()->toIso8601String(),
                'refresh_seconds' => self::TTL,
                'data' => $this->build($user),
            ];
        };

        return $fresh ? $build() : Cache::remember($key, self::TTL, $build);
    }

    public static function cacheKey(User $user): string
    {
        return $user->role === User::ROLE_SUPER_ADMIN ? 'dash:v1:admin' : 'dash:v1:'.$user->role.':'.$user->id;
    }

    public static function forget(User $user): void
    {
        Cache::forget(self::cacheKey($user));
    }

    protected function build(User $user): array
    {
        return match ($user->role) {
            User::ROLE_SUPER_ADMIN => $this->admin(),
            User::ROLE_CLINIC_ADMIN => $this->clinic($user),
            User::ROLE_DOCTOR => $this->doctor($user),
            User::ROLE_PHARMACY_ADMIN => $this->pharmacy($user),
            User::ROLE_THIRD_PARTY => $this->partner($user),
            User::ROLE_DRIVER => $this->driver($user),
            User::ROLE_PATIENT => $this->patient($user),
            default => [],
        };
    }

    // ------------------------------------------------------------------
    // Super admin — command centre
    // ------------------------------------------------------------------

    public function admin(): array
    {
        $now = now();
        $today = $this->dayStart();
        $yesterday = $this->dayStart(1);
        $week = $this->dayStart(6);
        $localToday = $this->localNow()->toDateString();
        $localTomorrow = $this->localNow()->addDay()->toDateString();
        $localTime = $this->localNow()->format('H:i:s');

        // Rides: filter "open" rows through Ride::isActive() so stale ones never count as live.
        $openRides = Ride::query()->whereNotIn('status', Ride::TERMINAL)->latest('id')->limit(500)->get();
        $activeRides = $openRides->filter(fn (Ride $r) => $r->isActive());
        $searching = $activeRides->where('status', Ride::STATUS_REQUESTED);

        $freshDoctor = $now->copy()->subSeconds(max(30, DoctorNowSettings::int('doctor_now_heartbeat_stale_seconds')));
        $liveConsults = $this->liveConsultQuery();

        $live = [
            'shuttle_open' => $searching->count(),
            'shuttle_awaiting_payment' => $searching->where('dispatch_state', Ride::DISPATCH_AWAITING_PAYMENT)->count(),
            'shuttle_no_drivers' => $searching->where('dispatch_state', Ride::DISPATCH_NO_DRIVERS)->count(),
            'rides_in_progress' => $activeRides->whereIn('status', Ride::DRIVER_ACTIVE_STATUSES)->count(),
            'drivers_online' => DriverProfile::query()->where('availability', DriverProfile::AVAILABLE)->count(),
            'drivers_busy' => DriverProfile::query()->where('availability', DriverProfile::BUSY)->count(),
            'doctors_now' => DoctorProfile::query()->where('availability', DoctorProfile::AVAILABLE)->where('last_seen_at', '>=', $freshDoctor)->count(),
            'doctors_later' => DB::table('provider_availability')->where('role', User::ROLE_DOCTOR)->whereDate('later_date', $localToday)->where('later_to', '>', $localTime)->count(),
            'doctors_tomorrow' => DB::table('provider_availability')->where('role', User::ROLE_DOCTOR)->whereDate('tomorrow_date', $localTomorrow)->count(),
            'doctor_now_searching' => DoctorMatchRequest::query()->where('status', DoctorMatchRequest::SEARCHING)->count(),
            'consults_live' => (clone $liveConsults)->count(),
            'orange_consults_live' => (clone $liveConsults)->join('triage_submissions as ts', 'ts.id', '=', 'a.triage_submission_id')->where('ts.level', 'orange')->count(),
            'orders_awaiting' => Order::query()->where('status', Order::STATUS_CONFIRMED)->count(),
            'scripts_awaiting' => DB::table('prescription_uploads')->where('status', 'pending_review')->count(),
            'labs_unassigned' => LabRequest::query()->where('status', LabRequest::STATUS_REQUESTED)->whereNull('third_party_profile_id')->count(),
            'payments_awaiting' => PaymentIntent::query()->whereIn('status', [PaymentIntent::STATUS_PENDING, PaymentIntent::STATUS_PROCESSING])->where('created_at', '>=', $now->copy()->subDay())->count(),
            'payments_held' => PaymentIntent::query()->where('status', PaymentIntent::STATUS_AUTHORIZED)->count(),
            'dispatch_manual' => DispatchJob::query()->where('status', DispatchJob::MANUAL)->count(),
        ];

        // Today vs yesterday, with a 7-day sparkline per request type.
        $volume = [];
        foreach ([
            'shuttle' => [Ride::query(), 'created_at'],
            'doctor_now' => [DoctorMatchRequest::query(), 'created_at'],
            'appointments' => [Appointment::query(), 'created_at'],
            'orders' => [Order::query(), 'created_at'],
            'lab_requests' => [LabRequest::query(), 'created_at'],
        ] as $k => [$q, $col]) {
            $volume[$k] = $this->todayVsYesterday($q, $col);
        }

        $performance = $this->performance($week, $today);

        $cancellations = [
            'rides_cancelled' => Ride::query()->where('status', Ride::STATUS_CANCELLED)->where('cancelled_at', '>=', $today)->where(fn ($w) => $w->whereNull('cancelled_by')->orWhere('cancelled_by', '!=', Ride::CANCELLED_BY_SYSTEM))->count(),
            'rides_expired' => Ride::query()->where('status', Ride::STATUS_CANCELLED)->where('cancelled_by', Ride::CANCELLED_BY_SYSTEM)->where('updated_at', '>=', $today)->count(),
            'doctor_now_expired' => DoctorMatchRequest::query()->where('status', DoctorMatchRequest::EXPIRED)->where('updated_at', '>=', $today)->count(),
            'doctor_now_cancelled' => DoctorMatchRequest::query()->where('status', DoctorMatchRequest::CANCELLED)->where('updated_at', '>=', $today)->count(),
            'jobs_expired' => DispatchJob::query()->where('status', DispatchJob::EXPIRED)->where('updated_at', '>=', $today)->count(),
            'orders_cancelled' => Order::query()->where('status', Order::STATUS_CANCELLED)->where('updated_at', '>=', $today)->count(),
            'appointments_cancelled' => Appointment::query()->where('status', Appointment::STATUS_CANCELLED)->where('updated_at', '>=', $today)->count(),
        ];
        $cancellations['total'] = array_sum($cancellations);

        return [
            'live' => $live,
            'volume' => $volume,
            'performance' => $performance,
            'cancellations' => $cancellations,
            'revenue' => $this->platformRevenue(),
            'alerts' => $this->adminAlerts($live),
        ];
    }

    /** consult_sessions connected and not ended (ignores sessions left open > 6 h). */
    protected function liveConsultQuery()
    {
        return DB::table('consult_sessions as cs')
            ->join('appointments as a', 'a.id', '=', 'cs.appointment_id')
            ->whereNotNull('cs.connected_at')->whereNull('cs.ended_at')
            ->where('cs.updated_at', '>=', now()->subHours(6));
    }

    /** Fulfilment rate + median time-to-accept per service (today and last 7 days). */
    protected function performance(Carbon $week, Carbon $today): array
    {
        $out = [];

        $rides = fn () => Ride::query()->toBase();
        $out['shuttle'] = $this->fulfilment(
            $rides,
            fn ($q) => $q->whereNotNull('accepted_at'),
            fn ($q) => $q->where('status', Ride::STATUS_CANCELLED)->whereNull('accepted_at'),
            'created_at', 'requested_at', 'accepted_at', $week, $today,
        );

        $dm = fn () => DoctorMatchRequest::query()->toBase();
        $out['doctor_now'] = $this->fulfilment(
            $dm,
            fn ($q) => $q->whereNotNull('matched_at'),
            fn ($q) => $q->whereIn('status', [DoctorMatchRequest::EXPIRED, DoctorMatchRequest::CANCELLED])->whereNull('matched_at'),
            'created_at', 'created_at', 'matched_at', $week, $today,
        );

        $services = DispatchJob::query()->where('created_at', '>=', $week)->distinct()->pluck('service')->all();
        foreach ($services as $svc) {
            $jobs = fn () => DispatchJob::query()->toBase()->where('service', $svc);
            $out[$svc] = $this->fulfilment(
                $jobs,
                fn ($q) => $q->where('status', DispatchJob::ACCEPTED),
                fn ($q) => $q->where('status', DispatchJob::EXPIRED),
                'created_at', 'created_at', 'accepted_at', $week, $today,
            );
        }

        foreach ($out as $k => $row) {
            $out[$k]['label'] = self::SERVICE_LABELS[$k] ?? str($k)->replace('_', ' ')->title()->toString();
        }

        return $out;
    }

    /**
     * @param  callable():\Illuminate\Database\Query\Builder  $base
     */
    protected function fulfilment(callable $base, callable $fulfilled, callable $failed, string $createdCol, string $startCol, string $acceptCol, Carbon $week, Carbon $today): array
    {
        $row = [];
        foreach (['today' => $today, 'week' => $week] as $k => $from) {
            $total = $base()->where($createdCol, '>=', $from)->count();
            $ok = $fulfilled($base()->where($createdCol, '>=', $from))->count();
            $bad = $failed($base()->where($createdCol, '>=', $from))->count();
            $row[$k] = [
                'requests' => $total,
                'fulfilled' => $ok,
                'failed' => $bad,
                'open' => max(0, $total - $ok - $bad),
                'rate' => ($ok + $bad) > 0 ? round($ok / ($ok + $bad) * 100) : null,
            ];
        }
        $pairs = $base()->where($createdCol, '>=', $week)->whereNotNull($acceptCol)->limit(2000)->get([$startCol, $acceptCol]);
        $row['median_accept_seconds'] = $this->medianSeconds($pairs, $startCol, $acceptCol);

        return $row;
    }

    /** Revenue this month / today / yesterday per service, + 7-day series. */
    protected function platformRevenue(): array
    {
        $earnings = $this->earnings('platform', 0);
        $month = $this->dayStartOfMonth();
        $today = $this->dayStart();
        $yesterday = $this->dayStart(1);

        // Separate sources so nothing is counted twice: invoice payments
        // (consults/appointments, incl. card intents that settle invoices),
        // paid pharmacy orders, paid shuttle charges, paid subscriptions.
        $sources = [
            'consults' => [DB::table('payments')->where('status', 'success'), 'created_at', 'amount'],
            'pharmacy' => [DB::table('orders')->where('payment_status', 'paid')->where('status', '!=', Order::STATUS_CANCELLED), 'created_at', 'total'],
            'shuttle' => [DB::table('ride_charges')->where('status', RideCharge::STATUS_PAID), 'updated_at', 'amount'],
            'subscriptions' => [DB::table('payment_intents')->where('status', PaymentIntent::STATUS_PAID)->where('payable_type', \App\Models\Subscription::class), 'paid_at', 'amount'],
        ];

        $by = [];
        $series = array_fill(0, 7, 0.0);
        $totals = ['today' => 0.0, 'yesterday' => 0.0, 'month' => 0.0];
        foreach ($sources as $k => [$q, $col, $amt]) {
            $m = (float) (clone $q)->where($col, '>=', $month)->sum($amt);
            $t = (float) (clone $q)->where($col, '>=', $today)->sum($amt);
            $y = (float) (clone $q)->where($col, '>=', $yesterday)->where($col, '<', $today)->sum($amt);
            $by[$k] = ['month' => round($m, 2), 'today' => round($t, 2)];
            $totals['month'] += $m;
            $totals['today'] += $t;
            $totals['yesterday'] += $y;
            foreach ($this->series(clone $q, $col, 7, $amt) as $i => $v) {
                $series[$i] += $v;
            }
        }

        return [
            'currency' => 'ZAR',
            'today' => round($totals['today'], 2),
            'yesterday' => round($totals['yesterday'], 2),
            'month' => round($totals['month'], 2),
            'by_service' => $by,
            'series' => array_map(fn ($v) => round($v, 2), $series),
            'ledger' => $earnings, // platform commission from the finance ledger, when installed
        ];
    }

    protected function adminAlerts(array $live): array
    {
        $alerts = [];
        $add = function (string $level, string $title, int $count, ?string $url = null, ?string $hint = null) use (&$alerts) {
            if ($count > 0) {
                $alerts[] = compact('level', 'title', 'count', 'url', 'hint');
            }
        };
        $today = $this->dayStart();

        $add('danger', 'Rides with no driver found', (int) $live['shuttle_no_drivers'], url('/staff/live-requests'), 'Assign a driver manually');
        $add('warning', 'Requests waiting for manual assignment', (int) $live['dispatch_manual'], url('/staff/live-requests'));
        $add('warning', 'Requests expired today', Ride::query()->where('cancelled_by', Ride::CANCELLED_BY_SYSTEM)->where('updated_at', '>=', $today)->count()
            + DoctorMatchRequest::query()->where('status', DoctorMatchRequest::EXPIRED)->where('updated_at', '>=', $today)->count()
            + DispatchJob::query()->where('status', DispatchJob::EXPIRED)->where('updated_at', '>=', $today)->count(), url('/staff/live-requests'));
        $add('danger', 'Failed payments today', PaymentIntent::query()->where('status', PaymentIntent::STATUS_FAILED)->where('updated_at', '>=', $today)->count(), url('/staff/payment-intents'));
        $add('danger', 'Released holds needing a manual refund', PaymentIntent::query()->where('failure_reason', 'like', 'RELEASE NEEDS MANUAL REFUND%')->whereNull('refunded_at')->count(), url('/staff/payment-intents'), 'Refund in the gateway dashboard (e.g. Ozow)');
        $add('warning', 'Overdue data-subject requests (POPIA)', DB::table('dsr_requests')->whereIn('status', ['received', 'in_progress'])->where('due_at', '<', now())->count(), url('/staff/dsr-requests'));
        $add('warning', 'Driver documents expired or expiring in 14 days', DB::table('driver_documents')->whereNotNull('expires_at')->where('expires_at', '<=', now()->addDays(14))->whereIn('type', DriverProfile::EXPIRING_DOCUMENT_TYPES)->count(), url('/staff/expiring-documents'));
        $add('info', 'Doctor-now patients still searching', (int) $live['doctor_now_searching'], url('/staff/live-requests'));

        return $alerts;
    }

    // ------------------------------------------------------------------
    // Clinic admin
    // ------------------------------------------------------------------

    public function clinic(User $user): array
    {
        $clinicIds = $user->clinicsAdministered()->pluck('id')->all();
        $localToday = $this->localNow()->toDateString();
        $nowTime = $this->localNow()->format('H:i:s');
        $lateTime = $this->localNow()->subMinutes(10)->format('H:i:s');

        $appts = fn () => Appointment::query()->whereIn('clinic_id', $clinicIds);
        $byStatus = $appts()->whereDate('date', $localToday)->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status')->map(fn ($v) => (int) $v)->all();

        $doctorIds = DB::table('clinic_doctor')->whereIn('clinic_id', $clinicIds)->distinct()->pluck('doctor_profile_id')->all();
        $freshDoctor = now()->subSeconds(max(30, DoctorNowSettings::int('doctor_now_heartbeat_stale_seconds')));

        $revenue = fn (Carbon $from, ?Carbon $to = null) => (float) DB::table('payments')->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->where('payments.status', 'success')->whereIn('invoices.clinic_id', $clinicIds)
            ->where('payments.created_at', '>=', $from)->when($to, fn ($q) => $q->where('payments.created_at', '<', $to))->sum('payments.amount');

        // Utilisation per doctor: booked vs bookable slots today (weekly hours at these clinics).
        $weekday = $this->localNow()->dayOfWeek;
        $slots = DB::table('doctor_availabilities')->whereIn('clinic_id', $clinicIds)->where('weekday', $weekday)->where('is_active', true)
            ->get(['doctor_profile_id', 'start_time', 'end_time', 'slot_minutes'])
            ->groupBy('doctor_profile_id')
            ->map(fn ($rows) => $rows->sum(function ($r) {
                $mins = (strtotime('1970-01-01 '.$r->end_time.' UTC') - strtotime('1970-01-01 '.$r->start_time.' UTC')) / 60;

                return $r->slot_minutes > 0 && $mins > 0 ? intdiv((int) $mins, (int) $r->slot_minutes) : 0;
            }));
        $booked = $appts()->whereDate('date', $localToday)->whereNotIn('status', [Appointment::STATUS_CANCELLED])
            ->selectRaw('doctor_profile_id, COUNT(*) as c, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as done', [Appointment::STATUS_COMPLETED])
            ->groupBy('doctor_profile_id')->get()->keyBy('doctor_profile_id');
        $doctors = DoctorProfile::query()->with('user:id,name')->whereIn('id', $doctorIds)->get(['id', 'user_id', 'specialization', 'availability', 'last_seen_at']);
        $utilisation = $doctors->map(function (DoctorProfile $d) use ($slots, $booked, $freshDoctor) {
            $s = (int) ($slots[$d->id] ?? 0);
            $b = (int) ($booked[$d->id]->c ?? 0);

            return [
                'doctor' => $d->user?->name ?? 'Doctor #'.$d->id,
                'specialization' => $d->specialization,
                'online' => $d->availability === DoctorProfile::AVAILABLE && $d->last_seen_at && $d->last_seen_at->gte($freshDoctor),
                'booked' => $b,
                'completed' => (int) ($booked[$d->id]->done ?? 0),
                'slots' => $s,
                'utilisation' => $s > 0 ? min(100, (int) round($b / $s * 100)) : null,
            ];
        })->sortByDesc('booked')->values()->take(12)->all();

        $openToday = fn () => $appts()->whereDate('date', $localToday)->whereIn('status', [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED]);

        $ledger = null;
        foreach ($clinicIds as $cid) {
            if ($e = $this->earnings('clinic', (int) $cid)) {
                $ledger ??= ['source' => 'ledger', 'today' => 0.0, 'month' => 0.0];
                $ledger['today'] += $e['today'];
                $ledger['month'] += $e['month'];
            }
        }

        return [
            'clinics' => count($clinicIds),
            'appointments_today' => array_sum($byStatus),
            'by_status' => $byStatus,
            'doctors_total' => count($doctorIds),
            'doctors_online' => $doctors->filter(fn ($d) => $d->availability === DoctorProfile::AVAILABLE && $d->last_seen_at && $d->last_seen_at->gte($freshDoctor))->count(),
            'consults_live' => $this->liveConsultQuery()->whereIn('a.clinic_id', $clinicIds)->count(),
            'waiting' => (int) ($byStatus[Appointment::STATUS_CHECKED_IN] ?? 0),
            'late' => $openToday()->whereNotNull('start_time')->where('start_time', '<', $lateTime)->count(),
            'upcoming' => $openToday()->where('start_time', '>=', $nowTime)->count(),
            'no_shows' => (int) ($byStatus[Appointment::STATUS_NO_SHOW] ?? 0),
            'pending_confirmation' => $appts()->where('status', Appointment::STATUS_PENDING)->whereDate('date', '>=', $localToday)->count(),
            'revenue' => [
                'today' => round($revenue($this->dayStart()), 2),
                'yesterday' => round($revenue($this->dayStart(1), $this->dayStart()), 2),
                'month' => round($revenue($this->dayStartOfMonth()), 2),
            ],
            'earnings' => $ledger, // clinic share from the finance ledger, when installed
            'series' => $this->seriesDate($appts(), 'date'),
            'utilisation' => $utilisation,
        ];
    }

    // ------------------------------------------------------------------
    // Doctor
    // ------------------------------------------------------------------

    public function doctor(User $user): array
    {
        $doc = $user->doctorProfile;
        if (! $doc) {
            return [];
        }
        $now = now();
        $localNow = $this->localNow();
        $localToday = $localNow->toDateString();

        // Incoming right now: doctor-now offers + generic dispatch offers (appointments, doctor later).
        $incoming = [];
        DoctorMatchOffer::query()->with('matchRequest')->where('doctor_profile_id', $doc->id)->where('status', DoctorMatchOffer::OFFERED)
            ->where('expires_at', '>', $now)->latest('id')->limit(5)->get()
            ->each(function (DoctorMatchOffer $o) use (&$incoming) {
                $incoming[] = [
                    'service' => 'Doctor now', 'detail' => trim(($o->matchRequest?->specialty ?? '').' · '.str_replace('_', ' ', (string) $o->matchRequest?->mode), ' ·'),
                    'expires_in' => max(0, (int) now()->diffInSeconds($o->expires_at, false)),
                ];
            });
        DispatchOffer::query()->with('job')->where('provider_user_id', $user->id)->where('status', DispatchOffer::PENDING)
            ->where('expires_at', '>', $now)->latest('id')->limit(5)->get()
            ->each(function (DispatchOffer $o) use (&$incoming) {
                $incoming[] = [
                    'service' => self::SERVICE_LABELS[$o->job?->service] ?? 'Request', 'detail' => $o->distance_km !== null ? number_format((float) $o->distance_km, 1).' km away' : '',
                    'expires_in' => max(0, (int) now()->diffInSeconds($o->expires_at, false)),
                ];
            });

        // Today's schedule.
        $schedule = Appointment::query()->with('patient.user:id,name')->where('doctor_profile_id', $doc->id)->whereDate('date', $localToday)
            ->whereNotIn('status', [Appointment::STATUS_CANCELLED])->orderBy('start_time')->limit(40)->get();
        $sessions = DB::table('consult_sessions')->whereIn('appointment_id', $schedule->pluck('id'))->get(['appointment_id', 'connected_at', 'ended_at'])->keyBy('appointment_id');
        $next = null;
        $timeline = $schedule->map(function (Appointment $a) use ($localToday, $localNow, $sessions, &$next) {
            $start = $a->start_time ? Carbon::parse($localToday.' '.$a->start_time, self::TZ) : null;
            $s = $sessions[$a->id] ?? null;
            $live = $s && $s->connected_at && ! $s->ended_at;
            $remote = $a->isVideo() || $a->mode === Appointment::MODE_CALLBACK || $a->visit_type === Appointment::VISIT_PHONE;
            $open = in_array($a->status, [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED, Appointment::STATUS_CHECKED_IN], true);
            $row = [
                'id' => $a->id,
                'time' => $start?->format('H:i'),
                'start_iso' => $start?->toIso8601String(),
                'patient' => $a->patient?->user?->name ?? 'Patient',
                'mode' => $a->mode === Appointment::MODE_CALLBACK ? 'call back' : ($a->isVideo() ? 'video' : 'in person'),
                'status' => $live ? 'in consult' : (string) $a->status,
                'remote' => $remote,
                'live' => $live,
                'consult_url' => $remote && ($open || $live) ? $this->consultUrl($a->id, 'doctor') : null,
            ];
            if ($next === null && $open && $start && $start->gte($localNow->copy()->subMinutes(15))) {
                $next = $row + ['starts_in_seconds' => (int) $localNow->diffInSeconds($start, false)];
            }

            return $row;
        })->all();

        // Request stats (doctor-now offers + dispatch offers).
        $reqStats = function (Carbon $from) use ($doc, $user) {
            $a = DoctorMatchOffer::query()->where('doctor_profile_id', $doc->id)->where('offered_at', '>=', $from)
                ->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');
            $b = DispatchOffer::query()->where('provider_user_id', $user->id)->where('offered_at', '>=', $from)
                ->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');
            $accepted = (int) ($a['accepted'] ?? 0) + (int) ($b['accepted'] ?? 0);
            $declined = (int) ($a['declined'] ?? 0) + (int) ($b['declined'] ?? 0);
            $missed = (int) ($a['expired'] ?? 0) + (int) ($b['expired'] ?? 0);
            $received = (int) $a->sum() + (int) $b->sum();
            $pairs = DoctorMatchOffer::query()->toBase()->where('doctor_profile_id', $doc->id)->where('offered_at', '>=', $from)->whereNotNull('responded_at')->limit(1000)->get(['offered_at', 'responded_at'])
                ->concat(DispatchOffer::query()->toBase()->where('provider_user_id', $user->id)->where('offered_at', '>=', $from)->whereNotNull('responded_at')->whereIn('status', ['accepted', 'declined'])->limit(1000)->get(['offered_at', 'responded_at']));

            return [
                'received' => $received, 'accepted' => $accepted, 'declined' => $declined, 'missed' => $missed,
                'acceptance_rate' => ($accepted + $declined + $missed) > 0 ? (int) round($accepted / ($accepted + $declined + $missed) * 100) : null,
                'avg_response_seconds' => $this->avgSeconds($pairs, 'offered_at', 'responded_at'),
            ];
        };

        $completed = fn (Carbon $from) => Appointment::query()->where('doctor_profile_id', $doc->id)->where('status', Appointment::STATUS_COMPLETED)->where('updated_at', '>=', $from)->count();

        $earnings = $this->earnings('doctor', $doc->id);
        if ($earnings === null) {
            $billed = fn (Carbon $from) => (float) DB::table('payments')->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
                ->join('appointments', 'appointments.id', '=', 'invoices.appointment_id')
                ->where('payments.status', 'success')->where('appointments.doctor_profile_id', $doc->id)
                ->where('payments.created_at', '>=', $from)->sum('payments.amount');
            $earnings = ['source' => 'payments', 'today' => round($billed($this->dayStart()), 2), 'month' => round($billed($this->dayStartOfMonth()), 2)];
        }

        $unread = DB::table('messages')->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->where('conversations.doctor_profile_id', $doc->id)->where('messages.sender_user_id', '!=', $user->id)->whereNull('messages.read_at')->count();

        $fresh = $doc->last_seen_at && $doc->last_seen_at->gte($now->copy()->subSeconds(max(30, DoctorNowSettings::int('doctor_now_heartbeat_stale_seconds'))));

        return [
            'availability' => (string) ($doc->availability ?? DoctorProfile::OFFLINE),
            'heartbeat_fresh' => (bool) $fresh,
            'accepting_appointments' => (bool) $doc->is_accepting_appointments,
            'incoming' => $incoming,
            'pending_confirmation' => Appointment::query()->where('doctor_profile_id', $doc->id)->where('status', Appointment::STATUS_PENDING)->whereDate('date', '>=', $localToday)->count(),
            'schedule' => $timeline,
            'next' => $next,
            'requests' => ['today' => $reqStats($this->dayStart()), 'week' => $reqStats($this->dayStart(6))],
            'consults_completed' => ['today' => $completed($this->dayStart()), 'month' => $completed($this->dayStartOfMonth())],
            'earnings' => $earnings,
            'rating' => ['avg' => round((float) $doc->rating_avg, 1), 'count' => (int) $doc->rating_count],
            'unread_messages' => $unread,
            'series' => $this->series(Appointment::query()->where('doctor_profile_id', $doc->id)->where('status', Appointment::STATUS_COMPLETED), 'updated_at'),
        ];
    }

    // ------------------------------------------------------------------
    // Pharmacy (vendor)
    // ------------------------------------------------------------------

    public function pharmacy(User $user): array
    {
        $ids = Pharmacy::query()->where('vendor_id', $user->id)->pluck('id')->all();
        $orders = fn () => Order::query()->whereIn('pharmacy_id', $ids);
        $month = $this->dayStartOfMonth();
        $since30 = $this->dayStart(29);

        $pipeline = $orders()->whereNotIn('status', [Order::STATUS_DELIVERED, Order::STATUS_CANCELLED])
            ->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status')->map(fn ($v) => (int) $v)->all();
        $pipeline[Order::STATUS_DELIVERED.'_today'] = $orders()->where('status', Order::STATUS_DELIVERED)->where('updated_at', '>=', $this->dayStart())->count();

        $earnings = null;
        foreach ($ids as $pid) {
            $e = $this->earnings('pharmacy', (int) $pid);
            if ($e !== null) {
                $earnings ??= ['source' => 'ledger', 'today' => 0.0, 'month' => 0.0];
                $earnings['today'] += $e['today'];
                $earnings['month'] += $e['month'];
            }
        }
        $paid = fn (Carbon $from) => (float) $orders()->where('payment_status', 'paid')->where('status', '!=', Order::STATUS_CANCELLED)->where('created_at', '>=', $from)->sum('total');

        $offers = DispatchOffer::query()->where('provider_user_id', $user->id)->where('offered_at', '>=', $since30)
            ->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');
        $decided = (int) ($offers['accepted'] ?? 0) + (int) ($offers['declined'] ?? 0) + (int) ($offers['expired'] ?? 0);

        $acceptPairs = DB::table('dispatch_jobs')->where('service', 'pharmacy_order')->where('target_user_id', $user->id)
            ->where('created_at', '>=', $since30)->whereNotNull('accepted_at')->limit(1000)->get(['created_at', 'accepted_at']);
        $deliverPairs = $orders()->toBase()->where('status', Order::STATUS_DELIVERED)->where('created_at', '>=', $since30)->limit(1000)->get(['created_at', 'updated_at']);

        $awaiting = $orders()->with('patient.user:id,name')->where('status', Order::STATUS_CONFIRMED)->oldest('updated_at')->limit(6)
            ->get(['id', 'order_no', 'patient_profile_id', 'total', 'created_at', 'updated_at'])
            ->map(fn (Order $o) => [
                'id' => $o->id, 'order_no' => $o->order_no, 'patient' => $o->patient?->user?->name, 'total' => (float) $o->total,
                'waiting_minutes' => (int) $o->updated_at?->diffInMinutes(now(), true),
            ])->all();

        $lowStock = DB::table('products')->whereIn('pharmacy_id', $ids)->where('is_active', true)->whereNotNull('stock')->where('stock', '<=', self::LOW_STOCK)
            ->where(fn ($q) => $q->whereNull('kind')->orWhere('kind', 'goods'));

        return [
            'pharmacies' => count($ids),
            'awaiting_acceptance' => $orders()->where('status', Order::STATUS_CONFIRMED)->count(),
            'scripts_to_review' => DB::table('prescription_uploads')->join('orders', 'orders.id', '=', 'prescription_uploads.order_id')
                ->whereIn('orders.pharmacy_id', $ids)->where('prescription_uploads.status', 'pending_review')->count(),
            'pipeline' => $pipeline,
            'awaiting' => $awaiting,
            'orders_today' => $orders()->where('created_at', '>=', $this->dayStart())->count(),
            'orders_yesterday' => $orders()->where('created_at', '>=', $this->dayStart(1))->where('created_at', '<', $this->dayStart())->count(),
            'revenue' => $earnings ?? ['source' => 'orders', 'today' => round($paid($this->dayStart()), 2), 'month' => round($paid($month), 2)],
            'low_stock_count' => (clone $lowStock)->count(),
            'low_stock' => (clone $lowStock)->orderBy('stock')->limit(6)->get(['id', 'name', 'stock'])->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'stock' => (int) $p->stock])->all(),
            'acceptance_rate' => $decided > 0 ? (int) round(((int) ($offers['accepted'] ?? 0)) / $decided * 100) : null,
            'avg_accept_seconds' => $this->avgSeconds($acceptPairs, 'created_at', 'accepted_at'),
            'avg_fulfilment_seconds' => $this->avgSeconds($deliverPairs, 'created_at', 'updated_at'),
            'series' => $this->series($orders(), 'created_at'),
        ];
    }

    // ------------------------------------------------------------------
    // Partner (labs / allied)
    // ------------------------------------------------------------------

    public function partner(User $user): array
    {
        $tp = $user->thirdPartyProfile;
        if (! $tp) {
            return [];
        }
        $avail = DB::table('provider_availability')->where('user_id', $user->id)->first(['lat', 'lng', 'max_radius_km']);
        $radius = (float) ($avail?->max_radius_km ?: DispatchSettings::defaultRadius());
        $hasLoc = $avail && $avail->lat !== null && $avail->lng !== null;

        $unassigned = LabRequest::query()->where('status', LabRequest::STATUS_REQUESTED)->whereNull('third_party_profile_id')
            ->latest('id')->limit(200)->get(['id', 'request_ref', 'priority', 'collection_address', 'collection_lat', 'collection_lng', 'created_at']);
        $nearby = $unassigned->map(function (LabRequest $r) use ($avail, $hasLoc) {
            $km = $hasLoc && $r->collection_lat !== null && $r->collection_lng !== null
                ? $this->km((float) $avail->lat, (float) $avail->lng, (float) $r->collection_lat, (float) $r->collection_lng) : null;

            return ['id' => $r->id, 'ref' => $r->request_ref, 'priority' => $r->priority, 'area' => $r->collection_address ? mb_strimwidth((string) $r->collection_address, 0, 60, '…') : null,
                'distance_km' => $km !== null ? round($km, 1) : null, 'waiting_minutes' => (int) $r->created_at?->diffInMinutes(now(), true)];
        })->filter(fn ($r) => ! $hasLoc || $r['distance_km'] === null || $r['distance_km'] <= $radius)->values();

        $mine = fn () => LabRequest::query()->where('third_party_profile_id', $tp->id);
        $pipeline = $mine()->whereIn('status', [LabRequest::STATUS_ACCEPTED, LabRequest::STATUS_SAMPLE_COLLECTED, LabRequest::STATUS_PROCESSING])
            ->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status')->map(fn ($v) => (int) $v)->all();
        $turn = $mine()->toBase()->where('status', LabRequest::STATUS_COMPLETED)->where('completed_at', '>=', $this->dayStart(29))->whereNotNull('accepted_at')->limit(1000)->get(['accepted_at', 'completed_at']);

        $offers = DispatchOffer::query()->where('provider_user_id', $user->id)->where('offered_at', '>=', $this->dayStart(29))
            ->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');
        $decided = (int) ($offers['accepted'] ?? 0) + (int) ($offers['declined'] ?? 0) + (int) ($offers['expired'] ?? 0);

        return [
            'company' => $tp->company_name,
            'has_location' => (bool) $hasLoc,
            'radius_km' => $radius,
            'offers_now' => DispatchOffer::query()->where('provider_user_id', $user->id)->where('status', DispatchOffer::PENDING)->where('expires_at', '>', now())->count(),
            'unassigned_nearby' => $nearby->count(),
            'unassigned_list' => $nearby->take(6)->all(),
            'pipeline' => $pipeline,
            'active' => array_sum($pipeline),
            'completed' => [
                'today' => $mine()->where('status', LabRequest::STATUS_COMPLETED)->where('completed_at', '>=', $this->dayStart())->count(),
                'week' => $mine()->where('status', LabRequest::STATUS_COMPLETED)->where('completed_at', '>=', $this->dayStart(6))->count(),
                'month' => $mine()->where('status', LabRequest::STATUS_COMPLETED)->where('completed_at', '>=', $this->dayStartOfMonth())->count(),
            ],
            'avg_turnaround_seconds' => $this->avgSeconds($turn, 'accepted_at', 'completed_at'),
            'acceptance_rate' => $decided > 0 ? (int) round(((int) ($offers['accepted'] ?? 0)) / $decided * 100) : null,
            'rating' => ['avg' => round((float) $tp->rating_avg, 1), 'count' => (int) $tp->rating_count],
            'earnings' => $this->earnings('partner', $tp->id),
            'series' => $this->series($mine()->where('status', LabRequest::STATUS_COMPLETED), 'completed_at'),
        ];
    }

    // ------------------------------------------------------------------
    // Driver
    // ------------------------------------------------------------------

    public function driver(User $user): array
    {
        $drv = $user->driverProfile;
        if (! $drv) {
            return [];
        }
        $rides = fn () => Ride::query()->where('driver_profile_id', $drv->id);
        $today = $this->dayStart();
        $week = $this->dayStart(6);

        $earnings = $this->earnings('driver', $drv->id, ['week' => $week]);
        if ($earnings === null) {
            $fare = fn (Carbon $from) => (float) $rides()->where('status', Ride::STATUS_COMPLETED)->where('completed_at', '>=', $from)->sum(DB::raw('COALESCE(fare_final, fare_estimate, 0)'));
            $earnings = ['source' => 'fares', 'today' => round($fare($today), 2), 'week' => round($fare($week), 2), 'month' => round($fare($this->dayStartOfMonth()), 2)];
        }

        $trips = $rides()->toBase()->where('status', Ride::STATUS_COMPLETED)->where('completed_at', '>=', $today)->limit(200)->get(['accepted_at', 'started_at', 'completed_at']);
        $onTrip = 0;
        foreach ($trips as $t) {
            $from = $t->accepted_at ?? $t->started_at;
            if ($from && $t->completed_at) {
                $onTrip += max(0, Carbon::parse($t->completed_at)->getTimestamp() - Carbon::parse($from)->getTimestamp());
            }
        }

        $offers = RideOffer::query()->where('driver_profile_id', $drv->id)->where('offered_at', '>=', $this->dayStart(29))
            ->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');
        $decided = (int) ($offers['accepted'] ?? 0) + (int) ($offers['declined'] ?? 0) + (int) ($offers['expired'] ?? 0);

        $next = $rides()->whereNotIn('status', Ride::TERMINAL)->whereNotNull('scheduled_for')->where('scheduled_for', '>=', now()->subMinutes(30))
            ->orderBy('scheduled_for')->first(['id', 'ride_ref', 'pickup_address', 'scheduled_for']);

        return [
            'availability' => (string) $drv->availability,
            'trips_today' => $trips->count(),
            'trips_week' => $rides()->where('status', Ride::STATUS_COMPLETED)->where('completed_at', '>=', $week)->count(),
            'earnings' => $earnings,
            'on_trip_seconds_today' => $onTrip,
            'acceptance_rate' => $decided > 0 ? (int) round(((int) ($offers['accepted'] ?? 0)) / $decided * 100) : null,
            'offers_30d' => ['accepted' => (int) ($offers['accepted'] ?? 0), 'declined' => (int) ($offers['declined'] ?? 0), 'missed' => (int) ($offers['expired'] ?? 0)],
            'rating' => ['avg' => round((float) $drv->rating_avg, 1), 'count' => (int) $drv->rating_count],
            'cancelled_today' => $rides()->where('status', Ride::STATUS_CANCELLED)->where('cancelled_by', 'driver')->where('cancelled_at', '>=', $today)->count(),
            'next_pickup' => $next ? [
                'ride_ref' => $next->ride_ref, 'pickup' => $next->pickup_address,
                'at' => $next->scheduled_for->copy()->setTimezone(self::TZ)->format('D d M, H:i'),
                'at_iso' => $next->scheduled_for->toIso8601String(),
                'starts_in_seconds' => (int) now()->diffInSeconds($next->scheduled_for, false),
            ] : null,
            'series' => $this->series($rides()->where('status', Ride::STATUS_COMPLETED), 'completed_at'),
        ];
    }

    // ------------------------------------------------------------------
    // Patient — "My active requests" (finished items are never "active")
    // ------------------------------------------------------------------

    public function patient(User $user): array
    {
        $p = $user->patientProfile;
        if (! $p) {
            return [];
        }
        $items = [];

        $rides = Ride::query()->with('driver.user:id,name')->where('patient_profile_id', $p->id)->whereNotIn('status', Ride::TERMINAL)
            ->latest('id')->limit(10)->get()->filter(fn (Ride $r) => $r->isActive())->take(3);
        foreach ($rides as $ride) {
            $later = $ride->scheduled_for && $ride->scheduled_for->gt(now()->addMinutes(30));
            $label = match (true) {
                $ride->status === Ride::STATUS_REQUESTED && $ride->dispatch_state === Ride::DISPATCH_AWAITING_PAYMENT => 'Waiting for payment',
                $ride->status === Ride::STATUS_REQUESTED && $later => 'Scheduled',
                $ride->status === Ride::STATUS_ACCEPTED && $later => 'Scheduled · driver assigned',
                $ride->status === Ride::STATUS_REQUESTED => 'Finding a driver',
                $ride->status === Ride::STATUS_ACCEPTED, $ride->status === Ride::STATUS_DRIVER_ENROUTE => 'Driver on the way',
                $ride->status === Ride::STATUS_ARRIVED => 'Driver has arrived',
                $ride->status === Ride::STATUS_IN_PROGRESS => 'On the way to your destination',
                default => str($ride->status)->headline()->toString(),
            };
            $items[] = [
                'type' => 'ride', 'title' => 'Shuttle '.$ride->ride_ref, 'status' => $label,
                'tone' => $ride->dispatch_state === Ride::DISPATCH_AWAITING_PAYMENT ? 'warning' : 'info',
                'detail' => trim(($ride->driver?->user?->name ? $ride->driver->user->name.($ride->driver->vehicle_plate_no ? ' · '.$ride->driver->vehicle_plate_no : '') : '').' · '.$ride->dropoff_address, ' ·'),
                'eta_minutes' => ! $later && in_array($ride->status, [Ride::STATUS_ACCEPTED, Ride::STATUS_DRIVER_ENROUTE, Ride::STATUS_IN_PROGRESS], true) && $ride->eta_minutes !== null ? (int) $ride->eta_minutes : null,
                'starts_at' => $later ? $ride->scheduled_for->toIso8601String() : null,
                'action' => $ride->dispatch_state === Ride::DISPATCH_AWAITING_PAYMENT ? 'Pay now' : 'Track live',
                'url' => $this->safeRoute('rides.track', $ride),
            ];
        }

        $dm = DoctorMatchRequest::query()->where('patient_profile_id', $p->id)->where('status', DoctorMatchRequest::SEARCHING)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->latest('id')->first();
        if ($dm) {
            $items[] = [
                'type' => 'doctor_now', 'title' => 'See a doctor now', 'status' => 'Finding your doctor', 'tone' => 'info',
                'detail' => trim($dm->specialty.' · '.str_replace('_', ' ', (string) $dm->mode), ' ·'),
                'eta_minutes' => null, 'starts_at' => null, 'action' => 'View search', 'url' => $this->safeRoute('account.doctor-now'),
            ];
        }

        // Active consult: connected session, or a remote appointment whose window is open now.
        $localToday = $this->localNow()->toDateString();
        $remoteToday = Appointment::query()->with('doctor.user:id,name')->where('patient_profile_id', $p->id)->whereDate('date', $localToday)
            ->whereIn('status', [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED, Appointment::STATUS_CHECKED_IN])->orderBy('start_time')->limit(10)->get();
        $liveIds = DB::table('consult_sessions')->whereIn('appointment_id', $remoteToday->pluck('id'))->whereNotNull('connected_at')->whereNull('ended_at')->pluck('appointment_id')->all();
        foreach ($remoteToday as $a) {
            $remote = $a->isVideo() || $a->mode === Appointment::MODE_CALLBACK;
            $start = $a->videoWindowStart();
            $end = $a->videoWindowEnd();
            $inWindow = $start && $end && now()->between($start, $end);
            if (in_array($a->id, $liveIds, true) || ($remote && $inWindow)) {
                $items[] = [
                    'type' => 'consult', 'title' => 'Consult with '.($a->doctor?->user?->name ?? 'your doctor'),
                    'status' => in_array($a->id, $liveIds, true) ? 'In progress' : 'Ready to join', 'tone' => 'success',
                    'detail' => ($a->mode === Appointment::MODE_CALLBACK ? 'Call back' : 'Video').($a->start_time ? ' · '.substr((string) $a->start_time, 0, 5) : ''),
                    'eta_minutes' => null, 'starts_at' => null, 'action' => 'Open consult', 'url' => $this->consultUrl($a->id, 'patient'),
                ];
                break;
            }
        }

        $orders = Order::query()->with('pharmacy:id,name')->where('patient_profile_id', $p->id)
            ->whereNotIn('status', [Order::STATUS_DELIVERED, Order::STATUS_CANCELLED])->where('created_at', '>=', now()->subDays(30))
            ->latest('id')->limit(3)->get();
        foreach ($orders as $o) {
            $items[] = [
                'type' => 'order', 'title' => 'Order '.$o->order_no,
                'status' => $o->status === Order::STATUS_PENDING_PAYMENT ? 'Waiting for payment' : str($o->status)->replace('_', ' ')->ucfirst()->toString(),
                'tone' => $o->status === Order::STATUS_PENDING_PAYMENT ? 'warning' : 'info',
                'detail' => trim(($o->pharmacy?->name ?? '').' · R'.number_format((float) $o->total, 2), ' ·'),
                'eta_minutes' => null, 'starts_at' => null, 'action' => 'View order', 'url' => $this->safeRoute('account.orders'),
            ];
        }

        $nextAppt = Appointment::query()->with(['doctor.user:id,name', 'clinic:id,name'])->where('patient_profile_id', $p->id)
            ->whereIn('status', [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED, Appointment::STATUS_CHECKED_IN])
            ->whereDate('date', '>=', $localToday)->orderBy('date')->orderBy('start_time')->limit(5)->get()
            ->first(function (Appointment $a) {
                $start = $a->start_time ? Carbon::parse($a->date->toDateString().' '.$a->start_time, self::TZ) : $a->date->copy()->endOfDay();

                return $start->gte(now()->subMinutes(30));
            });
        $next = null;
        if ($nextAppt) {
            $start = $nextAppt->start_time ? Carbon::parse($nextAppt->date->toDateString().' '.$nextAppt->start_time, self::TZ) : null;
            $next = [
                'id' => $nextAppt->id,
                'doctor' => $nextAppt->doctor?->user?->name,
                'clinic' => $nextAppt->clinic?->name,
                'mode' => $nextAppt->mode === Appointment::MODE_CALLBACK ? 'Call back' : ($nextAppt->isVideo() ? 'Video' : 'In person'),
                'status' => (string) $nextAppt->status,
                'starts_at' => $start?->toIso8601String(),
                'starts_label' => $start ? $start->format('D d M, H:i') : $nextAppt->date->format('D d M'),
                'starts_in_seconds' => $start ? (int) now()->diffInSeconds($start, false) : null,
            ];
        }

        return [
            'active' => $items,
            'active_count' => count($items),
            'next_appointment' => $next,
            'labs_in_progress' => LabRequest::query()->where('patient_profile_id', $p->id)->whereNotIn('status', [LabRequest::STATUS_COMPLETED, LabRequest::STATUS_CANCELLED])->count(),
            'unread_messages' => DB::table('messages')->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
                ->where('conversations.patient_profile_id', $p->id)->where('messages.sender_user_id', '!=', $user->id)->whereNull('messages.read_at')->count(),
        ];
    }

    // ------------------------------------------------------------------
    // Earnings (finance ledger, when installed)
    // ------------------------------------------------------------------

    /**
     * EarningsService::summaryFor($type, $id, $from, $to) from the finance
     * module, if present. Returns null when it is missing or fails, so callers
     * fall back to their own revenue maths.
     *
     * @param  array<string, Carbon>  $extra  extra named periods
     * @return array<string, mixed>|null
     */
    public function earnings(string $type, int $id, array $extra = []): ?array
    {
        $class = 'App\\Services\\Finance\\EarningsService';
        if (! class_exists($class) || ! method_exists($class, 'summaryFor')) {
            return null;
        }
        try {
            $svc = app($class);
            $to = now();
            $periods = ['today' => $this->dayStart(), 'month' => $this->dayStartOfMonth()] + $extra;
            $out = ['source' => 'ledger'];
            $seen = false;
            foreach ($periods as $k => $from) {
                $s = (array) $svc->summaryFor($type, $id, $from, $to);
                $out[$k] = round((float) ($s['net'] ?? $s['gross'] ?? 0), 2);
                $seen = $seen || (float) ($s['gross'] ?? 0) != 0.0 || (int) ($s['counts']['items'] ?? 0) > 0
                    || (float) ($s['balance'] ?? 0) != 0.0 || (float) ($s['paid_out_total'] ?? 0) != 0.0;
                if ($k === 'month') {
                    $out['month_detail'] = array_filter($s, fn ($v) => is_scalar($v) || $v === null);
                }
            }

            // A ledger with nothing posted for this account yet (e.g. before the
            // finance backfill) would show R 0 — fall back to collected payments.
            return $seen ? $out : null;
        } catch (Throwable $e) {
            Log::warning('[Dashboard] earnings unavailable', ['type' => $type, 'error' => $e->getMessage()]);

            return null;
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    public function localNow(): Carbon
    {
        return now()->setTimezone(self::TZ);
    }

    /** UTC instant of the start of the local day $daysAgo days back. */
    public function dayStart(int $daysAgo = 0): Carbon
    {
        return $this->localNow()->startOfDay()->subDays($daysAgo)->utc();
    }

    public function dayStartOfMonth(): Carbon
    {
        return $this->localNow()->startOfMonth()->utc();
    }

    /** SQL expression: local (UTC+2) calendar date of a UTC timestamp column. */
    protected function localDateExpr(string $col): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "DATE($col, '+2 hours')",
            'pgsql' => "DATE($col + INTERVAL '2 hours')",
            'sqlsrv' => "CAST(DATEADD(hour, 2, $col) AS date)",
            default => "DATE(DATE_ADD($col, INTERVAL 2 HOUR))",
        };
    }

    /**
     * Daily counts (or sums) for the last $days local days, oldest first.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $q
     * @return array<int, float>
     */
    public function series($q, string $col, int $days = 7, ?string $sumCol = null): array
    {
        $expr = $this->localDateExpr($col);
        $agg = $sumCol ? "SUM($sumCol)" : 'COUNT(*)';
        $rows = (clone $q)->where($col, '>=', $this->dayStart($days - 1))
            ->selectRaw("$expr as d, $agg as c")->groupBy(DB::raw($expr))->pluck('c', 'd');
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $out[] = (float) ($rows[$this->localNow()->subDays($i)->toDateString()] ?? 0);
        }

        return $out;
    }

    /** Same as series() for a column that already holds a local date (appointments.date). */
    public function seriesDate($q, string $col, int $days = 7): array
    {
        $from = $this->localNow()->subDays($days - 1)->toDateString();
        $rows = (clone $q)->whereDate($col, '>=', $from)->whereDate($col, '<=', $this->localNow()->toDateString())
            ->selectRaw("DATE($col) as d, COUNT(*) as c")->groupBy(DB::raw("DATE($col)"))->pluck('c', 'd');
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $out[] = (float) ($rows[$this->localNow()->subDays($i)->toDateString()] ?? 0);
        }

        return $out;
    }

    /** ['today' => n, 'yesterday' => n, 'delta_pct' => int|null, 'series' => [7]] */
    protected function todayVsYesterday($q, string $col): array
    {
        $today = (clone $q)->where($col, '>=', $this->dayStart())->count();
        $yesterday = (clone $q)->where($col, '>=', $this->dayStart(1))->where($col, '<', $this->dayStart())->count();

        return [
            'today' => $today,
            'yesterday' => $yesterday,
            'delta_pct' => $yesterday > 0 ? (int) round(($today - $yesterday) / $yesterday * 100) : null,
            'series' => $this->series(clone $q, $col),
        ];
    }

    /** @return array<int, int> seconds between two timestamp columns per row (negative/invalid skipped) */
    protected function durations(iterable $rows, string $from, string $to): array
    {
        $out = [];
        foreach ($rows as $r) {
            $a = is_array($r) ? ($r[$from] ?? null) : ($r->{$from} ?? null);
            $b = is_array($r) ? ($r[$to] ?? null) : ($r->{$to} ?? null);
            if (! $a || ! $b) {
                continue;
            }
            $d = Carbon::parse($b)->getTimestamp() - Carbon::parse($a)->getTimestamp();
            if ($d >= 0) {
                $out[] = $d;
            }
        }

        return $out;
    }

    protected function medianSeconds(iterable $rows, string $from, string $to): ?int
    {
        $d = $this->durations($rows, $from, $to);
        if (! $d) {
            return null;
        }
        sort($d);
        $n = count($d);
        $mid = intdiv($n, 2);

        return (int) round($n % 2 ? $d[$mid] : ($d[$mid - 1] + $d[$mid]) / 2);
    }

    protected function avgSeconds(iterable $rows, string $from, string $to): ?int
    {
        $d = $this->durations($rows, $from, $to);

        return $d ? (int) round(array_sum($d) / count($d)) : null;
    }

    protected function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    protected function consultUrl(int $appointmentId, string $who): ?string
    {
        try {
            return $who === 'doctor'
                ? \App\Filament\Pages\ConsultHub::getUrl(['appointment' => $appointmentId], panel: 'admin')
                : route('account.consult-hub', $appointmentId);
        } catch (Throwable) {
            return null;
        }
    }

    protected function safeRoute(string $name, mixed $params = []): ?string
    {
        try {
            return route($name, $params);
        } catch (Throwable) {
            return null;
        }
    }

    // ------------------------------------------------------------------
    // Presentation helpers (used by the Blade views)
    // ------------------------------------------------------------------

    /** "4 min", "1 h 12 min", "45 s", or "—". */
    public static function duration(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }
        $seconds = abs($seconds);
        if ($seconds < 60) {
            return $seconds.' s';
        }
        $m = intdiv($seconds, 60);
        if ($m < 60) {
            return $m.' min';
        }
        $h = intdiv($m, 60);
        if ($h < 48) {
            return $h.' h'.($m % 60 ? ' '.($m % 60).' min' : '');
        }

        return intdiv($h, 24).' days';
    }

    public static function money(float|int|null $v): string
    {
        // Non-breaking spaces so a big amount never wraps mid-number.
        return "R\u{00A0}".number_format((float) $v, abs((float) $v) >= 1000 ? 0 : 2, '.', "\u{00A0}");
    }

    /** SVG polyline points for a sparkline in a 100x28 box. */
    public static function sparkPoints(array $values, int $w = 100, int $h = 28): string
    {
        $values = array_values(array_map('floatval', $values));
        $n = count($values);
        if ($n < 2) {
            return '';
        }
        $max = max($values);
        $min = min($values);
        $span = $max - $min ?: 1;
        $pts = [];
        foreach ($values as $i => $v) {
            $x = round($i / ($n - 1) * $w, 1);
            $y = round($h - 2 - (($v - $min) / $span) * ($h - 4), 1);
            $pts[] = $x.','.$y;
        }

        return implode(' ', $pts);
    }
}
