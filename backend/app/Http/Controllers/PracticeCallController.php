<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\CallLog;
use App\Services\Doctors\DoctorMatchException;
use App\Services\PracticeCalls\CallLogService;
use App\Services\PracticeCalls\CallRecordingService;
use App\Services\PracticeCalls\PracticeBookingService;
use App\Services\PracticeCalls\PracticeCallService;
use App\Services\Video\VideoRoomService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Orange practice call — shared by the website (/account/practice-calls/*,
 * staff page JSON at /practice-calls/*, session auth) and the app
 * (/api/practice-calls/*, Sanctum). Every endpoint is scoped to the call's
 * patient or the practice it belongs to (404 for anyone else).
 */
class PracticeCallController extends Controller
{
    public function __construct(
        protected PracticeCallService $calls,
        protected PracticeBookingService $booking,
    ) {}

    protected function mode(Request $request): string
    {
        if ($request->is('api/*')) {
            return 'app';
        }

        return $request->query('mode') === 'staff' ? 'staff' : 'web';
    }

    protected function find(int $id): CallLog
    {
        $call = CallLog::query()->where('source', CallLog::SOURCE_PRACTICE)->find($id);
        if (! $call) {
            throw new DoctorMatchException('Call not found.', 404);
        }

        return $call;
    }

    protected function guard(callable $fn): JsonResponse
    {
        try {
            return response()->json($fn())->header('Cache-Control', 'no-store, private');
        } catch (DoctorMatchException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status ?? 422);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('[PracticeCall] '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());

            return response()->json(['message' => 'Something went wrong. Please try again.'], 500);
        }
    }

    // ---------------------------------------------------------------- polls

    /** Patient: is a practice calling me? Practice user: is my call ringing? (cheap, cache-only). */
    public function ring(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = null;
        if ($user?->isPatient()) {
            $data = $this->calls->ringFor($user);
        } elseif ($user) {
            $data = $this->calls->practiceRingFor($user);
        }

        return response()->json(['data' => $data])->header('Cache-Control', 'no-store, private');
    }

    /** Practice side: open + recent calls of my practice(s). */
    public function index(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            $user = $request->user();
            $this->calls->tick();
            $clinics = $user->practiceClinicIds();
            $q = CallLog::query()->with(['patientUser', 'clinic', 'appointment'])->where('source', CallLog::SOURCE_PRACTICE)->latest('id')->limit(30);
            if ($user->isPatient()) {
                $q->where('patient_user_id', $user->id);
            } else {
                $q->where(fn ($w) => $w->whereIn('clinic_id', $clinics ?: [0])->orWhere('practice_user_id', $user->id));
            }

            return ['data' => $q->get()->map(fn (CallLog $c) => [
                'id' => $c->id,
                'status' => $c->status,
                'outcome' => $c->outcome,
                'outcome_label' => CallLog::outcomeLabel($c->outcome),
                'patient_name' => $user->isPatient() ? null : $c->patientUser?->name,
                'practice_name' => $c->clinic?->name,
                'ringing_at' => $c->ringing_at?->toIso8601String(),
                'connected_at' => $c->connected_at?->toIso8601String(),
                'duration_seconds' => (int) $c->duration_seconds,
                'booked' => $c->appointment && $c->appointment->status === Appointment::STATUS_CONFIRMED,
                'open' => $c->isOpen(),
            ])->values()->all()];
        });
    }

    public function show(Request $request, int $call): JsonResponse
    {
        return $this->guard(fn () => ['data' => $this->calls->payload($this->find($call), $request->user(), $this->mode($request))]);
    }

    public function accept(Request $request, int $call): JsonResponse
    {
        return $this->guard(function () use ($request, $call) {
            $c = $this->calls->accept($this->find($call), $request->user());

            return ['data' => $this->calls->payload($c, $request->user(), $this->mode($request))];
        });
    }

    public function decline(Request $request, int $call): JsonResponse
    {
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:255']])['reason'] ?? null;

        return $this->guard(function () use ($request, $call, $reason) {
            $c = $this->calls->decline($this->find($call), $request->user(), $reason);

            return ['data' => $this->calls->payload($c, $request->user(), $this->mode($request))];
        });
    }

    public function ringAgain(Request $request, int $call): JsonResponse
    {
        return $this->guard(function () use ($request, $call) {
            $c = $this->calls->ringAgain($this->find($call), $request->user());

            return ['data' => $this->calls->payload($c, $request->user(), $this->mode($request))];
        });
    }

    public function presence(Request $request, int $call): JsonResponse
    {
        $in = $request->validate(['state' => ['required', 'in:in,out']])['state'] === 'in';

        return $this->guard(function () use ($request, $call, $in) {
            $this->calls->presence($this->find($call), $request->user(), $in);

            return ['ok' => true];
        });
    }

    public function end(Request $request, int $call): JsonResponse
    {
        $data = $request->validate([
            'outcome' => ['nullable', 'string', 'in:'.implode(',', array_keys(CallLog::OUTCOMES))],
            'close_request' => ['nullable', 'boolean'],
        ]);

        return $this->guard(function () use ($request, $call, $data) {
            $c = $this->calls->end($this->find($call), $request->user(), $data['outcome'] ?? null, (bool) ($data['close_request'] ?? false));

            return ['data' => $this->calls->payload($c, $request->user(), $this->mode($request))];
        });
    }

    public function location(Request $request, int $call): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'label' => ['nullable', 'string', 'max:200'],
        ]);

        return $this->guard(function () use ($request, $call, $data) {
            $this->calls->shareLocation($this->find($call), $request->user(), (float) $data['lat'], (float) $data['lng'], $data['label'] ?? null);

            return ['ok' => true];
        });
    }

    // ---------------------------------------------------------------- booking

    public function slots(Request $request, int $call): JsonResponse
    {
        $data = $request->validate(['doctor_id' => ['required', 'integer'], 'date' => ['required', 'date_format:Y-m-d']]);

        return $this->guard(fn () => ['data' => $this->booking->slots($this->find($call), $request->user(), (int) $data['doctor_id'], $data['date'])]);
    }

    public function book(Request $request, int $call): JsonResponse
    {
        $data = $request->validate([
            'doctor_profile_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'mode' => ['required', 'in:in_person,video'],
            'reason' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:240'],
            'outside_sessions' => ['nullable', 'boolean'],
        ]);

        return $this->guard(function () use ($request, $call, $data) {
            $c = $this->find($call);
            $this->booking->capture($c, $request->user(), $data);

            return ['message' => 'Booked — it is on the doctor\'s and the patient\'s calendars.', 'data' => $this->calls->payload($c->fresh(), $request->user(), $this->mode($request))];
        });
    }

    public function shuttleQuote(Request $request, int $call): JsonResponse
    {
        $data = $request->validate([
            'pickup_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'pickup_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'pickup_address' => ['nullable', 'string', 'max:255'],
            'return_leg' => ['nullable', 'boolean'],
        ]);

        return $this->guard(function () use ($request, $call, $data) {
            $c = $this->find($call);
            $this->booking->quoteShuttle($c, $request->user(), $data);

            return ['message' => 'Shuttle options sent to the patient.', 'data' => $this->calls->payload($c->fresh(), $request->user(), $this->mode($request))];
        });
    }

    public function shuttleChoose(Request $request, int $call): JsonResponse
    {
        $data = $request->validate(['vehicle_type' => ['required', 'string', 'max:40']]);

        return $this->guard(function () use ($request, $call, $data) {
            $c = $this->find($call);
            $this->booking->chooseShuttle($c, $request->user(), $data['vehicle_type']);

            return ['message' => 'Shuttle booked. Complete the payment to confirm it.', 'data' => $this->calls->payload($c->fresh(), $request->user(), $this->mode($request))];
        });
    }

    // ---------------------------------------------------------------- recording (practice browser)

    public function recordingChunk(Request $request, int $call): JsonResponse
    {
        $data = $request->validate([
            'chunk' => ['required', 'file', 'max:'.(int) (CallRecordingService::MAX_CHUNK_BYTES / 1024)],
            'seq' => ['required', 'integer', 'min:0', 'max:100000'],
            'final' => ['nullable', 'boolean'],
            'mime' => ['nullable', 'string', 'max:80'],
        ]);

        return $this->guard(function () use ($request, $call, $data) {
            $rec = app(CallRecordingService::class)->receiveChunk($this->find($call), $request->user(), $request->file('chunk'), (int) $data['seq'], (bool) ($data['final'] ?? false), $data['mime'] ?? null);

            return ['ok' => true, 'status' => $rec->status, 'chunks' => $rec->chunk_count];
        });
    }

    // ---------------------------------------------------------------- pages

    /** Patient web page. */
    public function page(Request $request, int $call)
    {
        $c = CallLog::query()->where('source', CallLog::SOURCE_PRACTICE)->findOrFail($call);
        abort_unless($this->calls->roleFor($c, $request->user()) === 'patient', 404);

        return response()->view('account.practice-call', [
            'callId' => $c->id,
            'cfg' => [
                'stateUrl' => route('practice-call.state', $c->id),
                'base' => url('/practice-calls/'.$c->id),
                'csrf' => csrf_token(),
                'role' => 'patient',
                'soundUrl' => asset('assets/sounds/request-alert.mp3'),
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    /** App WebView: the Jitsi audio call for a party, authorised by a single-use token. */
    public function embed(Request $request, int $call)
    {
        $claim = $this->calls->consumeEmbedToken((string) $request->query('t', ''));
        $c = $claim && (int) $claim['call_id'] === $call ? CallLog::query()->find($call) : null;
        $user = $c ? \App\Models\User::query()->find($claim['user_id']) : null;
        $role = $c && $user ? $this->calls->roleFor($c, $user) : null;
        if (! in_array($role, ['patient', 'practice'], true) || ! $c->isOpen()) {
            return response()->view('video.call-error', ['code' => 'expired', 'message' => 'This call link has expired. Go back and tap Join again.'], 410);
        }
        try {
            $counterpart = $role === 'patient' ? ($c->clinic?->name ?? 'Your practice') : ($c->patientUser?->name ?? 'Patient');
            $jaasRecord = $role === 'practice' && $this->calls->recordingOn($c) && app(CallRecordingService::class)->usesJaas();
            $config = $this->calls->callConfig($c, $user, $role, 'app', $counterpart, $jaasRecord);
        } catch (\Throwable $e) {
            Log::warning('[PracticeCall] embed config: '.$e->getMessage());

            return response()->view('video.call-error', ['code' => 'config', 'message' => 'In-app calls are temporarily unavailable.'], 503);
        }

        return response()->view('video.practice-call-embed', ['config' => $config, 'callId' => $c->id, 'notice' => $this->calls->recordingOn($c) ? \App\Support\PracticeCallSettings::recordingNotice() : null])
            ->withHeaders(['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'strict-origin', 'X-Robots-Tag' => 'noindex, nofollow']);
    }

    // ---------------------------------------------------------------- evidence

    public function evidence(Request $request, int $appointment): JsonResponse
    {
        $appt = Appointment::query()->find($appointment);
        $svc = app(CallLogService::class);
        $level = $appt ? $svc->evidenceAccess($appt, $request->user()) : null;
        if (! $level) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return response()->json(['data' => $svc->evidence($appt, $level === 'full')])->header('Cache-Control', 'no-store, private');
    }

    public function evidenceDownload(Request $request, int $appointment, string $format)
    {
        $appt = Appointment::query()->findOrFail($appointment);
        $svc = app(CallLogService::class);
        abort_unless($svc->evidenceAccess($appt, $request->user()) === 'full', 404);
        $name = 'consult-evidence-'.($appt->booking_ref ?: $appt->id);

        return $format === 'pdf'
            ? response($svc->evidencePdf($appt), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$name.'.pdf"', 'Cache-Control' => 'no-store, private'])
            : response($svc->evidenceCsv($appt), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$name.'.csv"', 'Cache-Control' => 'no-store, private']);
    }
}
