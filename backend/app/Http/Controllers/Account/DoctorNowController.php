<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\DoctorMatchRequest;
use App\Services\Doctors\DoctorMatchException;
use App\Services\Doctors\DoctorMatchPricing;
use App\Services\Doctors\DoctorMatchService;
use App\Support\DoctorNowSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Patient website: /account/see-a-doctor — choose specialty + mode, see the
 * quote, request, watch the live "finding your doctor" screen (polls the
 * status endpoint every 3s), then the matched card / fallback options.
 * JSON endpoints are session-authenticated and scoped to the patient.
 */
class DoctorNowController extends Controller
{
    public function __construct(protected DoctorMatchService $match, protected DoctorMatchPricing $pricing) {}

    public function show(Request $request)
    {
        $patient = $request->user()->patientProfile;
        abort_unless($patient, 403, 'Only patient accounts can request a doctor.');

        $current = $this->match->currentRequestFor($patient);
        if ($current) {
            $this->match->progress($current);
            $current = $current->fresh();
        }

        $specialties = DoctorMatchService::specialties();
        $online = [];
        foreach ($specialties as $s) {
            $online[$s] = DoctorMatchService::onlineQuery($s)->count();
        }

        $saved = ($patient->lat !== null && $patient->lng !== null)
            ? ['lat' => (float) $patient->lat, 'lng' => (float) $patient->lng, 'label' => (string) ($patient->address ?: 'Saved address')]
            : null;

        return view('account.see-a-doctor', [
            'enabled' => DoctorNowSettings::bool('doctor_now_enabled'),
            'specialties' => $specialties,
            'online' => $online,
            'current' => $current ? $this->match->patientPayload($current) : null,
            'savedLocation' => $saved,
            'preSpecialty' => (string) $request->query('specialty', ''),
            'preMode' => (string) $request->query('mode', 'video'),
            'radiusKm' => DoctorNowSettings::float('doctor_now_inperson_radius_km'),
            'offerSeconds' => DoctorNowSettings::int('doctor_now_offer_timeout_seconds'),
            'requestMinutes' => DoctorNowSettings::int('doctor_now_request_timeout_minutes'),
            'freeCancelMinutes' => DoctorNowSettings::int('doctor_now_free_cancel_minutes'),
            // Orange triage "speak to a doctor now": video / call back (+ in person, less prominent).
            'defaultSpecialty' => DoctorMatchService::defaultSpecialty(),
            'modes' => DoctorMatchService::enabledModes(),
            'triageRef' => mb_substr((string) $request->query('triage', ''), 0, 40),
            'callbackPhone' => \App\Services\Consults\ConsultCallService::displayPhone(\App\Services\Consults\ConsultCallService::normalisePhone($request->user()->phone)) ?? (string) $request->user()->phone,
            'callbackTarget' => \App\Services\Consults\ConsultCallService::targetMinutes(),
            'dispatch' => \App\Models\EmergencyContact::primary(),
        ]);
    }

    public function quote(Request $request)
    {
        $data = $request->validate([
            'specialty' => ['nullable', 'string', 'max:255'],
            'mode' => ['required', 'in:video,in_person,callback'],
        ]);

        return response()->json(['data' => $this->pricing->quote(($data['specialty'] ?? '') ?: (string) DoctorMatchService::defaultSpecialty(), $data['mode'])]);
    }

    public function store(Request $request)
    {
        $patient = $request->user()->patientProfile;
        abort_unless($patient, 403);

        $data = $request->validate([
            // Specialty defaults to GP / general (orange triage "speak to a doctor now").
            'specialty' => ['nullable', 'string', 'max:255'],
            'mode' => ['required', 'in:video,in_person,callback'],
            'lat' => ['required_if:mode,in_person', 'nullable', 'numeric', 'between:-90,90'],
            'lng' => ['required_if:mode,in_person', 'nullable', 'numeric', 'between:-180,180'],
            'radius_km' => ['nullable', 'numeric', 'min:1', 'max:500'],
            'callback_phone' => ['required_if:mode,callback', 'nullable', 'string', 'max:32'],
            'callback_channel' => ['nullable', 'in:phone,whatsapp,either'],
            'callback_note' => ['nullable', 'string', 'max:500'],
            'triage_reference' => ['nullable', 'string', 'max:40'],
        ]);

        try {
            $match = $this->match->start($patient, $data['specialty'] ?? null, $data['mode'], isset($data['lat']) ? (float) $data['lat'] : null, isset($data['lng']) ? (float) $data['lng'] : null, isset($data['radius_km']) ? (float) $data['radius_km'] : $patient->search_radius_km, \Illuminate\Support\Arr::only($data, ['callback_phone', 'callback_channel', 'callback_note', 'triage_reference']));
        } catch (DoctorMatchException $e) {
            $open = $this->match->openRequestFor($patient);

            return response()->json(['message' => $e->getMessage(), 'data' => $open ? $this->match->patientPayload($open) : null], $e->status);
        }

        return response()->json(['data' => $this->match->patientPayload($match)], 201);
    }

    public function status(Request $request, int $id)
    {
        $match = $this->owned($request, $id);
        $this->match->progress($match);

        return response()->json(['data' => $this->match->patientPayload($match->fresh())]);
    }

    public function cancel(Request $request, int $id)
    {
        $match = $this->owned($request, $id);
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:255']])['reason'] ?? null;

        return $this->guard(fn () => $this->match->patientPayload($this->match->cancelByPatient($match, $reason)));
    }

    public function waitlist(Request $request, int $id)
    {
        $match = $this->owned($request, $id);
        $this->match->joinWaitlist($match);

        return response()->json(['data' => $this->match->patientPayload($match->fresh())]);
    }

    public function pay(Request $request, int $id)
    {
        $match = $this->owned($request, $id);

        return $this->guard(function () use ($match) {
            $result = $this->match->pay($match, 'card');
            if (! $result['success']) {
                throw new DoctorMatchException($result['message'] ?: 'Payment failed.', 402);
            }

            return $this->match->patientPayload($match->fresh());
        });
    }

    protected function owned(Request $request, int $id): DoctorMatchRequest
    {
        $patientId = $request->user()->patientProfile?->id;
        $match = DoctorMatchRequest::query()->find($id);
        abort_unless($match && $patientId && $match->patient_profile_id === $patientId, 404);

        return $match;
    }

    protected function guard(callable $fn): JsonResponse
    {
        try {
            return response()->json(['data' => $fn()]);
        } catch (DoctorMatchException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }
}
