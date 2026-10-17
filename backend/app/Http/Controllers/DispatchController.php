<?php

namespace App\Http\Controllers;

use App\Http\Resources\RideResource;
use App\Models\Appointment;
use App\Models\DispatchJob;
use App\Models\DispatchOffer;
use App\Models\Ride;
use App\Services\Dispatch\AvailabilityService;
use App\Services\Dispatch\DispatchException;
use App\Services\Dispatch\DispatchInbox;
use App\Services\Dispatch\DispatchService;
use App\Services\Dispatch\Handlers\DoctorLaterHandler;
use App\Services\Rides\RideActionException;
use App\Services\Rides\RideDispatchService;
use App\Support\DispatchSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Unified dispatch endpoints, shared by the website/panels (session auth,
 * routes/web_dispatch.php) and the apps (Sanctum, routes/api_dispatch.php).
 * Every provider endpoint is scoped to the signed-in provider; every
 * patient endpoint to the signed-in patient.
 */
class DispatchController extends Controller
{
    public function __construct(
        protected DispatchInbox $inbox,
        protected AvailabilityService $availability,
        protected DispatchService $engine,
    ) {}

    // ---------------------------------------------------------------- providers

    public function inbox(Request $request)
    {
        $user = $request->user();
        abort_unless(AvailabilityService::isProvider($user), 403);
        $lat = is_numeric($request->query('lat')) ? (float) $request->query('lat') : null;
        $lng = is_numeric($request->query('lng')) ? (float) $request->query('lng') : null;

        return response()->json($this->inbox->forUser($user, $lat, $lng));
    }

    public function accept(Request $request, string $key)
    {
        abort_unless(AvailabilityService::isProvider($request->user()), 403);
        try {
            return response()->json($this->inbox->accept($request->user(), $key));
        } catch (DispatchException $e) {
            return response()->json(['message' => $e->getMessage(), 'taken' => $e->status === 409 && str_contains(strtolower($e->getMessage()), 'another')], $e->status);
        }
    }

