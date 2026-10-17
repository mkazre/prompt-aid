<?php

namespace App\Services\Safety;

use App\Contracts\NotificationDispatcherInterface;
use App\Models\Ride;
use App\Models\RideRecording;
use App\Models\RideRecordingAccessLog;
use App\Models\RideStatusEvent;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\RideRecordingSettings;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Trip audio recordings — an extra safety layer for shuttle rides.
 *
 * WHO: only the ride's patient or its assigned driver can record, and only
 * while the ride is live (accepted → completed/cancelled + GRACE_MINUTES).
 * Both parties can play/download ALL recordings of their ride (both sides);
 * staff need the `ride-recordings.view` permission (super admins always).
 * Every play/download/hold/release/delete is written to
 * ride_recording_access_logs.
 *
 * UPLOAD: the client records in ~30 s pieces and uploads each as soon as it
 * is written, so audio already captured survives if the phone is taken.
 *   - app (expo-audio): each piece is a standalone .m4a  → layout "segments"
 *   - web (MediaRecorder timeslice): pieces continue one file → layout "stream"
 *
 * ENCRYPTION AT REST: each piece is encrypted with Laravel's Crypt
 * (Crypt::encryptString → AES-256-CBC + HMAC-SHA256 using APP_KEY) and
 * written to the PRIVATE `local` disk as
 *   ride-recordings/{ride}/{recording}/{seq}.enc
 * Pieces are decrypted one at a time when streamed. Rotating APP_KEY makes
 * existing recordings unreadable unless the old key is kept in
 * APP_PREVIOUS_KEYS (Laravel 11+ tries those keys when decrypting).
 *
 * INTEGRITY: the manifest keeps a SHA-256 per piece (of the plaintext) and
 * `sha256` on the recording is the SHA-256 of all pieces' plaintext
 * concatenated in sequence order, computed on completion. Downloads include
 * the manifest so the hash can be re-checked independently.
 *
 * RETENTION: expires_at = ended_at + ride_recording_retention_days (default
 * 3). The Tick job `safety.ride_recordings` (every 10 min) auto-holds
 * recordings of rides with an SOS or an open shuttle support ticket from
 * either party, then deletes the files of expired, un-held recordings and
 * soft-deletes the row (a metadata tombstone: times, hash, access log),
 * which is hard-deleted after TOMBSTONE_DAYS.
 */
class RideRecordingService
{
    public const TOMBSTONE_DAYS = 30;

    public const SIGNED_URL_MINUTES = 15;

    public function __construct(protected NotificationDispatcherInterface $notifier) {}

    // ------------------------------------------------------------------
    // Authorisation
    // ------------------------------------------------------------------

    /** 'patient' | 'driver' | null — the user's part in this ride. */
    public function partyRole(User $user, Ride $ride): ?string
    {
        $pid = $user->patientProfile?->id;
        if ($pid && (int) $ride->patient_profile_id === (int) $pid) {
            return 'patient';
        }
        $did = $user->driverProfile?->id;
        if ($did && $ride->driver_profile_id && (int) $ride->driver_profile_id === (int) $did) {
            return 'driver';
        }

        return null;
    }

    public static function isStaffViewer(?User $user): bool
    {
        return (bool) $user && ($user->isSuperAdmin() || $user->hasPermission('ride-recordings.view'));
    }

    public static function isStaffManager(?User $user): bool
    {
        return (bool) $user && ($user->isSuperAdmin() || $user->hasPermission('ride-recordings.manage'));
    }

    public function canAccess(User $user, Ride $ride): bool
    {
        return $this->partyRole($user, $ride) !== null || self::isStaffViewer($user);
    }

    /** When the recording window for this ride closes (null = still open-ended / not started). */
    public function windowEndsAt(Ride $ride): ?\Carbon\CarbonInterface
    {
        $ended = $ride->completed_at ?? $ride->cancelled_at;

        return $ended?->copy()->addMinutes(RideRecordingSettings::GRACE_MINUTES);
    }

    /** May a NEW recording be started on this ride right now? */
    public function windowOpen(Ride $ride): bool
    {
        if (! $ride->driver_profile_id) {
            return false;
        }
        if (in_array($ride->status, Ride::DRIVER_ACTIVE_STATUSES, true)) {
            return true;
        }
        if (in_array($ride->status, [Ride::STATUS_COMPLETED, Ride::STATUS_CANCELLED], true) && $ride->accepted_at) {
            $end = $this->windowEndsAt($ride);

            return $end !== null && now()->lte($end);
        }

        return false;
    }

