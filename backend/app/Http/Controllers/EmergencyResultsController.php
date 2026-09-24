<?php

namespace App\Http\Controllers;

use App\Contracts\GeocodingInterface;
use App\Contracts\NotificationDispatcherInterface;
use App\Models\Clinic;
use App\Models\DoctorProfile;
use App\Models\Pharmacy;
use App\Models\TriageSubmission;
use App\Services\Rides\RideDispatchService;
use Illuminate\Http\Request;

/**
 * Real "nearest help" results for the pre-triage tool — ranks our own
 * registered clinics, doctors and pharmacies by straight-line distance
 * from the patient's real (browser-geolocated) position, using the same
 * GeocodingInterface/RideDispatchService every other real distance/fare
 * calculation in the app already uses. No external directory API is
 * needed or used: Prompt Aid is a closed marketplace of registered
 * providers, not a general "find any hospital" search, so matching
 * against our own database is the honest, correct scope — not a
 * placeholder for a paid integration.
 *
 * We do NOT claim any of these are hospital emergency departments — the
 * platform has no real emergency-department data, so 'emergency' and
 * 'clinic' triage facility types both resolve to real Clinic records,
 * always labelled "Clinic". Red-level results keep the strong "call
 * 10177 now" messaging regardless of what's listed here.
 */
class EmergencyResultsController extends Controller
{
    public function __construct(
        protected GeocodingInterface $geo,
        protected RideDispatchService $dispatch,
        protected NotificationDispatcherInterface $notifier,
    ) {}

    public function show(Request $request)
    {
        $data = $request->validate([
            'level' => ['nullable', 'in:red,orange,yellow,green'],
            'ref' => ['nullable', 'string'],
            'lat' => ['nullable', 'numeric'],
            'lng' => ['nullable', 'numeric'],
        ]);

        $submission = ! empty($data['ref'])
            ? TriageSubmission::query()->where('reference', $data['ref'])->first()
            : null;

        $level = $data['level'] ?? $submission?->level ?? 'yellow';

        $lat = $data['lat'] ?? $submission?->pickup_lat;
        $lng = $data['lng'] ?? $submission?->pickup_lng;

        // No location at all (geolocation denied and no prior submission) —
        // fall back to Johannesburg CBD rather than showing nothing; the
        // distances shown will be honestly wrong, so we flag that in the view.
        $hasRealLocation = $lat !== null && $lng !== null;
        $lat = (float) ($lat ?? -26.2041);
        $lng = (float) ($lng ?? 28.0473);

        if ($submission && $hasRealLocation && ! $submission->pickup_lat) {
            $submission->update(['pickup_lat' => $lat, 'pickup_lng' => $lng]);
        }

        $facilityTypes = $submission?->facility_types ?: ['clinic', 'doctor', 'pharmacy'];
        if ($level === 'red') {
            $facilityTypes[] = 'emergency';
        }

        $results = collect();

        if (in_array('clinic', $facilityTypes, true) || in_array('emergency', $facilityTypes, true)) {
            $results = $results->merge(
                Clinic::query()->where('status', 'active')->whereNotNull('lat')->get()
                    ->map(fn (Clinic $c) => $this->toResult('Clinic', $c->name, $c->address, (float) $c->lat, (float) $c->lng, route('clinics.show', $c)))
            );
        }

        if (in_array('doctor', $facilityTypes, true)) {
            $results = $results->merge(
                DoctorProfile::query()->where('status', 'active')->where('is_accepting_appointments', true)
                    ->with(['user', 'clinics' => fn ($q) => $q->whereNotNull('lat')])
                    ->get()
                    ->filter(fn (DoctorProfile $d) => $d->clinics->isNotEmpty())
                    ->map(function (DoctorProfile $d) {
                        $clinic = $d->clinics->first();

                        return $this->toResult('Doctor', 'Dr '.$d->user->name, $clinic->name, (float) $clinic->lat, (float) $clinic->lng, route('doctors.show', $d));
                    })
            );
        }

        if (in_array('pharmacy', $facilityTypes, true)) {
            $results = $results->merge(
                Pharmacy::query()->where('status', 'active')->whereNotNull('lat')->get()
                    ->map(fn (Pharmacy $p) => $this->toResult('Pharmacy', $p->name, $p->address, (float) $p->lat, (float) $p->lng, route('pharmacies.show', $p)))
            );
        }

        $results = $results
            ->map(function (array $r) use ($lat, $lng) {
                $r['distance_km'] = $this->geo->distanceKm($lat, $lng, $r['lat'], $r['lng']);
                $r['eta_minutes'] = $this->geo->etaMinutes($r['distance_km']);
                $quotes = $this->dispatch->quotesFor($lat, $lng, $r['lat'], $r['lng']);
                $r['shuttle_fare'] = $quotes[0]['fare'] ?? null;

                return $r;
            })
            ->sortBy('distance_km')
            ->take(6)
            ->values();

        return view('pages.emergency-results', [
            'level' => $level,
            'submission' => $submission,
            'results' => $results,
            'hasRealLocation' => $hasRealLocation,
            'pickupLat' => $lat,
            'pickupLng' => $lng,
        ]);
    }

    /** Patient (or anonymous, if the browser still has the triage reference): alert a real contact via SMS. */
    public function alertContact(Request $request)
    {
        $data = $request->validate([
            'ref' => ['required', 'string', 'exists:triage_submissions,reference'],
            'contact_name' => ['required', 'string', 'max:255'],
            'contact_phone' => ['required', 'string', 'max:30'],
        ]);

        $submission = TriageSubmission::query()->where('reference', $data['ref'])->firstOrFail();

        $submission->update([
            'emergency_contact_name' => $data['contact_name'],
            'emergency_contact_phone' => $data['contact_phone'],
            'contact_alerted_at' => now(),
        ]);

        $this->notifier->smsToPhone(
            $data['contact_phone'],
            "Prompt Aid: {$data['contact_name']}, this is an alert that someone you know has started an emergency triage ({$submission->reference}, level ".strtoupper($submission->level).'). We will keep you updated.',
            $data['contact_name'],
        );

        return response()->json(['message' => 'Contact alerted.']);
    }

    protected function toResult(string $type, string $name, ?string $address, float $lat, float $lng, string $url): array
    {
        return compact('type', 'name', 'address', 'lat', 'lng', 'url');
    }
}
