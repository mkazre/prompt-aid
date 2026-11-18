<?php

namespace App\Services\PracticeCalls;

use App\Models\Appointment;
use App\Models\CallLog;
use App\Models\CallRecording;
use App\Models\CallRecordingAccessLog;
use App\Models\User;
use App\Services\Doctors\DoctorMatchException;
use App\Services\Video\VideoRoomService;
use App\Support\PracticeCallSettings as S;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

/**
 * Call recordings for in-app voice calls and video consults.
 *
 *  - JaaS (8x8) server recording: the moderator's JWT carries the "recording"
 *    feature and the practice page calls startRecording({mode: 'file'}) once
 *    both are in. JaaS posts RECORDING_UPLOADED to /api/webhooks/jaas with a
 *    pre-authenticated download link; we download it and push it to storage.
 *    REQUIRES a paid JaaS plan with recording enabled.
 *  - Client fallback (voice calls on the web practice page): the browser
 *    records the mixed call audio (MediaRecorder) and uploads it in chunks;
 *    the last chunk finalises the file (sha256) and pushes it to storage.
 *
 * Access: platform admins (call-recordings.play) and the practice / doctor of
 * the call. Patients never get the recording (they see the call log only).
 * Every play / download / hold / delete is logged.
 */
class CallRecordingService
{
    public const MAX_CHUNK_BYTES = 25 * 1024 * 1024;

    public const MIMES = ['audio/webm', 'audio/ogg', 'audio/mp4', 'audio/mpeg', 'audio/x-m4a', 'audio/m4a', 'video/mp4', 'video/webm'];

    public function __construct(protected RecordingStorage $storage) {}

    public function usesJaas(): bool
    {
        $mode = S::str('call_recording_mode');

        return in_array($mode, ['auto', 'jaas'], true) && app(VideoRoomService::class)->provider() === 'jaas';
    }

    public function usesClient(): bool
    {
        $mode = S::str('call_recording_mode');

        return $mode === 'client' || ($mode === 'auto' && ! $this->usesJaas());
    }

    /** What the practice page should do. @return array<string, mixed>|null */
    public function clientPlan(CallLog $call): ?array
    {
        if (! app(PracticeCallService::class)->recordingOn($call)) {
            return ['method' => 'off'];
        }
        $rec = $call->recordings->sortByDesc('id')->first();

        return [
            'method' => $this->usesJaas() ? 'jaas' : ($this->usesClient() ? 'client' : 'off'),
            'chunk_url' => \Illuminate\Support\Facades\Route::has('practice-call.recording') ? route('practice-call.recording', $call->id) : null,
            'status' => $rec?->status ?? 'none',
            'max_minutes' => max(5, S::int('call_recording_max_minutes')),
        ];
    }

    /** Client-side chunk (practice browser). Final chunk assembles and stores the file. */
    public function receiveChunk(CallLog $call, User $user, UploadedFile $file, int $seq, bool $final, ?string $mime = null): CallRecording
    {
        if (app(PracticeCallService::class)->authorizeParty($call, $user) !== 'practice') {
            throw new DoctorMatchException('Only the practice side records the call.', 403);
        }
        if (! app(PracticeCallService::class)->recordingOn($call)) {
            throw new DoctorMatchException('Recording is switched off for this call.', 409);
        }
        if ($file->getSize() > self::MAX_CHUNK_BYTES) {
            throw new DoctorMatchException('Chunk too large.', 413);
        }
        $mime = $this->cleanMime($mime ?: (string) $file->getMimeType());
        $rec = DB::transaction(function () use ($call, $user, $mime) {
            $rec = CallRecording::query()->where('call_log_id', $call->id)->where('source', 'client')->where('status', CallRecording::RECEIVING)->lockForUpdate()->latest('id')->first();
            if (! $rec) {
                $rec = CallRecording::query()->create([
                    'call_log_id' => $call->id, 'source' => 'client', 'status' => CallRecording::RECEIVING, 'mime' => $mime,
                    'recorded_by_user_id' => $user->id, 'started_at' => now(),
                ]);
                CallLog::query()->whereKey($call->id)->update(['recording_status' => 'recording']);
            }

            return $rec;
        });
        $dir = $this->tmpDir($rec);
        File::ensureDirectoryExists($dir);
        if (! file_exists($dir.'/'.sprintf('%06d', $seq).'.part')) {
            $file->move($dir, sprintf('%06d', $seq).'.part');
            $rec->increment('chunk_count');
        }
        if ($final) {
            return $this->finaliseClient($rec->fresh());
        }

        return $rec->fresh();
    }

    protected function tmpDir(CallRecording $rec): string
    {
        return storage_path('app/private/call-recordings-tmp/'.$rec->id);
    }

