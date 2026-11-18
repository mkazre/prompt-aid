<?php

namespace App\Services\PracticeCalls;

use App\Support\PracticeCallSettings as S;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Where call recordings live: Bunny.net Storage (preferred) or the private
 * local disk (storage/app/private, never web-reachable) when Bunny is not
 * configured.
 *
 * Bunny Storage HTTP API:
 *   PUT    https://{region.}storage.bunnycdn.com/{zone}/{path}   header AccessKey: <storage zone password>
 *   GET    same URL (download)        DELETE same URL
 * Playback: a short-lived token-authenticated Pull Zone URL when a Pull Zone
 * + token key are set (basic token auth: base64url(sha256(key + path + expires))),
 * otherwise our server streams the file from the Storage API. Either way the
 * caller is our signed, access-checked, logged /call-recordings/{id}/media route.
 */
class RecordingStorage
{
    public function bunnyConfigured(): bool
    {
        return S::str('bunny_storage_zone') !== '' && S::secret('bunny_storage_key') !== '';
    }

    /** 'bunny' | 'local' */
    public function driver(): string
    {
        $want = S::str('recording_storage_driver');
        if ($want === 'local') {
            return 'local';
        }

        return $this->bunnyConfigured() ? 'bunny' : 'local';
    }

    public function host(): string
    {
        $h = trim(preg_replace('#^https?://#i', '', S::str('bunny_storage_hostname')), '/');
        if ($h !== '') {
            return $h;
        }
        $r = S::str('bunny_storage_region');

        return ($r !== '' && $r !== 'de' ? $r.'.' : '').'storage.bunnycdn.com';
    }

    public function objectUrl(string $path): string
    {
        $segments = array_map('rawurlencode', explode('/', ltrim($path, '/')));

        return 'https://'.$this->host().'/'.rawurlencode(S::str('bunny_storage_zone')).'/'.implode('/', $segments);
    }

    /** Store a local file; returns [disk, path]. */
    public function put(string $localFile, string $path, string $mime = 'application/octet-stream'): array
    {
        $prefix = trim(S::str('bunny_path_prefix') ?: 'call-recordings', '/');
        $path = $prefix.'/'.ltrim($path, '/');
        if ($this->driver() === 'bunny') {
            $res = Http::withHeaders(['AccessKey' => S::secret('bunny_storage_key'), 'Content-Type' => 'application/octet-stream'])
                ->timeout(300)
                ->withBody(file_get_contents($localFile), $mime)
                ->put($this->objectUrl($path));
            if (! $res->successful()) {
                throw new \RuntimeException('Bunny Storage upload failed (HTTP '.$res->status().').');
            }

            return ['bunny', $path];
        }
        $stream = fopen($localFile, 'rb');
        Storage::disk('local')->put($path, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        return ['local', $path];
    }

    public function delete(string $disk, string $path): bool
    {
        if ($disk === 'bunny') {
            $res = Http::withHeaders(['AccessKey' => S::secret('bunny_storage_key')])->timeout(60)->delete($this->objectUrl($path));

            return $res->successful() || $res->status() === 404;
        }

        return Storage::disk('local')->delete($path) || ! Storage::disk('local')->exists($path);
    }

    /** Signed short-lived Pull Zone URL (null when no Pull Zone / token key). */
    public function signedCdnUrl(string $path): ?string
    {
        $base = rtrim(S::str('bunny_pull_zone_url'), '/');
        $key = S::secret('bunny_token_key');
        if ($base === '' || $key === '') {
            return null;
        }
        if (! preg_match('#^https?://#i', $base)) {
            $base = 'https://'.$base;
        }
        $urlPath = '/'.implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
        $expires = time() + max(1, S::int('bunny_signed_url_minutes') ?: 10) * 60;
        $token = rtrim(strtr(base64_encode(hash('sha256', $key.$urlPath.$expires, true)), '+/', '-_'), '=');

        return $base.$urlPath.'?token='.$token.'&expires='.$expires;
    }

    /** Server-side download from Bunny Storage (streaming fallback). */
    public function fetch(string $path): \Illuminate\Http\Client\Response
    {
        return Http::withHeaders(['AccessKey' => S::secret('bunny_storage_key')])->timeout(300)->get($this->objectUrl($path));
    }

    /** Staff → Recording storage → "Test connection": PUT, GET, DELETE a tiny file. @return array{ok: bool, lines: list<string>} */
    public function test(): array
    {
        if (! $this->bunnyConfigured()) {
            return ['ok' => false, 'lines' => ['Bunny Storage is not configured (zone name + storage API key). Recordings are kept on the private local disk.']];
        }
        $lines = ['Host: '.$this->host().' · zone: '.S::str('bunny_storage_zone')];
        $path = trim(S::str('bunny_path_prefix') ?: 'call-recordings', '/').'/_connection-test-'.bin2hex(random_bytes(4)).'.txt';
        $body = 'Prompt Aid connection test '.now()->toIso8601String();
        try {
            $put = Http::withHeaders(['AccessKey' => S::secret('bunny_storage_key')])->timeout(30)->withBody($body, 'text/plain')->put($this->objectUrl($path));
            $lines[] = 'Upload: HTTP '.$put->status();
            if (! $put->successful()) {
                return ['ok' => false, 'lines' => array_merge($lines, ['Check the zone name, region and the storage zone PASSWORD (FTP & API access), not the account API key.'])];
            }
            $get = $this->fetch($path);
            $lines[] = 'Download: HTTP '.$get->status().($get->body() === $body ? ' (content matches)' : ' (content differs)');
            $del = Http::withHeaders(['AccessKey' => S::secret('bunny_storage_key')])->timeout(30)->delete($this->objectUrl($path));
            $lines[] = 'Delete: HTTP '.$del->status();
            if ($cdn = $this->signedCdnUrl('test.txt')) {
                $lines[] = 'Signed Pull Zone playback URLs: on ('.parse_url($cdn, PHP_URL_HOST).')';
            } else {
                $lines[] = 'Signed Pull Zone URLs: off — playback is streamed through Prompt Aid.';
            }

            return ['ok' => $get->successful() && $get->body() === $body, 'lines' => $lines];
        } catch (\Throwable $e) {
            return ['ok' => false, 'lines' => array_merge($lines, ['Connection error: '.$e->getMessage()])];
        }
    }
}
