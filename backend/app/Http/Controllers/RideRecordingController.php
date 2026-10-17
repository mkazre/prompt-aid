<?php

namespace App\Http\Controllers;

use App\Models\Ride;
use App\Models\RideRecording;
use App\Models\User;
use App\Services\Safety\RecordingException;
use App\Services\Safety\RideRecordingService;
use App\Support\RideRecordingSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Trip audio recordings. The same actions serve the app (Sanctum, routes/api_safety.php)
 * and the website / driver console (session, routes/web_safety.php).
 * Media is served from a signed, short-lived URL (no auth header needed by
 * <audio> elements or the app's player); see RideRecordingService.
 */
class RideRecordingController extends Controller
{
    public function __construct(protected RideRecordingService $recordings) {}

    /** Recorder state + every recording of the ride (both sides) with signed play/download URLs. */
    public function index(Request $request, Ride $ride): JsonResponse
    {
        abort_unless($this->recordings->canAccess($request->user(), $ride), 403);

        return response()->json($this->recordings->rideSummary($ride->loadMissing('patient', 'driver'), $request->user()));
    }

    public function start(Request $request, Ride $ride): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'mime' => ['required', 'string', 'max:80'],
            'layout' => ['nullable', 'in:stream,segments'],
            'consent' => ['accepted'],
        ], ['consent.accepted' => 'Please confirm you understand how trip recording works.']);

        if ($reason = $this->recordings->cannotRecordReason($user, $ride)) {
            return response()->json(['message' => $reason], 403);
        }
        $mime = RideRecordingSettings::normaliseMime($data['mime']);
        if (! $mime) {
            return response()->json(['message' => 'This audio format is not supported. Allowed: m4a, aac, webm, ogg, mp3.'], 422);
        }

        $isApi = $request->is('api/*');
        $rec = $this->recordings->start(
            $ride,
            $user,
            $mime,
            $isApi ? 'app' : 'web',
            $data['layout'] ?? ($isApi ? RideRecording::LAYOUT_SEGMENTS : RideRecording::LAYOUT_STREAM),
        );

        return response()->json([
            'recording' => ['id' => $rec->id, 'role' => $rec->role, 'layout' => $rec->layout, 'mime' => $rec->mime],
            'chunk_seconds' => RideRecordingSettings::chunkSeconds(),
            'max_minutes' => RideRecordingSettings::maxMinutes(),
            'max_chunk_bytes' => RideRecordingSettings::MAX_CHUNK_BYTES,
        ], 201);
    }

    public function chunk(Request $request, Ride $ride, RideRecording $recording): JsonResponse
    {
        $this->assertUploader($request->user(), $ride, $recording);
        $data = $request->validate([
            'chunk' => ['required', 'file', 'max:'.(int) (RideRecordingSettings::MAX_CHUNK_BYTES / 1024)],
            'seq' => ['required', 'integer', 'min:0'],
            'duration_ms' => ['nullable', 'integer', 'min:0'],
            'sha256' => ['nullable', 'string', 'size:64'],
        ]);

        try {
            $result = $this->recordings->addChunk($recording, $request->file('chunk'), (int) $data['seq'], (int) ($data['duration_ms'] ?? 0), $data['sha256'] ?? null);
        } catch (RecordingException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json($result + ['seq' => (int) $data['seq']]);
    }

    public function complete(Request $request, Ride $ride, RideRecording $recording): JsonResponse
    {
        $this->assertUploader($request->user(), $ride, $recording);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:40']]);
        $rec = $this->recordings->complete($recording, $data['reason'] ?? 'stopped');

        return response()->json([
            'recording' => $this->recordings->present($rec->fresh(['user']), $request->user()),
        ]);
    }

    /** Signed media URL: inline play (Range-aware) or download (zip for app recordings). */
    public function media(Request $request, RideRecording $recording)
    {
        $viewer = User::query()->find((int) $request->query('u'));
        $ride = $recording->ride;
        abort_unless($viewer && $ride && $viewer->status === 'active' && $this->recordings->canAccess($viewer, $ride), 403);

        $seq = $request->filled('seg') ? (int) $request->query('seg') : null;
        $download = $request->boolean('dl');
        $this->recordings->logMediaAccess($recording, $viewer, $download, $seq, $request);

        return $this->recordings->mediaResponse($recording, $seq, $download, $request);
    }

    /** Website: recordings of one trip (patient account). */
    public function accountPage(Request $request, Ride $ride)
    {
        abort_unless($this->recordings->partyRole($request->user(), $ride) !== null, 403);

        return view('account.ride-recordings', [
            'ride' => $ride,
            'summary' => $this->recordings->rideSummary($ride, $request->user()),
        ]);
    }

    protected function assertUploader(User $user, Ride $ride, RideRecording $recording): void
    {
        // Only the person who started it. Deliberately NOT re-checking the current
        // driver assignment: a driver reassigned mid-trip may still upload the
        // pieces already recorded (evidence), but cannot start a new recording.
        abort_unless((int) $recording->ride_id === (int) $ride->id && (int) $recording->user_id === (int) $user->id, 403);
    }
}
