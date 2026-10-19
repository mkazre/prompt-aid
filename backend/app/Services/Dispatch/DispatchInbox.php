<?php

namespace App\Services\Dispatch;

use App\Models\DispatchJob;
use App\Models\DispatchOffer;
use App\Models\DoctorMatchOffer;
use App\Models\DoctorMatchRequest;
use App\Models\Ride;
use App\Models\RideOffer;
use App\Models\User;
use App\Services\Doctors\DoctorMatchException;
use App\Services\Doctors\DoctorMatchService;
use App\Services\Rides\RideActionException;
use App\Services\Rides\RideDispatchService;
use App\Support\DispatchSettings;
use App\Support\ShuttleSettings;
use Illuminate\Support\Facades\Cache;

/**
 * One provider-facing inbox across every service: live offers (to show as
 * the full-screen alert with Accept / Decline) plus offers that closed in
 * the last 90 s (so a popup can flip to "Accepted by another provider").
 *
 * Offer keys: "ride:{ride_offer_id}", "dm:{doctor_match_offer_id}",
 * "job:{dispatch_offer_id}". Every query is scoped to the signed-in user.
 */
class DispatchInbox
{
    public const SERVICE_LABELS = [
        'shuttle' => 'Shuttle trip',
        'doctor_now' => 'See a doctor now',
        'doctor_later' => 'Doctor request',
        'appointment' => 'Appointment',
        'pharmacy_order' => 'Medicine order',
        'script_review' => 'Script review',
        'lab_request' => 'Lab request',
    ];

    public function __construct(
        protected DispatchService $engine,
        protected RideDispatchService $rides,
        protected DoctorMatchService $doctors,
        protected AvailabilityService $availability,
    ) {}