    public function finaliseClient(CallRecording $rec): CallRecording
    {
        $dir = $this->tmpDir($rec);
        $parts = glob($dir.'/*.part') ?: [];
        sort($parts);
        if (! $parts) {
            $rec->update(['status' => CallRecording::FAILED, 'error' => 'No audio was received.']);

            return $rec->fresh();
        }
        $rec->update(['status' => CallRecording::PROCESSING, 'ended_at' => now()]);
        $out = $dir.'/recording.bin';
        $fh = fopen($out, 'wb');
        foreach ($parts as $p) {
            $in = fopen($p, 'rb');
            stream_copy_to_stream($in, $fh);
            fclose($in);
        }
        fclose($fh);

        return $this->store($rec, $out, $rec->mime ?: 'audio/webm', true);
    }

    /** Push an assembled / downloaded file to storage and record checksum + location. */
    public function store(CallRecording $rec, string $localFile, string $mime, bool $cleanupDir = false): CallRecording
    {
        $call = $rec->callLog;
        $ext = match (true) {
            str_contains($mime, 'mp4'), str_contains($mime, 'm4a') => 'mp4',
            str_contains($mime, 'ogg') => 'ogg',
            str_contains($mime, 'mpeg') => 'mp3',
            default => 'webm',
        };
        $date = ($call?->created_at ?? now())->format('Y/m');
        $path = $date.'/'.($call?->uuid ?? 'call-'.$rec->call_log_id).'-'.$rec->id.'.'.$ext;
        try {
            [$disk, $stored] = $this->storage->put($localFile, $path, $mime);
            $rec->update([
                'status' => CallRecording::STORED,
                'disk' => $disk,
                'path' => $stored,
                'mime' => $mime,
                'size_bytes' => (int) filesize($localFile),
                'sha256' => hash_file('sha256', $localFile),
                'uploaded_at' => now(),
                'duration_seconds' => $rec->duration_seconds ?: ($call?->duration_seconds ?: null),
                'retention_until' => now()->addDays(max(1, S::int('call_recording_retention_days'))),
                'error' => null,
            ]);
            CallLog::query()->whereKey($rec->call_log_id)->update(['recording_status' => 'stored']);
        } catch (\Throwable $e) {
            Log::warning('[CallRecording] store failed: '.$e->getMessage());
            $rec->update(['status' => CallRecording::FAILED, 'error' => mb_substr($e->getMessage(), 0, 500)]);
            CallLog::query()->whereKey($rec->call_log_id)->update(['recording_status' => 'failed']);
        } finally {
            if ($cleanupDir) {
                File::deleteDirectory(dirname($localFile));
            } elseif (is_file($localFile)) {
                @unlink($localFile);
            }
        }

        return $rec->fresh();
    }

    protected function cleanMime(string $mime): string
    {
        $m = strtolower(trim(explode(';', $mime)[0]));

        return in_array($m, self::MIMES, true) ? $m : 'audio/webm';
    }

    // ------------------------------------------------------------------
    // JaaS webhook
    // ------------------------------------------------------------------

    /**
     * Verify the JaaS webhook signature: header "X-Jaas-Signature: t=<ts>,v1=<sig>",
     * sig = base64(HMAC-SHA256(secret, "<ts>.<raw body>")). 5-minute tolerance.
     */
    public function verifyJaas(string $raw, ?string $header): bool
    {
        $secret = S::secret('jaas_webhook_secret');
        if ($secret === '' || ! $header) {
            return false;
        }
        $parts = [];
        foreach (explode(',', $header) as $kv) {
            [$k, $v] = array_pad(explode('=', trim($kv), 2), 2, '');
            $parts[$k] = $v;
        }
        $ts = (int) ($parts['t'] ?? 0);
        $sig = (string) ($parts['v1'] ?? '');
        if (! $ts || $sig === '' || abs(time() - $ts) > 300) {
            return false;
        }
        $expected = base64_encode(hash_hmac('sha256', $ts.'.'.$raw, $secret, true));

        return hash_equals($expected, $sig);
    }

