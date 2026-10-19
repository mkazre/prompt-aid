<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\DoctorProfile;
use App\Services\Consults\ConsultCallService;
use App\Services\Doctors\DoctorMatchException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Speak to a doctor now" after the doctor has the consult — shared by the
 * app API (/api/consults/*, Sanctum) and the website (/account/consult*,
 * session). Patients: the incoming-call (ring) state + answer/decline.
 * Doctors: the consult card (call back), Start (rings the patient),
 * "calling now", outcomes and "start video instead". Every action is
 * scoped to the appointment's own patient / doctor (404 otherwise).
 */
class ConsultCallController extends Controller
{
    public function __construct(protected ConsultCallService $calls) {}

    // ---------------------------------------------------------------- patient

    /** Cheap poll: is a doctor calling me right now? */
    public function ring(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $user && $user->isPatient() ? $this->calls->ringFor($user) : null;

        return response()->json(['data' => $data])->header('Cache-Control', 'no-store, private');
    }

    public function answer(Request $request, int $appointment): JsonResponse
    {
        return $this->guard(function () use ($request, $appointment) {
            $appt = $this->find($appointment);
            $this->calls->answer($appt, $request->user());

            return ['ok' => true, 'appointment_id' => $appt->id];
        });
    }

    public function decline(Request $request, int $appointment): JsonResponse
    {
        return $this->guard(function () use ($request, $appointment) {
            $appt = $this->find($appointment);
            $this->calls->decline($appt, $request->user());

            return ['ok' => true];
        });
    }

    // ---------------------------------------------------------------- doctor

    public function card(Request $request, int $appointment): JsonResponse
    {
        return $this->guard(fn () => ['data' => $this->calls->doctorCard($this->find($appointment), $this->doctor($request))]);
    }

    /**
     * Doctor pressed Start: video → the patient's phone/browser rings and
     * auto-joins; call back → the card (Call / WhatsApp buttons).
     */
    public function start(Request $request, int $appointment): JsonResponse
    {
        return $this->guard(function () use ($request, $appointment) {
            $doctor = $this->doctor($request);
            $appt = $this->find($appointment);
            $card = $this->calls->doctorCard($appt, $doctor); // ownership check
            $rang = false;
            if ($appt->isVideo()) {
                if (! $appt->canJoinVideoNow()) {
                    throw new DoctorMatchException('The video room opens 10 minutes before the appointment and closes 30 minutes after it ends.', 423);
                }
                $rang = $this->calls->ring($appt, true);
                $card = $this->calls->doctorCard($appt->fresh(), $doctor);
            }

            return ['data' => $card, 'rang' => $rang];
        });
    }

    public function calling(Request $request, int $appointment): JsonResponse
    {
        $via = $request->validate(['via' => ['nullable', 'in:phone,whatsapp']])['via'] ?? null;

        return $this->guard(function () use ($request, $appointment, $via) {
            $doctor = $this->doctor($request);
            $appt = $this->calls->markCalling($this->find($appointment), $doctor, $via);

            return ['data' => $this->calls->doctorCard($appt, $doctor)];
        });
    }

    public function outcome(Request $request, int $appointment): JsonResponse
    {
        $outcome = $request->validate(['outcome' => ['required', 'in:'.implode(',', Appointment::CALL_OUTCOMES)]])['outcome'];

        return $this->guard(function () use ($request, $appointment, $outcome) {
            $doctor = $this->doctor($request);
            $appt = $this->calls->recordOutcome($this->find($appointment), $doctor, $outcome);

            return ['data' => $this->calls->doctorCard($appt, $doctor)];
        });
    }

    public function switchToVideo(Request $request, int $appointment): JsonResponse
    {
        return $this->guard(function () use ($request, $appointment) {
            $doctor = $this->doctor($request);
            $appt = $this->calls->switchToVideo($this->find($appointment), $doctor);

            return ['data' => $this->calls->doctorCard($appt, $doctor)];
        });
    }

    // ---------------------------------------------------------------- helpers

    protected function find(int $id): Appointment
    {
        $appt = Appointment::query()->with(['patient.user', 'doctor.user'])->find($id);
        if (! $appt) {
            throw new DoctorMatchException('Appointment not found.', 404);
        }

        return $appt;
    }

    protected function doctor(Request $request): DoctorProfile
    {
        $doctor = $request->user()?->doctorProfile;
        if (! $doctor) {
            throw new DoctorMatchException('Appointment not found.', 404);
        }

        return $doctor;
    }

    protected function guard(callable $fn): JsonResponse
    {
        try {
            return response()->json($fn());
        } catch (DoctorMatchException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }
}