    /** Lazy progression (no cron needed): at most every 5 s platform-wide. */
    public function lazyTick(): void
    {
        if (! Cache::add('dispatch:lazy', 1, 5)) {
            return;
        }
        try {
            $this->engine->tick(true);
            $this->rides->tickAll();
            $this->doctors->tick();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[DispatchInbox] lazy tick: '.$e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    public function forUser(User $user, ?float $lat = null, ?float $lng = null): array
    {
        $this->availability->heartbeat($user, $lat, $lng);
        $this->lazyTick();

        $offers = [];
        $recent = [];

        if ($user->role === User::ROLE_DRIVER && ($driver = $user->driverProfile)) {
            RideOffer::query()->with('ride.patient.user')->where('driver_profile_id', $driver->id)
                ->where(fn ($q) => $q->where('status', RideOffer::PENDING)->orWhere('responded_at', '>=', now()->subSeconds(90)))
                ->where('offered_at', '>=', now()->subMinutes(30))
                ->latest('id')->limit(15)->get()
                ->each(function (RideOffer $o) use (&$offers, &$recent, $driver) {
                    if (! $o->ride) {
                        return;
                    }
                    $card = $this->rideCard($o, $driver->id);
                    if ($card['state'] === 'pending') {
                        $offers[] = $card;
                    } elseif (in_array($card['state'], ['taken', 'expired', 'cancelled'], true)) {
                        $recent[] = $card;
                    }
                });
        }

        if ($user->role === User::ROLE_DOCTOR && ($doctor = $user->doctorProfile)) {
            DoctorMatchOffer::query()->with(['matchRequest.patient.user', 'matchRequest.triageSubmission', 'matchRequest.appointment'])->where('doctor_profile_id', $doctor->id)
                ->where(fn ($q) => $q->where('status', DoctorMatchOffer::OFFERED)->orWhere('responded_at', '>=', now()->subSeconds(90)))
                ->where('offered_at', '>=', now()->subMinutes(30))
                ->latest('id')->limit(15)->get()
                ->each(function (DoctorMatchOffer $o) use (&$offers, &$recent, $doctor) {
                    $card = $this->doctorNowCard($o, $doctor->id);
                    if ($card['state'] === 'pending') {
                        $offers[] = $card;
                    } elseif (in_array($card['state'], ['taken', 'expired', 'cancelled'], true)) {
                        $recent[] = $card;
                    }
                });
        }

        DispatchOffer::query()->with('job')->where('provider_user_id', $user->id)
            ->where(fn ($q) => $q->where('status', DispatchOffer::PENDING)->orWhere('responded_at', '>=', now()->subSeconds(90)))
            ->where('offered_at', '>=', now()->subDays(1))
            ->latest('id')->limit(20)->get()
            ->each(function (DispatchOffer $o) use (&$offers, &$recent) {
                if (! $o->job) {
                    return;
                }
                $card = $this->jobCard($o);
                if ($card['state'] === 'pending') {
                    $offers[] = $card;
                } elseif (in_array($card['state'], ['taken', 'expired', 'cancelled'], true)) {
                    $recent[] = $card;
                }
            });

        // Soonest-expiring first (the client shows one at a time).
        usort($offers, fn ($a, $b) => $a['seconds_left'] <=> $b['seconds_left']);

        return [
            'version' => ProviderAlerts::version($user->id),
            'server_time' => now()->toIso8601String(),
            'offers' => $offers,
            'recent' => $recent,
            'availability' => $this->availability->payload($user),
            'sound' => DispatchSettings::bool('dispatch_sound_enabled'),
            'poll_ms' => $offers ? 3000 : 5000,
        ];
    }

    /** @return array<string, mixed> */
    public function rideCard(RideOffer $o, int $driverId): array
    {
        $r = $o->ride;
        $state = 'pending';
        if ($o->status !== RideOffer::PENDING || ! $o->expires_at || $o->expires_at->isPast() || $r->status !== Ride::STATUS_REQUESTED || ! $r->isActive()) {
            $state = match (true) {
                $o->status === RideOffer::ACCEPTED => 'accepted',
                $o->status === RideOffer::TAKEN, $r->driver_profile_id && (int) $r->driver_profile_id !== $driverId => 'taken',
                $o->status === RideOffer::DECLINED => 'declined',
                $o->status === RideOffer::CANCELLED || $r->status === Ride::STATUS_CANCELLED => 'cancelled',
                default => 'expired',
            };
        }
        $fare = (float) ($r->quoted_fare ?? $r->fare_estimate ?? 0);
        $badges = array_values(array_filter([
            $r->scheduled_for && $r->scheduled_for->gt(now()->addMinutes(10)) ? 'Scheduled '.$r->scheduled_for->copy()->setTimezone('Africa/Johannesburg')->format('D H:i') : 'On-demand',
            ucwords(str_replace('_', ' ', (string) $r->vehicle_type)),
            $r->is_return || $r->return_of_ride_id ? 'Return leg' : null,
            $r->ride_series_id ? 'Recurring' : null,
            $r->wait_and_return ? 'Wait & return' : null,
            (float) ($r->surge_multiplier ?: 1) > 1 ? 'Peak x'.rtrim(rtrim(number_format((float) $r->surge_multiplier, 2), '0'), '.') : null,
            $r->priority === 'emergency' ? 'Emergency priority' : null,
        ]));
        $timeout = max(5, ShuttleSettings::int('shuttle_offer_timeout_seconds'));

        return [
            'key' => 'ride:'.$o->id,
            'service' => 'shuttle',
            'service_label' => self::SERVICE_LABELS['shuttle'],
            'icon' => 'car',
            'state' => $state,
            'request_id' => $r->id,
            'offer_id' => $o->id,
            'title' => 'New trip request',
            'subtitle' => $r->pickup_address.' → '.$r->dropoff_address,
            'patient_first_name' => RiderProfile::forRide($r, false)['first_name'],
            'rider' => RiderProfile::forRide($r, false),
            'badges' => $badges,
            'lines' => array_values(array_filter([$r->pickup_notes ? 'Pickup note: '.$r->pickup_notes : null])),
            'earning' => $fare, // drivers are paid the trip fare (no platform split configured)
            'fare' => $fare,
            'distance_km' => $o->distance_km !== null ? (float) $o->distance_km : null,
            'eta_min' => $o->eta_minutes,
            'trip_km' => (float) ($r->route_distance_km ?? $r->distance_km ?? 0),
            'trip_min' => $r->route_duration_min ?? $r->eta_minutes,
            'pickup' => ['address' => $r->pickup_address, 'lat' => (float) $r->pickup_lat, 'lng' => (float) $r->pickup_lng],
            'dropoff' => ['address' => $r->dropoff_address, 'lat' => (float) $r->dropoff_lat, 'lng' => (float) $r->dropoff_lng],
            'when' => $r->scheduled_for?->toIso8601String(),
            'seconds_left' => $state === 'pending' ? $o->secondsLeft() : 0,
            'timeout_s' => $timeout,
            'expires_at' => $o->expires_at?->toIso8601String(),
            'swipe' => true,
            'url' => '/driver',
        ];
    }

    /** @return array<string, mixed> */
    public function doctorNowCard(DoctorMatchOffer $o, int $doctorId): array
    {
        $req = $o->matchRequest;
        $state = 'pending';
        if ($o->status !== DoctorMatchOffer::OFFERED || ! $o->expires_at || $o->expires_at->lt(now()->subSeconds(1)) || ! $req || $req->status !== DoctorMatchRequest::SEARCHING) {
            $state = match (true) {
                $o->status === DoctorMatchOffer::ACCEPTED => 'accepted',
                $req && $req->status === DoctorMatchRequest::MATCHED && (int) $req->doctor_profile_id !== $doctorId => 'taken',
                $o->status === DoctorMatchOffer::DECLINED => 'declined',
                $req && $req->status === DoctorMatchRequest::CANCELLED => 'cancelled',
                default => 'expired',
            };
        }
        $name = (string) ($req?->patient?->user?->name ?? 'Patient');

        return [
            'key' => 'dm:'.$o->id,
            'service' => 'doctor_now',
            'service_label' => self::SERVICE_LABELS['doctor_now'],
            'icon' => 'stethoscope',
            'state' => $state,
            'request_id' => $o->doctor_match_request_id,
            'offer_id' => $o->id,
            'title' => match ($req?->mode) {
                'callback' => 'Patient asks a doctor to CALL BACK now',
                'video' => 'Patient needs a VIDEO consult now',
                default => 'Patient wants to see a doctor NOW',
            },
            'subtitle' => DoctorMatchService::modeLabel($req?->mode).' · '.($req?->specialty ?? ''),
            'patient_first_name' => trim(strtok($name, ' ') ?: $name),
            'rider' => null,
            'badges' => array_values(array_filter([
                $req?->triageSubmission?->level ? strtoupper($req->triageSubmission->level) : null,
                'Now',
                match ($req?->mode) { 'video' => 'Video', 'callback' => 'Call back', default => 'In person' },
            ])),
            'mode' => $req?->mode,
            'appointment_id' => $o->appointment_id,
            'lines' => $req && $state === 'pending' ? $this->doctors->offerLines($req) : [],
            'earning' => (float) ($req?->price ?? 0),
            'fare' => (float) ($req?->price ?? 0),
            'distance_km' => $o->distance_km !== null ? (float) $o->distance_km : null,
            'eta_min' => null,
            'pickup' => null,
            'dropoff' => null,
            'when' => null,
            'seconds_left' => $state === 'pending' ? $o->secondsLeft() : 0,
            'timeout_s' => max(10, \App\Support\DoctorNowSettings::int('doctor_now_offer_timeout_seconds')),
            'expires_at' => $o->expires_at?->toIso8601String(),
            'swipe' => false,
            'url' => '/staff/live-consults',
        ];
    }

    /** @return array<string, mixed> */
    public function jobCard(DispatchOffer $o): array
    {
        $job = $o->job;
        $state = 'pending';
        if ($o->status !== DispatchOffer::PENDING || ! $o->expires_at || $o->expires_at->isPast() || ! $job->isOpen()) {
            $state = match (true) {
                $o->status === DispatchOffer::ACCEPTED => 'accepted',
                $o->status === DispatchOffer::TAKEN, $job->accepted_by_user_id && (int) $job->accepted_by_user_id !== (int) $o->provider_user_id => 'taken',
                $o->status === DispatchOffer::DECLINED => 'declined',
                $o->status === DispatchOffer::CANCELLED || $job->status === DispatchJob::CANCELLED => 'cancelled',
                default => 'expired',
            };
        }
        try {
            $c = $this->engine->handler($job->service)->card($job);
        } catch (\Throwable) {
            $c = ['icon' => 'bell', 'title' => 'Request', 'subtitle' => '', 'patient_first_name' => null, 'lines' => [], 'earning' => null, 'when' => null, 'url' => null];
        }

        return [
            'key' => 'job:'.$o->id,
            'service' => $job->service,
            'service_label' => self::SERVICE_LABELS[$job->service] ?? $job->service,
            'icon' => $c['icon'],
            'state' => $state,
            'request_id' => $job->id,
            'offer_id' => $o->id,
            'title' => $c['title'],
            'subtitle' => $c['subtitle'],
            'patient_first_name' => $c['patient_first_name'],
            'rider' => null,
            'badges' => array_values(array_filter([$job->window_from ? 'Scheduled' : null])),
            'lines' => $c['lines'],
            'earning' => $c['earning'],
            'fare' => $c['earning'],
            'distance_km' => $o->distance_km !== null ? (float) $o->distance_km : null,
            'eta_min' => null,
            'pickup' => null,
            'dropoff' => null,
            'when' => $c['when'],
            'seconds_left' => $state === 'pending' ? $o->secondsLeft() : 0,
            'timeout_s' => max(1, (int) ($o->offered_at && $o->expires_at ? $o->offered_at->diffInSeconds($o->expires_at, true) : DispatchSettings::offerTimeout())),
            'expires_at' => $o->expires_at?->toIso8601String(),
            'swipe' => false,
            'url' => $c['url'],
        ];
    }

    /**
     * Accept by key. Returns ['message', 'next' => [...]] for the client.
     *
     * @return array<string, mixed>
     */
    public function accept(User $user, string $key): array
    {
        [$kind, $id] = $this->parseKey($key);
        try {
            if ($kind === 'ride') {
                $driver = $user->driverProfile;
                $offer = RideOffer::query()->where('driver_profile_id', $driver?->id)->findOrFail($id);
                $ride = $this->rides->acceptOffer($offer, $driver);

                return ['message' => 'Trip accepted. Head to the pickup point.', 'next' => ['type' => 'ride', 'ride_id' => $ride->id, 'url' => '/driver']];
            }
            if ($kind === 'dm') {
                $doctor = $user->doctorProfile;
                $offer = DoctorMatchOffer::query()->where('doctor_profile_id', $doctor?->id)->findOrFail($id);
                $req = $this->doctors->accept($offer, $doctor);

                // Video → straight into the in-panel call (the patient's screen is ringing);
                // call back → the call card with Call / WhatsApp buttons.
                $url = match ($req->mode) {
                    'video' => \App\Filament\Pages\ConsultCall::getUrl(['appointment' => $req->appointment_id], panel: 'admin'),
                    'callback' => \App\Filament\Pages\CallbackConsult::getUrl(['appointment' => $req->appointment_id], panel: 'admin'),
                    default => '/staff/live-consults',
                };
                $msg = match ($req->mode) {
                    'video' => 'Consult accepted — opening the video call. The patient is joining.',
                    'callback' => 'Consult accepted — call the patient now.',
                    default => 'Consult accepted. The patient has been notified.',
                };

                return ['message' => $msg, 'next' => ['type' => 'doctor_now', 'request_id' => $req->id, 'appointment_id' => $req->appointment_id, 'mode' => $req->mode, 'patient_name' => $req->appointment?->patient?->user?->name, 'url' => $url]];
            }
            $offer = DispatchOffer::query()->where('provider_user_id', $user->id)->findOrFail($id);
            $job = $this->engine->accept($offer, $user);
            $card = $this->engine->handler($job->service)->card($job);

            return ['message' => 'Accepted. The patient has been notified.', 'next' => ['type' => $job->service, 'request_id' => $job->id, 'subject_id' => $job->subject_id, 'url' => $card['url']]];
        } catch (RideActionException $e) {
            throw new DispatchException($e->getMessage(), $e->status);
        } catch (DoctorMatchException $e) {
            throw new DispatchException($e->getMessage(), $e->status ?? 409);
        }
    }

    public function decline(User $user, string $key, ?string $reason = null): void
    {
        [$kind, $id] = $this->parseKey($key);
        try {
            if ($kind === 'ride') {
                $driver = $user->driverProfile;
                $offer = RideOffer::query()->where('driver_profile_id', $driver?->id)->findOrFail($id);
                $this->rides->declineOffer($offer, $driver);

                return;
            }
            if ($kind === 'dm') {
                $doctor = $user->doctorProfile;
                $offer = DoctorMatchOffer::query()->where('doctor_profile_id', $doctor?->id)->findOrFail($id);
                if ($offer->status === DoctorMatchOffer::OFFERED) {
                    $this->doctors->decline($offer, $doctor);
                }

                return;
            }
            $offer = DispatchOffer::query()->where('provider_user_id', $user->id)->findOrFail($id);
            $this->engine->decline($offer, $user, $reason);
        } catch (RideActionException $e) {
            throw new DispatchException($e->getMessage(), $e->status);
        } catch (DoctorMatchException $e) {
            throw new DispatchException($e->getMessage(), $e->status ?? 409);
        } finally {
            ProviderAlerts::bump($user->id);
        }
    }

    /** @return array{0: string, 1: int} */
    protected function parseKey(string $key): array
    {
        if (! preg_match('/^(ride|dm|job):(\d+)$/', $key, $m)) {
            throw new DispatchException('Unknown request.', 404);
        }

        return [$m[1], (int) $m[2]];
    }
}