    /** RECORDING_UPLOADED → download → storage. Returns a short status string. */
    public function handleJaas(array $payload): string
    {
        if (($payload['eventType'] ?? '') !== 'RECORDING_UPLOADED') {
            return 'ignored';
        }
        $fqn = (string) ($payload['fqn'] ?? '');
        $room = str_contains($fqn, '/') ? substr($fqn, strrpos($fqn, '/') + 1) : $fqn;
        $link = (string) ($payload['data']['preAuthenticatedLink'] ?? '');
        $external = (string) ($payload['sessionId'] ?? $payload['idempotencyKey'] ?? '');
        if ($room === '' || $link === '' || ! preg_match('#^https://#i', $link)) {
            return 'invalid';
        }
        $call = CallLog::query()->where('room', $room)->latest('id')->first();
        if (! $call) {
            // A video consult: the room is the appointment's video room.
            $appt = Appointment::query()->where('video_room', $room)->first();
            $call = $appt ? app(CallLogService::class)->forConsult($appt) : null;
        }
        if (! $call) {
            return 'unknown_room';
        }
        if ($external !== '' && CallRecording::query()->where('external_id', $external)->where('status', CallRecording::STORED)->exists()) {
            return 'duplicate';
        }
        $rec = CallRecording::query()->create([
            'call_log_id' => $call->id, 'source' => 'jaas', 'status' => CallRecording::PROCESSING,
            'external_id' => $external ?: null, 'started_at' => $call->connected_at, 'ended_at' => $call->ended_at,
            'duration_seconds' => isset($payload['data']['durationSec']) ? (int) $payload['data']['durationSec'] : null,
        ]);
        $tmp = storage_path('app/private/call-recordings-tmp/jaas-'.$rec->id.'.mp4');
        File::ensureDirectoryExists(dirname($tmp));
        try {
            $res = Http::timeout(600)->withOptions(['sink' => $tmp])->get($link);
            if (! $res->successful()) {
                throw new \RuntimeException('Download failed (HTTP '.$res->status().')');
            }
        } catch (\Throwable $e) {
            $rec->update(['status' => CallRecording::FAILED, 'error' => mb_substr($e->getMessage(), 0, 500)]);
            @unlink($tmp);

            return 'download_failed';
        }
        $rec = $this->store($rec, $tmp, 'video/mp4');

        return $rec->status === CallRecording::STORED ? 'stored' : 'store_failed';
    }

    // ------------------------------------------------------------------
    // Access + playback
    // ------------------------------------------------------------------

    public function canAccess(CallRecording $rec, ?User $user): bool
    {
        if (! $user || $user->status !== 'active') {
            return false;
        }
        if ($user->isSuperAdmin()) {
            return true;
        }
        $call = $rec->callLog;
        if (! $call || ! $user->hasPermission('call-recordings.play')) {
            return false;
        }
        if ($call->patient_user_id && (int) $call->patient_user_id === (int) $user->id) {
            return false; // patients see the call log, never the recording
        }
        if ($call->clinic_id && in_array((int) $call->clinic_id, $user->practiceClinicIds(), true)) {
            return true;
        }

        return $user->isDoctor() && $user->doctorProfile && (int) $call->doctor_profile_id === (int) $user->doctorProfile->id;
    }

    /** Short-lived signed link to our media route (which re-checks access and logs). */
    public function signedUrl(CallRecording $rec, bool $download = false): string
    {
        return URL::temporarySignedRoute('call-recordings.media', now()->addMinutes(10), ['recording' => $rec->id] + ($download ? ['download' => 1] : []));
    }

    public function log(CallRecording $rec, ?User $user, string $action): void
    {
        try {
            CallRecordingAccessLog::query()->create([
                'call_recording_id' => $rec->id, 'user_id' => $user?->id, 'action' => $action,
                'ip' => request()?->ip(), 'user_agent' => mb_substr((string) request()?->userAgent(), 0, 255), 'created_at' => now(),
            ]);
        } catch (\Throwable) {
        }
    }

    public function setHold(CallRecording $rec, User $user, bool $hold, ?string $reason = null): void
    {
        $rec->update(['legal_hold' => $hold, 'hold_reason' => $hold ? ($reason ? mb_substr($reason, 0, 255) : 'Legal hold') : null, 'held_by' => $hold ? $user->id : null, 'held_at' => $hold ? now() : null]);
        $this->log($rec, $user, $hold ? 'hold' : 'release');
    }

    public function deleteRecording(CallRecording $rec, ?User $user, string $why = 'delete'): bool
    {
        if ($rec->legal_hold) {
            return false;
        }
        if ($rec->path && $rec->disk) {
            try {
                if (! $this->storage->delete($rec->disk, $rec->path)) {
                    return false;
                }
            } catch (\Throwable $e) {
                Log::warning('[CallRecording] delete failed: '.$e->getMessage());

                return false;
            }
        }
        $rec->update(['status' => CallRecording::DELETED, 'deleted_at' => now()]);
        $this->log($rec, $user, $why);

        return true;
    }

    /** Retention: delete expired recordings that are not on legal hold. */
    public function purgeExpired(): int
    {
        $n = 0;
        CallRecording::query()->where('status', CallRecording::STORED)->where('legal_hold', false)
            ->whereNotNull('retention_until')->where('retention_until', '<=', now())->orderBy('id')->limit(50)->get()
            ->each(function (CallRecording $r) use (&$n) {
                if ($this->deleteRecording($r, null, 'retention')) {
                    $n++;
                }
            });
        // Abandoned client uploads (no final chunk within a day).
        CallRecording::query()->where('status', CallRecording::RECEIVING)->where('updated_at', '<=', now()->subDay())->limit(20)->get()
            ->each(fn (CallRecording $r) => $this->finaliseClient($r));

        return $n;
    }
}