    public function decline(Request $request, string $key)
    {
        abort_unless(AvailabilityService::isProvider($request->user()), 403);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        try {
            $this->inbox->decline($request->user(), $key, $data['reason'] ?? null);
        } catch (DispatchException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json(['message' => 'Declined.']);
    }

    public function availability(Request $request)
    {
        abort_unless(AvailabilityService::isProvider($request->user()), 403);

        return response()->json($this->availability->payload($request->user()));
    }

    /**
     * Body (all optional): now (bool), later {from,to}|null, tomorrow {from,to}|null,
     * weekly [{weekday,start,end}], max_radius_km (number|null).
     */
    public function updateAvailability(Request $request)
    {
        $user = $request->user();
        abort_unless(AvailabilityService::isProvider($user), 403);
        $data = $request->validate([
            'now' => ['sometimes', 'boolean'],
            'later' => ['sometimes', 'nullable', 'array'],
            'later.from' => ['nullable', 'date_format:H:i'],
            'later.to' => ['nullable', 'date_format:H:i'],
            'tomorrow' => ['sometimes', 'nullable', 'array'],
            'tomorrow.from' => ['nullable', 'date_format:H:i'],
            'tomorrow.to' => ['nullable', 'date_format:H:i'],
            'weekly' => ['sometimes', 'nullable', 'array', 'max:21'],
            'max_radius_km' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:500'],
        ]);
        try {
            if (array_key_exists('now', $data)) {
                $this->availability->setNow($user, (bool) $data['now']);
            }
            foreach (['later', 'tomorrow'] as $kind) {
                if (array_key_exists($kind, $data)) {
                    $this->availability->setWindow($user, $kind, $data[$kind]['from'] ?? null, $data[$kind]['to'] ?? null);
                }
            }
            if (array_key_exists('weekly', $data)) {
                $this->availability->setWeekly($user, (array) $data['weekly']);
            }
            if (array_key_exists('max_radius_km', $data)) {
                $this->availability->setRadius($user, $data['max_radius_km'] !== null ? (float) $data['max_radius_km'] : null);
            }
        } catch (DispatchException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json($this->availability->payload($user));
    }

    // ---------------------------------------------------------------- patients

    /** The single truly-active ride (not terminal, not stale) or null. */
    public function activeRide(Request $request)
    {
        $patient = $request->user()->patientProfile;
        abort_unless($patient, 403);
        $ride = $patient->rides()->whereNotIn('status', Ride::TERMINAL)->with(['driver.user'])
            ->orderByRaw('CASE WHEN status = ? THEN 1 ELSE 0 END', [Ride::STATUS_REQUESTED])
            ->latest('requested_at')->limit(20)->get()
            // On-demand / imminent first; a far-future scheduled ride is not "active".
            ->first(fn (Ride $r) => $r->isActive() && (! $r->scheduled_for || $r->scheduled_for->lte(now()->addMinutes(60)) || $r->driver_profile_id));

        return response()->json(['data' => $ride ? new RideResource($ride) : null]);
    }

    public function retryRide(Request $request, Ride $ride, RideDispatchService $dispatch)
    {
        abort_unless($request->user()->patientProfile && (int) $ride->patient_profile_id === (int) $request->user()->patientProfile->id, 403);
        $data = $request->validate(['radius_km' => ['nullable', 'numeric', 'min:1', 'max:500']]);
        try {
            $ride = $dispatch->retryDispatch($ride, isset($data['radius_km']) ? (float) $data['radius_km'] : null);
        } catch (RideActionException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json(['message' => 'Searching for a driver again.', 'data' => new RideResource($ride)]);
    }

    public function radius(Request $request)
    {
        $patient = $request->user()->patientProfile;

        return response()->json([
            'radius_km' => $patient?->search_radius_km !== null ? (float) $patient->search_radius_km : null,
            'default_radius_km' => DispatchSettings::defaultRadius(),
            'max_radius_km' => DispatchSettings::maxRadius(),
            'options' => DispatchSettings::radiusOptions(),
        ]);
    }

    public function updateRadius(Request $request)
    {
        $patient = $request->user()->patientProfile;
        abort_unless($patient, 403);
        $data = $request->validate(['radius_km' => ['nullable', 'numeric', 'min:1', 'max:500']]);
        $patient->forceFill(['search_radius_km' => isset($data['radius_km']) ? DispatchSettings::clampRadius($data['radius_km']) : null])->save();

        return $request->expectsJson() ? $this->radius($request) : back()->with('success', 'Search radius saved.');
    }

    /** "Any doctor later today / tomorrow" — broadcast to qualifying doctors. */
    public function requestDoctorLater(Request $request)
    {
        $patient = $request->user()->patientProfile;
        abort_unless($patient, 403);
        $data = $request->validate([
            'specialty' => ['required', 'string', 'max:255'],
            'mode' => ['required', 'in:video,in_person'],
            'day' => ['required', 'in:today,tomorrow'],
            'from' => ['required', 'date_format:H:i'],
            'to' => ['required', 'date_format:H:i', 'after:from'],
            'lat' => ['required_if:mode,in_person', 'nullable', 'numeric', 'between:-90,90'],
            'lng' => ['required_if:mode,in_person', 'nullable', 'numeric', 'between:-180,180'],
            'radius_km' => ['nullable', 'numeric', 'min:1', 'max:500'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $day = now()->setTimezone(Appointment::CLINIC_TIMEZONE)->startOfDay();
        if ($data['day'] === 'tomorrow') {
            $day->addDay();
        }
        $from = Carbon::parse($day->toDateString().' '.$data['from'], Appointment::CLINIC_TIMEZONE)->utc();
        $to = Carbon::parse($day->toDateString().' '.$data['to'], Appointment::CLINIC_TIMEZONE)->utc();
        if ($from->lt(now())) {
            $from = now()->addMinutes(15)->second(0);
        }

        try {
            $job = DoctorLaterHandler::request(
                $patient, $data['specialty'], $data['mode'], $from, $to,
                isset($data['lat']) ? (float) $data['lat'] : null, isset($data['lng']) ? (float) $data['lng'] : null,
                isset($data['radius_km']) ? (float) $data['radius_km'] : ($patient->search_radius_km !== null ? (float) $patient->search_radius_km : null),
                $data['reason'] ?? null,
            );
        } catch (DispatchException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], $e->status);
            }

            return back()->withInput()->with('error', $e->getMessage());
        }

        return $request->expectsJson()
            ? response()->json(['data' => $this->jobPayload($job)], 201)
            : redirect()->route('dispatch.request.show', $job)->with('success', 'Request sent to doctors.');
    }

    public function showJob(Request $request, DispatchJob $job)
    {
        $this->authorizePatientJob($request, $job);
        if ($job->status === DispatchJob::SEARCHING) {
            $this->engine->pump($job);
            $job->refresh();
        }

        if (! $request->expectsJson() && ! $request->is('api/*')) {
            return view('dispatch.request-status', ['job' => $job, 'payload' => $this->jobPayload($job)]);
        }

        return response()->json(['data' => $this->jobPayload($job)]);
    }

    public function cancelJob(Request $request, DispatchJob $job)
    {
        $this->authorizePatientJob($request, $job);
        if ($job->isOpen()) {
            $subject = $job->subject;
            if ($subject instanceof Appointment && in_array($subject->status, [Appointment::STATUS_MATCHING, Appointment::STATUS_PENDING], true)) {
                $subject->update(['status' => Appointment::STATUS_CANCELLED, 'cancel_reason' => 'Cancelled by the patient']);
            }
            $this->engine->close($job, DispatchJob::CANCELLED, 'cancelled_by_patient');
        }

        return response()->json(['data' => $this->jobPayload($job->fresh())]);
    }

    public function retryJob(Request $request, DispatchJob $job)
    {
        $this->authorizePatientJob($request, $job);
        $data = $request->validate(['radius_km' => ['nullable', 'numeric', 'min:1', 'max:500']]);
        $subject = $job->subject;
        try {
            if ($subject instanceof Appointment && $job->service === 'doctor_later') {
                if ($subject->status === Appointment::STATUS_CANCELLED) {
                    $subject->update(['status' => Appointment::STATUS_MATCHING, 'cancel_reason' => null]);
                }
                $from = $job->window_from && $job->window_from->isFuture() ? $job->window_from : now()->addMinutes(15);
                $job->update(['window_from' => $from]);
            }
            $job = $this->engine->retry($job, isset($data['radius_km']) ? (float) $data['radius_km'] : null);
        } catch (DispatchException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json(['data' => $this->jobPayload($job)]);
    }

    public function config()
    {
        return response()->json([
            'default_radius_km' => DispatchSettings::defaultRadius(),
            'max_radius_km' => DispatchSettings::maxRadius(),
            'radius_options' => DispatchSettings::radiusOptions(),
            'modes' => collect(array_keys(DispatchSettings::SERVICES))->mapWithKeys(fn ($s) => [$s => DispatchSettings::mode($s)]),
        ]);
    }

    /** @return array<string, mixed> */
    protected function jobPayload(DispatchJob $job): array
    {
        $pending = DispatchOffer::query()->where('dispatch_job_id', $job->id)->where('status', DispatchOffer::PENDING)->where('expires_at', '>', now())->count();
        $provider = $job->acceptedBy;
        $label = match ($job->status) {
            DispatchJob::SEARCHING => $pending > 0
                ? "Searching {$pending} provider".($pending === 1 ? '' : 's').($job->radius_km ? ' within '.rtrim(rtrim(number_format((float) $job->radius_km, 1), '0'), '.').' km' : '').'…'
                : 'Waiting for an available provider…',
            DispatchJob::MANUAL => 'Our team is assigning a provider…',
            DispatchJob::ACCEPTED => 'Accepted by '.($job->service === 'doctor_later' || $job->service === 'appointment' ? 'Dr. ' : '').($provider?->name ?? 'a provider'),
            DispatchJob::EXPIRED => 'No provider accepted in time',
            default => 'Cancelled',
        };

        return [
            'id' => $job->id,
            'service' => $job->service,
            'status' => $job->status,
            'status_label' => $label,
            'is_active' => $job->isOpen(),
            'providers_notified' => $pending,
            'providers_offered' => (int) $job->offers_count,
            'radius_km' => $job->radius_km !== null ? (float) $job->radius_km : null,
            'radius_options' => DispatchSettings::radiusOptions(),
            'capability' => $job->capability,
            'window_from' => $job->window_from?->toIso8601String(),
            'window_to' => $job->window_to?->toIso8601String(),
            'expires_at' => $job->expires_at?->toIso8601String(),
            'accepted_by' => $provider ? ['name' => $provider->name] : null,
            'subject_id' => $job->subject_id,
            'can_retry' => $job->status === DispatchJob::EXPIRED,
            'can_cancel' => $job->isOpen(),
            'poll_ms' => $job->isOpen() ? 4000 : 0,
        ];
    }

    protected function authorizePatientJob(Request $request, DispatchJob $job): void
    {
        abort_unless((int) $job->patient_user_id === (int) $request->user()->id, 403);
    }
}