    /** Why the user cannot record (null = they can). */
    public function cannotRecordReason(User $user, Ride $ride): ?string
    {
        $role = $this->partyRole($user, $ride);
        if (! $role) {
            return 'Only the patient and the assigned driver can record this trip.';
        }
        if (! RideRecordingSettings::enabledFor($role)) {
            return 'Trip recording is switched off for '.($role === 'driver' ? 'drivers' : 'patients').'.';
        }
        if (! $this->windowOpen($ride)) {
            return 'Recording is only available while the trip is active (from driver acceptance until shortly after drop-off).';
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Recording lifecycle
    // ------------------------------------------------------------------

    public function start(Ride $ride, User $user, string $mime, string $source, string $layout): RideRecording
    {
        $role = $this->partyRole($user, $ride);

        // One live recording per person per ride: close any previous one first.
        RideRecording::query()->where('ride_id', $ride->id)->where('user_id', $user->id)
            ->where('status', RideRecording::STATUS_UPLOADING)->get()
            ->each(fn (RideRecording $r) => $this->complete($r, 'restarted'));

        $rec = RideRecording::query()->create([
            'ride_id' => $ride->id,
            'user_id' => $user->id,
            'role' => $role,
            'source' => $source,
            'layout' => $layout,
            'disk' => 'local',
            'mime' => $mime,
            'segments' => [],
            'started_at' => now(),
            'consent_at' => now(),
            'status' => RideRecording::STATUS_UPLOADING,
            'expires_at' => now()->addDays(RideRecordingSettings::retentionDays()),
        ]);
        $rec->update(['path' => "ride-recordings/{$ride->id}/{$rec->id}"]);

        $this->logRideEvent($ride, 'recording_started', ucfirst((string) $role).' started trip audio recording');
        $this->notifyOtherParty($ride, (string) $role);

        return $rec;
    }

    /**
     * Store one piece. Idempotent per sequence number (a retried upload of
     * the same piece is accepted; a different piece with the same number is
     * rejected).
     *
     * @return array{stored: bool, duplicate: bool, limit_reached: bool}
     *
     * @throws RecordingException
     */
    public function addChunk(RideRecording $rec, UploadedFile $file, int $seq, int $durationMs, ?string $clientSha): array
    {
        if ($rec->status === RideRecording::STATUS_FAILED) {
            throw new RecordingException('This recording was closed.', 409);
        }
        $lateUntil = ($rec->started_at ?? $rec->created_at)->copy()
            ->addMinutes(RideRecordingSettings::maxMinutes() + RideRecordingSettings::LATE_UPLOAD_MINUTES);
        if (now()->gt($lateUntil)) {
            throw new RecordingException('The upload window for this recording has closed.', 410);
        }

        $chunkSeconds = RideRecordingSettings::chunkSeconds();
        $maxSeq = (int) ceil(RideRecordingSettings::maxMinutes() * 60 / $chunkSeconds) * 2 + 10;
        if ($seq < 0 || $seq > $maxSeq) {
            throw new RecordingException('Invalid piece number.', 422);
        }

        $size = (int) $file->getSize();
        if ($size <= 0 || $size > RideRecordingSettings::MAX_CHUNK_BYTES) {
            throw new RecordingException('Audio piece is empty or too large.', 413);
        }

        $bytes = (string) file_get_contents($file->getRealPath());
        $sha = hash('sha256', $bytes);
        if ($clientSha !== null && $clientSha !== '' && ! hash_equals(strtolower($clientSha), $sha)) {
            throw new RecordingException('Audio piece was damaged in transit — please retry.', 422);
        }

        // Standalone pieces (and the first piece of a stream) must look like audio of the declared type.
        if (($rec->layout === RideRecording::LAYOUT_SEGMENTS || $seq === 0) && ! $this->looksLikeAudio($bytes, $rec->mime)) {
            throw new RecordingException('That file is not a supported audio recording.', 415);
        }

        // A piece can be much longer than chunk_seconds if the phone throttled timers in the background.
        $durationMs = max(0, min($durationMs, 1800 * 1000));
        $relative = $rec->path.'/'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT).'.enc';

        return DB::transaction(function () use ($rec, $seq, $bytes, $sha, $size, $durationMs, $relative) {
            /** @var RideRecording $locked */
            $locked = RideRecording::query()->lockForUpdate()->findOrFail($rec->id);
            $segments = $locked->segments ?? [];

            foreach ($segments as $s) {
                if ((int) $s['seq'] === $seq) {
                    if (hash_equals((string) $s['sha256'], $sha)) {
                        return ['stored' => false, 'duplicate' => true, 'limit_reached' => false];
                    }
                    throw new RecordingException('A different audio piece with this number was already received.', 409);
                }
            }

            $totalMs = array_sum(array_map(fn ($s) => (int) ($s['duration_ms'] ?? 0), $segments)) + $durationMs;
            $capMs = (RideRecordingSettings::maxMinutes() * 60 + RideRecordingSettings::chunkSeconds()) * 1000;
            if ($totalMs > $capMs || $locked->size_bytes + $size > RideRecordingSettings::MAX_RECORDING_BYTES) {
                throw new RecordingException('Maximum recording length reached.', 422);
            }

            Storage::disk($locked->disk)->put($relative, Crypt::encryptString($bytes));

            $segments[] = ['seq' => $seq, 'file' => $relative, 'bytes' => $size, 'sha256' => $sha, 'duration_ms' => $durationMs, 'at' => now()->toIso8601String()];
            $locked->forceFill([
                'segments' => $segments,
                'chunk_count' => count($segments),
                'size_bytes' => $locked->size_bytes + $size,
                'duration_seconds' => (int) round($totalMs / 1000),
                'last_chunk_at' => now(),
            ])->save();

            // A late piece for an already-completed recording: refresh the hash.
            if ($locked->status === RideRecording::STATUS_COMPLETE) {
                $locked->forceFill(['sha256' => $this->computeHash($locked)])->save();
            }

            $limit = $totalMs >= RideRecordingSettings::maxMinutes() * 60 * 1000;

            return ['stored' => true, 'duplicate' => false, 'limit_reached' => $limit];
        });
    }

    public function complete(RideRecording $rec, string $reason = 'stopped'): RideRecording
    {
        $rec->refresh();
        if ($rec->status !== RideRecording::STATUS_UPLOADING) {
            return $rec;
        }

        $ended = $rec->last_chunk_at ?? now();
        $hasAudio = (int) $rec->chunk_count > 0;
        $rec->forceFill([
            'status' => $hasAudio ? RideRecording::STATUS_COMPLETE : RideRecording::STATUS_FAILED,
            'end_reason' => mb_substr($reason, 0, 40),
            'ended_at' => $ended,
            'sha256' => $hasAudio ? $this->computeHash($rec) : null,
            'expires_at' => $ended->copy()->addDays(RideRecordingSettings::retentionDays()),
        ])->save();

        if ($rec->ride) {
            $this->logRideEvent($rec->ride, 'recording_stopped', ucfirst($rec->role).' stopped trip audio recording ('.$rec->durationLabel().')');
        }

        return $rec;
    }

    public function computeHash(RideRecording $rec): ?string
    {
        $ctx = hash_init('sha256');
        $disk = Storage::disk($rec->disk);
        foreach ($rec->orderedSegments() as $s) {
            try {
                hash_update($ctx, Crypt::decryptString((string) $disk->get($s['file'])));
            } catch (\Throwable $e) {
                Log::error('[RideRecording] hash: piece unreadable', ['recording' => $rec->id, 'seq' => $s['seq'] ?? null, 'error' => $e->getMessage()]);

                return null;
            }
        }

        return hash_final($ctx);
    }

    // ------------------------------------------------------------------
    // Presentation
    // ------------------------------------------------------------------

    /** Live-status block merged into RideStatusPresenter::forPatient(). */
    public function liveStatus(Ride $ride): array
    {
        try {
            $live = RideRecording::query()->where('ride_id', $ride->id)
                ->where('status', RideRecording::STATUS_UPLOADING)->get()
                ->filter(fn (RideRecording $r) => $r->isLive());
        } catch (\Throwable $e) {
            return ['recording_active' => false, 'recorded_by' => []];
        }

        return [
            'recording_active' => $live->isNotEmpty(),
            'recorded_by' => $live->pluck('role')->unique()->values()->all(),
        ];
    }

    /** Full JSON for the in-app/web recorder + recordings list. */
    public function rideSummary(Ride $ride, User $user): array
    {
        $role = $this->partyRole($user, $ride);
        $reason = $role ? $this->cannotRecordReason($user, $ride) : 'Not a party to this trip.';
        $mine = $role ? RideRecording::query()->where('ride_id', $ride->id)->where('user_id', $user->id)
            ->where('status', RideRecording::STATUS_UPLOADING)->latest('id')->first() : null;

        return $this->liveStatus($ride) + [
            'ride_id' => $ride->id,
            'ride_ref' => $ride->ride_ref,
            'ride_status' => $ride->status,
            'my_role' => $role,
            'can_record' => $reason === null,
            'cannot_record_reason' => $reason,
            'my_active_recording_id' => $mine?->id,
            'my_next_seq' => $mine ? (int) (collect($mine->segments ?? [])->max('seq') ?? -1) + 1 : 0,
            'settings' => [
                'chunk_seconds' => RideRecordingSettings::chunkSeconds(),
                'max_minutes' => RideRecordingSettings::maxMinutes(),
                'retention_days' => RideRecordingSettings::retentionDays(),
                'max_chunk_bytes' => RideRecordingSettings::MAX_CHUNK_BYTES,
            ],
            'recordings' => RideRecording::query()->where('ride_id', $ride->id)
                ->with('user:id,name')->orderBy('started_at')->get()
                ->map(fn (RideRecording $r) => $this->present($r, $user))->values()->all(),
        ];
    }

    public function present(RideRecording $r, User $viewer): array
    {
        $segments = $r->orderedSegments();
        $playable = $r->chunk_count > 0;

        return [
            'id' => $r->id,
            'role' => $r->role,
            'recorded_by_me' => (int) $r->user_id === (int) $viewer->id,
            'recorded_by_name' => $r->user?->name ? explode(' ', trim($r->user->name))[0] : ucfirst($r->role),
            'status' => $r->status,
            'is_live' => $r->isLive(),
            'layout' => $r->layout,
            'mime' => $r->servedMime(),
            'started_at' => $r->started_at?->toIso8601String(),
            'ended_at' => $r->ended_at?->toIso8601String(),
            'duration_seconds' => (int) $r->duration_seconds,
            'size_bytes' => (int) $r->size_bytes,
            'sha256' => $r->sha256,
            'expires_at' => $r->legal_hold ? null : $r->expires_at?->toIso8601String(),
            'legal_hold' => (bool) $r->legal_hold,
            // Signed, short-lived URLs (no auth header needed by audio players).
            'play_url' => $playable && $r->layout === RideRecording::LAYOUT_STREAM ? $this->signedUrl($r, $viewer, null, false) : null,
            'segments' => $playable && $r->layout === RideRecording::LAYOUT_SEGMENTS
                ? array_map(fn ($s) => [
                    'seq' => (int) $s['seq'],
                    'duration_ms' => (int) ($s['duration_ms'] ?? 0),
                    'url' => $this->signedUrl($r, $viewer, (int) $s['seq'], false),
                ], $segments)
                : [],
            'download_url' => $playable ? $this->signedUrl($r, $viewer, null, true) : null,
            'urls_expire_at' => now()->addMinutes(self::SIGNED_URL_MINUTES)->toIso8601String(),
        ];
    }

    public function signedUrl(RideRecording $r, User $viewer, ?int $seq, bool $download): string
    {
        $params = ['recording' => $r->id, 'u' => $viewer->id];
        if ($seq !== null) {
            $params['seg'] = $seq;
        }
        if ($download) {
            $params['dl'] = 1;
        }

        return URL::temporarySignedRoute('ride-recordings.media', now()->addMinutes(self::SIGNED_URL_MINUTES), $params);
    }

    // ------------------------------------------------------------------
    // Streaming (decrypt on the fly, HTTP Range aware)
    // ------------------------------------------------------------------

    public function mediaResponse(RideRecording $rec, ?int $seq, bool $download, Request $request): Response
    {
        $segments = $rec->orderedSegments();
        if ($seq !== null) {
            $segments = array_values(array_filter($segments, fn ($s) => (int) $s['seq'] === $seq));
        }
        abort_if($segments === [], 404);

        $name = 'trip-'.($rec->ride?->ride_ref ?? $rec->ride_id).'-'.$rec->role.'-'.$rec->id;

        // Standalone pieces downloaded together → zip with the evidence manifest.
        if ($download && $seq === null && $rec->layout === RideRecording::LAYOUT_SEGMENTS && count($segments) > 1) {
            return $this->zipResponse($rec, $segments, $name);
        }

        $total = array_sum(array_map(fn ($s) => (int) $s['bytes'], $segments));
        [$start, $end, $partial] = $this->parseRange((string) $request->header('Range', ''), $total);
        if ($start === null) {
            return response('', 416, ['Content-Range' => "bytes */{$total}"]);
        }

        $headers = [
            'Content-Type' => $rec->servedMime(),
            'Accept-Ranges' => 'bytes',
            'Content-Length' => (string) ($end - $start + 1),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];
        if ($partial) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$total}";
        }
        $filename = $name.($seq !== null ? '-part'.($seq + 1) : '').'.'.$rec->fileExtension();
        $headers['Content-Disposition'] = ($download ? 'attachment' : 'inline').'; filename="'.$filename.'"';
        if ($download && $rec->sha256 && $seq === null) {
            $headers['X-Recording-SHA256'] = $rec->sha256;
        }

