<?php

namespace App\Http\Controllers;

use App\Models\CallRecording;
use App\Services\PracticeCalls\CallRecordingService;
use App\Services\PracticeCalls\RecordingStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Call recording playback / download (signed, short-lived, access-checked
 * and logged on every hit) and the JaaS RECORDING_UPLOADED webhook.
 * Recordings are never publicly reachable: local files live on the private
 * disk; Bunny files are reached through a token-signed Pull Zone URL that
 * expires in minutes, or streamed through here.
 */
class CallRecordingController extends Controller
{
    public function __construct(protected CallRecordingService $recordings, protected RecordingStorage $storage) {}

    public function media(Request $request, int $recording)
    {
        abort_unless($request->hasValidSignature(), 403);
        $rec = CallRecording::query()->with('callLog')->find($recording);
        $user = $request->user();
        if (! $rec || ! $this->recordings->canAccess($rec, $user)) {
            if ($rec) {
                $this->recordings->log($rec, $user, 'denied');
            }
            abort(404);
        }
        abort_unless($rec->isPlayable(), 410, 'This recording is no longer available.');
        $download = $request->boolean('download');
        $this->recordings->log($rec, $user, $download ? 'download' : 'play');
        $name = 'call-'.($rec->callLog?->uuid ?? $rec->call_log_id).'-'.$rec->id.'.'.pathinfo((string) $rec->path, PATHINFO_EXTENSION);
        $headers = ['Content-Type' => $rec->mime ?: 'application/octet-stream', 'Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex'];
        if ($download) {
            $headers['Content-Disposition'] = 'attachment; filename="'.$name.'"';
        }

        if ($rec->disk === 'bunny') {
            if (! $download && ($cdn = $this->storage->signedCdnUrl((string) $rec->path))) {
                return redirect()->away($cdn);
            }
            $res = $this->storage->fetch((string) $rec->path);
            abort_unless($res->successful(), 502, 'Recording storage is unavailable.');

            return response($res->body(), 200, $headers);
        }
        abort_unless(Storage::disk('local')->exists((string) $rec->path), 410);

        return response()->stream(function () use ($rec) {
            $s = Storage::disk('local')->readStream((string) $rec->path);
            fpassthru($s);
            fclose($s);
        }, 200, $headers + ['Content-Length' => (string) Storage::disk('local')->size((string) $rec->path)]);
    }

    /** POST /api/webhooks/jaas — signed by JaaS (X-Jaas-Signature), configured in Staff → Recording storage. */
    public function jaasWebhook(Request $request)
    {
        $raw = (string) $request->getContent();
        if (! $this->recordings->verifyJaas($raw, $request->header('X-Jaas-Signature'))) {
            return response()->json(['ok' => false], 401);
        }
        try {
            $status = $this->recordings->handleJaas((array) json_decode($raw, true));
        } catch (\Throwable $e) {
            Log::warning('[JaaS webhook] '.$e->getMessage());
            $status = 'error';
        }

        return response()->json(['ok' => true, 'status' => $status]);
    }
}