        $disk = Storage::disk($rec->disk);

        return new StreamedResponse(function () use ($segments, $disk, $start, $end) {
            $offset = 0;
            foreach ($segments as $s) {
                $len = (int) $s['bytes'];
                $segStart = $offset;
                $segEnd = $offset + $len - 1;
                $offset += $len;
                if ($segEnd < $start || $segStart > $end) {
                    continue;
                }
                $plain = Crypt::decryptString((string) $disk->get($s['file']));
                $from = max(0, $start - $segStart);
                $to = min($len - 1, $end - $segStart);
                echo substr($plain, $from, $to - $from + 1);
                unset($plain);
                if (function_exists('flush')) {
                    flush();
                }
            }
        }, $partial ? 206 : 200, $headers);
    }

    /** @return array{0: int|null, 1: int, 2: bool} [start, end, isPartial] */
    protected function parseRange(string $header, int $total): array
    {
        if ($total <= 0) {
            return [null, 0, false];
        }
        if (! preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $m)) {
            return [0, $total - 1, false];
        }
        if ($m[1] === '' && $m[2] === '') {
            return [0, $total - 1, false];
        }
        if ($m[1] === '') { // suffix range: last N bytes
            $n = (int) $m[2];

            return $n <= 0 ? [null, 0, false] : [max(0, $total - $n), $total - 1, true];
        }
        $start = (int) $m[1];
        $end = $m[2] === '' ? $total - 1 : min((int) $m[2], $total - 1);
        if ($start >= $total || $start > $end) {
            return [null, 0, false];
        }

        return [$start, $end, true];
    }

    protected function zipResponse(RideRecording $rec, array $segments, string $name): Response
    {
        abort_unless(class_exists(\ZipArchive::class), 501, 'ZIP support is not installed on this server; download the parts individually.');

        $tmp = tempnam(sys_get_temp_dir(), 'rrz');
        $zip = new \ZipArchive;
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $disk = Storage::disk($rec->disk);
        $manifest = [
            'recording_id' => $rec->id,
            'ride_ref' => $rec->ride?->ride_ref,
            'recorded_by_role' => $rec->role,
            'started_at' => $rec->started_at?->toIso8601String(),
            'ended_at' => $rec->ended_at?->toIso8601String(),
            'duration_seconds' => (int) $rec->duration_seconds,
            'sha256_all_parts_concatenated' => $rec->sha256,
            'note' => 'Each part is a standalone audio file recorded back-to-back. sha256_all_parts_concatenated is the SHA-256 of all parts\' bytes joined in order.',
            'parts' => [],
        ];
        foreach ($segments as $i => $s) {
            $file = sprintf('part-%03d.%s', $i + 1, $rec->fileExtension());
            $zip->addFromString($file, Crypt::decryptString((string) $disk->get($s['file'])));
            $manifest['parts'][] = ['file' => $file, 'seq' => (int) $s['seq'], 'bytes' => (int) $s['bytes'], 'sha256' => $s['sha256'], 'duration_ms' => (int) ($s['duration_ms'] ?? 0), 'received_at' => $s['at'] ?? null];
        }
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $zip->close();

        return response()->download($tmp, $name.'.zip', ['Content-Type' => 'application/zip', 'Cache-Control' => 'private, no-store'])->deleteFileAfterSend(true);
    }

    protected function looksLikeAudio(string $bytes, string $mime): bool
    {
        $head = substr($bytes, 0, 12);

        return match ($mime) {
            'audio/webm' => str_starts_with($head, "\x1A\x45\xDF\xA3"),
            'audio/ogg' => str_starts_with($head, 'OggS'),
            'audio/m4a' => substr($head, 4, 4) === 'ftyp' || substr($head, 4, 4) === 'moov' || substr($head, 4, 4) === 'mdat' || substr($head, 4, 4) === 'free',
            'audio/aac' => strlen($head) >= 2 && ord($head[0]) === 0xFF && (ord($head[1]) & 0xF6) === 0xF0,
            'audio/mpeg' => str_starts_with($head, 'ID3') || (strlen($head) >= 2 && ord($head[0]) === 0xFF && (ord($head[1]) & 0xE0) === 0xE0),
            default => false,
        };
    }

    // ------------------------------------------------------------------
    // Holds, deletion, retention
    // ------------------------------------------------------------------

    public function logAccess(RideRecording $rec, ?User $user, string $action, ?string $detail = null, ?Request $request = null): void
    {
        try {
            $role = null;
            if ($user) {
                $role = $rec->ride ? $this->partyRole($user, $rec->ride) : null;
                $role ??= self::isStaffViewer($user) ? 'admin' : $user->role;
            }
            RideRecordingAccessLog::query()->create([
                'ride_recording_id' => $rec->id,
                'user_id' => $user?->id,
                'actor_role' => $role,
                'action' => $action,
                'detail' => $detail !== null ? mb_substr($detail, 0, 255) : null,
                'ip' => $request?->ip(),
                'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[RideRecording] access log failed: '.$e->getMessage());
        }
    }

    /** Log a play/download once per user+recording+part per 10 minutes (players issue many Range requests). */
    public function logMediaAccess(RideRecording $rec, ?User $user, bool $download, ?int $seq, Request $request): void
    {
        $action = $download ? 'download' : 'play';
        $key = 'rrlog:'.$rec->id.':'.($user?->id ?? 0).':'.$action.':'.($seq ?? 'all');
        if (! Cache::add($key, 1, 600)) {
            return;
        }
        $this->logAccess($rec, $user, $action, $seq !== null ? 'part '.($seq + 1) : null, $request);
    }

    public function hold(RideRecording $rec, ?User $by, string $reason, string $source = 'admin'): void
    {
        $rec->forceFill([
            'legal_hold' => true,
            'hold_reason' => mb_substr($reason, 0, 255),
            'hold_source' => $source,
            'held_by' => $by?->id,
            'held_at' => now(),
            'hold_released_at' => null,
            'hold_released_by' => null,
        ])->save();
        $this->logAccess($rec, $by, 'hold', $reason);
    }

    public function release(RideRecording $rec, ?User $by, ?string $note = null): void
    {
        $base = $rec->ended_at ?? $rec->started_at ?? now();
        $rec->forceFill([
            'legal_hold' => false,
            'hold_released_at' => now(),
            'hold_released_by' => $by?->id,
            // Retention restarts from now so a just-released recording isn't purged before anyone reviews it.
            'expires_at' => max($base->copy()->addDays(RideRecordingSettings::retentionDays()), now()->addDay()),
        ])->save();
        $this->logAccess($rec, $by, 'release', $note);
    }

    /** Delete audio files and soft-delete the row (metadata tombstone). Refuses held recordings. */
    public function deleteRecording(RideRecording $rec, ?User $by, string $action = 'delete', ?string $detail = null): bool
    {
        if ($rec->legal_hold) {
            return false;
        }
        $this->deleteFiles($rec);
        $this->logAccess($rec, $by, $action, $detail);
        $rec->forceFill(['segments' => [], 'status' => $rec->status === RideRecording::STATUS_UPLOADING ? RideRecording::STATUS_FAILED : $rec->status])->save();
        $rec->delete();

        return true;
    }

    protected function deleteFiles(RideRecording $rec): void
    {
        try {
            $disk = Storage::disk($rec->disk);
            if ($rec->path) {
                $disk->deleteDirectory($rec->path);
            }
            foreach ($rec->orderedSegments() as $s) {
                if (! empty($s['file']) && $disk->exists($s['file'])) {
                    $disk->delete($s['file']);
                }
            }
        } catch (\Throwable $e) {
            Log::error('[RideRecording] file delete failed: '.$e->getMessage(), ['recording' => $rec->id]);
        }
    }

    /** Re-base every un-held recording's expiry on the current retention setting. */
    public function recomputeExpiry(): int
    {
        $days = RideRecordingSettings::retentionDays();
        $n = 0;
        RideRecording::query()->where('legal_hold', false)->whereNotNull('ended_at')
            ->chunkById(200, function ($rows) use ($days, &$n) {
                foreach ($rows as $r) {
                    $exp = $r->ended_at->copy()->addDays($days);
                    if ($r->hold_released_at && $r->hold_released_at->copy()->addDay()->gt($exp)) {
                        $exp = $r->hold_released_at->copy()->addDay();
                    }
                    $r->forceFill(['expires_at' => $exp])->save();
                    $n++;
                }
            });

        return $n;
    }

    /** Tick job: finalise stale uploads, apply auto-holds, purge expired, drop old tombstones. */
    public function runMaintenance(): array
    {
        $out = ['finalised' => 0, 'held' => 0, 'purged' => 0, 'tombstones' => 0];

        // 1. Recordings whose uploader went quiet (phone died / app killed / ride over).
        RideRecording::query()->where('status', RideRecording::STATUS_UPLOADING)
            ->where(fn ($q) => $q->where('last_chunk_at', '<', now()->subMinutes(30))
                ->orWhere(fn ($q2) => $q2->whereNull('last_chunk_at')->where('started_at', '<', now()->subMinutes(30))))
            ->get()->each(function (RideRecording $r) use (&$out) {
                $this->complete($r, 'uploader_went_quiet');
                $out['finalised']++;
            });

        // 2. Auto-holds (SOS / open shuttle complaint). Never re-holds one an admin released.
        if (RideRecordingSettings::bool('ride_recording_auto_hold')) {
            $out['held'] = $this->applyAutoHolds();
        }

        // 3. Purge expired, un-held recordings.
        RideRecording::query()->where('legal_hold', false)->where('status', '!=', RideRecording::STATUS_UPLOADING)
            ->whereNotNull('expires_at')->where('expires_at', '<=', now())
            ->limit(500)->get()->each(function (RideRecording $r) use (&$out) {
                if ($this->deleteRecording($r, null, 'purge', 'Retention period ended')) {
                    $out['purged']++;
                }
            });

        // 4. Hard-delete old tombstones (and their access logs, by cascade).
        $out['tombstones'] = RideRecording::onlyTrashed()->where('deleted_at', '<', now()->subDays(self::TOMBSTONE_DAYS))->forceDelete();

        return $out;
    }

    public function applyAutoHolds(): int
    {
        $held = 0;
        RideRecording::query()->where('legal_hold', false)->whereNull('hold_released_at')
            ->with('ride.patient.user', 'ride.driver.user')->get()
            ->each(function (RideRecording $r) use (&$held) {
                $reason = $r->ride ? $this->autoHoldReason($r->ride) : null;
                if ($reason) {
                    $this->hold($r, null, $reason, 'auto');
                    $held++;
                }
            });

        return $held;
    }

    public function autoHoldReason(Ride $ride): ?string
    {
        $sos = RideStatusEvent::query()->where('ride_id', $ride->id)->where('status', 'like', 'sos%')->exists();
        if ($sos) {
            return 'Auto: SOS raised on this ride';
        }

        if (class_exists(SupportTicket::class)) {
            $userIds = array_filter([$ride->patient?->user_id, $ride->driver?->user_id]);
            if ($userIds) {
                try {
                    $ticket = SupportTicket::query()->whereIn('user_id', $userIds)
                        ->where('category', 'shuttle')
                        ->whereIn('status', [SupportTicket::STATUS_OPEN, SupportTicket::STATUS_AWAITING_REPLY])
                        ->where('created_at', '>=', $ride->requested_at ?? $ride->created_at)
                        ->orderBy('id')->first();
                    if ($ticket) {
                        return 'Auto: open shuttle support ticket '.$ticket->reference;
                    }
                } catch (\Throwable $e) {
                    // support tables missing — no ticket-based holds
                }
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // POPIA (privacy export / anonymisation)
    // ------------------------------------------------------------------

    /** Metadata (not audio) for the data-subject export. */
    public function exportFor(User $user): array
    {
        $pid = $user->patientProfile?->id;
        $did = $user->driverProfile?->id;

        return RideRecording::query()
            ->where(function ($q) use ($user, $pid, $did) {
                $q->where('user_id', $user->id);
                if ($pid) {
                    $q->orWhereHas('ride', fn ($r) => $r->where('patient_profile_id', $pid));
                }
                if ($did) {
                    $q->orWhereHas('ride', fn ($r) => $r->where('driver_profile_id', $did));
                }
            })
            ->with('ride:id,ride_ref')->orderBy('started_at')->get()
            ->map(fn (RideRecording $r) => [
                'ride_ref' => $r->ride?->ride_ref,
                'recorded_by' => $r->role,
                'started_at' => (string) $r->started_at,
                'ended_at' => (string) $r->ended_at,
                'duration_seconds' => (int) $r->duration_seconds,
                'sha256' => $r->sha256,
                'deleted_after' => $r->legal_hold ? 'on legal hold' : (string) $r->expires_at,
                'how_to_get_audio' => 'Play or download from the trip in the app or on the website while it is kept.',
            ])->all();
    }

    /**
     * Delete the person's trip recordings for anonymisation (their own
     * recordings and any recording of trips where they were the patient).
     * Recordings on legal hold are kept.
     *
     * @return array{deleted: int, kept: int}
     */
    public function purgeForUser(int $userId, ?int $patientProfileId): array
    {
        $deleted = 0;
        $kept = 0;
        RideRecording::query()
            ->where(function ($q) use ($userId, $patientProfileId) {
                $q->where('user_id', $userId);
                if ($patientProfileId) {
                    $q->orWhereHas('ride', fn ($r) => $r->where('patient_profile_id', $patientProfileId));
                }
            })->get()->each(function (RideRecording $r) use (&$deleted, &$kept) {
                $this->deleteRecording($r, null, 'purge', 'Account anonymised (POPIA)') ? $deleted++ : $kept++;
            });

        return ['deleted' => $deleted, 'kept' => $kept];
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    protected function notifyOtherParty(Ride $ride, string $role): void
    {
        if (! Cache::add('rrnotify:'.$ride->id.':'.$role, 1, 600)) {
            return;
        }
        try {
            $ride->loadMissing('patient.user', 'driver.user');
            $title = 'Trip audio is being recorded for safety';
            if ($role === 'patient') {
                $user = $ride->driver?->user;
                $body = 'The patient started an audio recording of trip '.$ride->ride_ref.' as a safety measure. You and the patient can both play it from the trip.';
                $data = ['type' => 'ride', 'data' => ['route' => 'driver_ride', 'params' => ['ride_id' => $ride->id], 'url' => '/driver']];
            } else {
                $user = $ride->patient?->user;
                $body = 'Your driver started an audio recording of trip '.$ride->ride_ref.' as a safety measure. You and the driver can both play it from the trip.';
                $data = ['type' => 'ride', 'data' => ['route' => 'ride', 'params' => ['ride_id' => $ride->id], 'url' => '/rides/'.$ride->id.'/track']];
            }
            if ($user) {
                $this->notifier->push($user, $title, $body, $data);
            }
        } catch (\Throwable $e) {
            Log::warning('[RideRecording] other-party notice failed: '.$e->getMessage());
        }
    }

    protected function logRideEvent(Ride $ride, string $status, string $note): void
    {
        try {
            RideStatusEvent::query()->create(['ride_id' => $ride->id, 'status' => mb_substr($status.' | '.$note, 0, 255), 'lat' => null, 'lng' => null]);
        } catch (\Throwable $e) {
            Log::warning('[RideRecording] ride event failed: '.$e->getMessage());
        }
    }
}
