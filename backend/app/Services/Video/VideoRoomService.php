<?php

namespace App\Services\Video;

use App\Models\Appointment;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * In-app video consults on Jitsi. The provider is a settings change
 * (System → Video consults), never a code change:
 *
 *  - jaas:             8x8 Jitsi-as-a-Service. Every join gets a short-lived
 *                      RS256 JWT signed with the JaaS API-key private key, so
 *                      nobody is ever asked to log in to Jitsi.
 *  - self_hosted_jwt:  own Jitsi server with prosody token auth (HS256
 *                      app_id / app_secret).
 *  - public:           meet.jit.si — last-resort fallback only; it asks the
 *                      first participant to sign in as moderator (Google /
 *                      GitHub), which is the "external sign-in" users saw.
 *
 * Our platform login IS the call identity: tokens are minted server-side
 * per call, only for the appointment's own doctor (moderator) or patient
 * (participant), only inside the join window. Admins can't silently join.
 * The private key / app secret are stored encrypted and never rendered.
 */
class VideoRoomService
{
    public const DEFAULT_BASE = 'https://meet.jit.si';

    public const PROVIDERS = [
        'jaas' => '8x8 JaaS (recommended — no sign-in for anyone)',
        'self_hosted_jwt' => 'Self-hosted Jitsi with JWT',
        'public' => 'Public meet.jit.si (fallback — asks for a moderator login)',
    ];

    /** Jitsi toolbar buttons an admin may allow on the web call pages. Mic, camera and hang-up are our own controls. */
    public const TOOLBAR_CHOICES = [
        'chat' => 'In-call chat',
        'desktop' => 'Share screen',
        'raisehand' => 'Raise hand',
        'tileview' => 'Tile view',
        'select-background' => 'Blur / background',
        'settings' => 'Device settings',
        'fullscreen' => 'Full screen',
        'filmstrip' => 'Show/hide thumbnails',
        'toggle-camera' => 'Flip camera (mobile)',
    ];

    public const DEFAULT_TOOLBAR = ['chat', 'desktop', 'tileview', 'select-background', 'settings'];

    public const EMBED_TOKEN_TTL_SECONDS = 300;

    // ---------------------------------------------------------------- settings

    public function provider(): string
    {
        $p = (string) Setting::get('video_provider', '');
        if (isset(self::PROVIDERS[$p])) {
            return $p;
        }

        // Older installs: an app id plus a key means JaaS was intended.
        return $this->jaasAppId() && $this->secret('video_jaas_private_key') ? 'jaas' : 'public';
    }

    public function baseUrl(): string
    {
        $base = trim((string) Setting::get('video_base_url', self::DEFAULT_BASE));

        return rtrim($base !== '' ? $base : self::DEFAULT_BASE, '/');
    }

    public function jaasAppId(): ?string
    {
        $id = trim((string) Setting::get('video_jaas_app_id', ''));

        return $id !== '' ? $id : null;
    }

    /** Accepts either "<AppID>/<KeyID>" or just "<KeyID>" and returns the full kid. */
    public function jaasKid(): ?string
    {
        $key = trim((string) Setting::get('video_jaas_key_id', ''));
        $app = $this->jaasAppId();
        if ($key === '' || ! $app) {
            return null;
        }

        return str_contains($key, '/') ? $key : $app.'/'.$key;
    }

    public function selfHostedDomain(): ?string
    {
        $d = trim((string) Setting::get('video_selfhosted_domain', ''));
        $d = preg_replace('#^https?://#i', '', $d);

        return $d !== '' ? rtrim($d, '/') : null;
    }

    public function tokenTtlMinutes(): int
    {
        return max(5, min(240, (int) Setting::get('video_token_ttl_minutes', 60)));
    }

    /** @return list<string> */
    public function toolbarButtons(): array
    {
        $raw = Setting::get('video_toolbar_buttons');
        $list = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($list)) {
            $list = self::DEFAULT_TOOLBAR;
        }

        return array_values(array_intersect(array_keys(self::TOOLBAR_CHOICES), $list));
    }

    public function prejoinEnabled(): bool
    {
        return (bool) Setting::get('video_prejoin_enabled', false);
    }

    public function allowMeetOverride(): bool
    {
        return (bool) Setting::get('video_allow_meet_override', false);
    }

    /** Decrypted secret setting, or null. Never log or render the value. */
    public function secret(string $key): ?string
    {
        $enc = (string) Setting::get($key, '');
        if ($enc === '') {
            return null;
        }
        try {
            $plain = Crypt::decryptString($enc);
        } catch (Throwable) {
            return null;
        }

        return $plain !== '' ? $plain : null;
    }

    public function hasSecret(string $key): bool
    {
        return (string) Setting::get($key, '') !== '';
    }

    public function storeSecret(string $key, ?string $plain): void
    {
        Setting::set($key, $plain === null || $plain === '' ? '' : Crypt::encryptString($plain));
    }

    /** Jitsi domain the IFrame API connects to. */
    public function domain(): string
    {
        return match ($this->provider()) {
            'jaas' => '8x8.vc',
            'self_hosted_jwt' => $this->selfHostedDomain() ?? (string) parse_url($this->baseUrl(), PHP_URL_HOST),
            default => (string) (parse_url($this->baseUrl(), PHP_URL_HOST) ?: 'meet.jit.si'),
        };
    }

    public function externalApiUrl(): string
    {
        return $this->provider() === 'jaas' && $this->jaasAppId()
            ? 'https://8x8.vc/'.rawurlencode($this->jaasAppId()).'/external_api.js'
            : 'https://'.$this->domain().'/external_api.js';
    }

    // ---------------------------------------------------------------- rooms

    public function isParticipant(Appointment $appointment, User $user): bool
    {
        return ($user->isPatient() && $user->patientProfile && $appointment->patient_profile_id === $user->patientProfile->id)
            || ($user->isDoctor() && $user->doctorProfile && $appointment->doctor_profile_id === $user->doctorProfile->id);
    }

    public function isModerator(Appointment $appointment, User $user): bool
    {
        return $user->isDoctor() && $user->doctorProfile && $appointment->doctor_profile_id === $user->doctorProfile->id;
    }

    public function ensureRoom(Appointment $appointment): string
    {
        if (! $appointment->video_room) {
            $appointment->forceFill(['video_room' => Appointment::generateVideoRoom($appointment->id)])->saveQuietly();
        }

        return $appointment->video_room;
    }

    /** Room name as passed to the IFrame API (JaaS needs the "<AppID>/" prefix). */
    public function jitsiRoomName(Appointment $appointment): string
    {
        $room = $this->ensureRoom($appointment);

        return $this->provider() === 'jaas' && $this->jaasAppId() ? $this->jaasAppId().'/'.$room : $room;
    }

    public function roomUrl(Appointment $appointment): string
    {
        $room = $this->ensureRoom($appointment);

        return match ($this->provider()) {
            'jaas' => 'https://8x8.vc/'.rawurlencode((string) $this->jaasAppId()).'/'.rawurlencode($room),
            'self_hosted_jwt' => 'https://'.$this->domain().'/'.rawurlencode($room),
            default => $this->baseUrl().'/'.rawurlencode($room),
        };
    }

    public function displayName(User $user): string
    {
        return $user->isDoctor() ? 'Dr. '.$user->name : (string) $user->name;
    }

    protected function avatarUrl(User $user): ?string
    {
        $a = (string) ($user->avatar ?? '');
        if ($a === '') {
            return null;
        }

        return Str::startsWith($a, ['http://', 'https://']) ? $a : asset('storage/'.ltrim($a, '/'));
    }

    // ---------------------------------------------------------------- tokens

    /**
     * Mint the per-call JWT. Null for the public provider (no auth there).
     *
     * @return array{jwt: string, expires_at: \Carbon\CarbonInterface}|null
     */
    public function mintJwt(Appointment $appointment, User $user): ?array
    {
        $provider = $this->provider();
        if ($provider === 'public') {
            return null;
        }

        $room = $this->ensureRoom($appointment);
        $now = now();
        $exp = $now->copy()->addMinutes($this->tokenTtlMinutes());
        $windowEnd = $appointment->videoWindowEnd();
        if ($windowEnd && $windowEnd->lt($exp)) {
            $exp = $windowEnd->copy()->max($now->copy()->addMinutes(5));
        }
        $moderator = $this->isModerator($appointment, $user);

        $userClaim = array_filter([
            'id' => 'pa-'.$user->id,
            'name' => $this->displayName($user),
            'avatar' => $this->avatarUrl($user),
            'email' => $user->email,
            'moderator' => $moderator,
        ], fn ($v) => $v !== null);

        if ($provider === 'jaas') {
            $app = $this->jaasAppId();
            $kid = $this->jaasKid();
            $key = $this->secret('video_jaas_private_key');
            if (! $app || ! $kid || ! $key) {
                throw new \RuntimeException('JaaS video is selected but the App ID, API key ID or private key is missing.');
            }
            $jwt = JitsiJwt::encode(
                ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $kid],
                [
                    'aud' => 'jitsi',
                    'iss' => 'chat',
                    'sub' => $app,
                    'room' => $room,
                    'iat' => $now->getTimestamp(),
                    'nbf' => $now->getTimestamp() - 10,
                    'exp' => $exp->getTimestamp(),
                    'context' => [
                        'user' => $userClaim + ['hidden-from-recorder' => false],
                        'features' => [
                            'livestreaming' => false,
                            'recording' => false,
                            'transcription' => false,
                            'outbound-call' => false,
                        ],
                    ],
                ],
                $key
            );
        } else {
            $appId = trim((string) Setting::get('video_selfhosted_app_id', ''));
            $secret = $this->secret('video_selfhosted_app_secret');
            $domain = $this->selfHostedDomain();
            if ($appId === '' || ! $secret || ! $domain) {
                throw new \RuntimeException('Self-hosted Jitsi is selected but the domain, app id or app secret is missing.');
            }
            $userClaim['affiliation'] = $moderator ? 'owner' : 'member';
            $jwt = JitsiJwt::encode(
                ['alg' => 'HS256', 'typ' => 'JWT'],
                [
                    'aud' => trim((string) Setting::get('video_selfhosted_audience', '')) ?: 'jitsi',
                    'iss' => $appId,
                    'sub' => $domain,
                    'room' => $room,
                    'iat' => $now->getTimestamp(),
                    'nbf' => $now->getTimestamp() - 10,
                    'exp' => $exp->getTimestamp(),
                    'moderator' => $moderator,
                    'context' => ['user' => $userClaim],
                ],
                $secret
            );
        }

        return ['jwt' => $jwt, 'expires_at' => $exp];
    }

    /**
     * Everything the call page's JS needs (precomputed, JSON-safe).
     *
     * @param  'web'|'staff'|'app'  $mode
     */
    public function callConfig(Appointment $appointment, User $user, bool $audioOnly, string $mode, ?string $endUrl = null, ?string $backUrl = null): array
    {
        $token = $this->mintJwt($appointment, $user);
        $isDoctor = $this->isModerator($appointment, $user);
        $appointment->loadMissing(['doctor.user', 'patient.user']);
        $counterpart = $isDoctor
            ? (string) ($appointment->patient?->user?->name ?? 'Patient')
            : ($appointment->doctor?->user ? 'Dr. '.$appointment->doctor->user->name : 'Your doctor');

        return [
            'mode' => $mode,
            'provider' => $this->provider(),
            'domain' => $this->domain(),
            'scriptUrl' => $this->externalApiUrl(),
            'roomName' => $this->jitsiRoomName($appointment),
            'jwt' => $token['jwt'] ?? null,
            'displayName' => $this->displayName($user),
            'email' => (string) $user->email,
            'counterpart' => $counterpart,
            'role' => $isDoctor ? 'moderator' : 'participant',
            'audioOnly' => $audioOnly,
            'prejoin' => $this->prejoinEnabled(),
            'toolbar' => $mode === 'app' ? [] : $this->toolbarButtons(),
            'endUrl' => $endUrl,
            'backUrl' => $backUrl,
            'csrf' => $mode === 'app' ? null : csrf_token(),
        ];
    }

    /** Single-use, ≤5-minute token that lets the app WebView open /video/embed/{id}. */
    public function issueEmbedToken(Appointment $appointment, User $user, bool $audioOnly): string
    {
        $token = Str::random(48);
        Cache::put('video-embed:'.hash('sha256', $token), [
            'appointment_id' => $appointment->id,
            'user_id' => $user->id,
            'audio' => $audioOnly,
        ], self::EMBED_TOKEN_TTL_SECONDS);

        return $token;
    }

    /** @return array{appointment_id: int, user_id: int, audio: bool}|null */
    public function consumeEmbedToken(string $token): ?array
    {
        if (! preg_match('/^[A-Za-z0-9]{48}$/', $token)) {
            return null;
        }
        $data = Cache::pull('video-embed:'.hash('sha256', $token));

        return is_array($data) ? $data : null;
    }

    /** Where a participant opens the in-page call (doctor → staff page, patient → website page). */
    public function callUrlFor(Appointment $appointment, User $user): string
    {
        if ($this->allowMeetOverride() && $appointment->meet_url) {
            return $appointment->meet_url;
        }

        // Consult hub (video / voice / chat + fallback) for both sides.
        return $this->isModerator($appointment, $user)
            ? \App\Filament\Pages\ConsultHub::getUrl(['appointment' => $appointment->id], panel: 'admin')
            : route('account.consult-hub', $appointment);
    }

    /**
     * API / legacy payload. join_url stays for older app builds (now with
     * ?jwt= so they no longer hit the Jitsi login either).
     */
    public function joinPayload(Appointment $appointment, User $user, bool $audioOnly = false): array
    {
        if ($this->allowMeetOverride() && $appointment->meet_url) {
            return [
                'join_url' => $appointment->meet_url,
                'room' => '',
                'provider' => 'external',
                'external' => true,
                'display_name' => $user->name,
                'audio_only' => $audioOnly,
                'jwt' => null,
                'embed_url' => null,
                'expires_at' => null,
                'role' => $this->isModerator($appointment, $user) ? 'moderator' : 'participant',
                'domain' => null,
            ];
        }

        $displayName = $this->displayName($user);
        $token = $this->mintJwt($appointment, $user);

        $hash = [
            'config.prejoinPageEnabled' => 'false',
            'config.prejoinConfig.enabled' => 'false',
            'config.disableDeepLinking' => 'true',
            'config.startWithAudioMuted' => 'false',
            'config.startWithVideoMuted' => $audioOnly ? 'true' : 'false',
            'userInfo.displayName' => json_encode($displayName),
            'interfaceConfig.MOBILE_APP_PROMO' => 'false',
        ];
        $fragment = implode('&', array_map(fn ($k, $v) => $k.'='.rawurlencode($v), array_keys($hash), $hash));
        $joinUrl = $this->roomUrl($appointment).($token ? '?jwt='.$token['jwt'] : '').'#'.$fragment;

        $embed = $this->issueEmbedToken($appointment, $user, $audioOnly);

        return [
            'join_url' => $joinUrl,
            'room' => $this->jitsiRoomName($appointment),
            'provider' => $this->provider(),
            'external' => false,
            'display_name' => $displayName,
            'audio_only' => $audioOnly,
            'domain' => $this->domain(),
            'jwt' => $token['jwt'] ?? null,
            'expires_at' => ($token['expires_at'] ?? now()->addSeconds(self::EMBED_TOKEN_TTL_SECONDS))->toIso8601String(),
            'embed_url' => route('video.embed', ['appointment' => $appointment->id, 't' => $embed]).($audioOnly ? '&audio=1' : ''),
            'embed_expires_at' => now()->addSeconds(self::EMBED_TOKEN_TTL_SECONDS)->toIso8601String(),
            'role' => $this->isModerator($appointment, $user) ? 'moderator' : 'participant',
        ];
    }

    // ---------------------------------------------------------------- admin test

    /** @return array{ok: bool, lines: list<string>} */
    public function testConfiguration(): array
    {
        $lines = [];
        $ok = true;
        $provider = $this->provider();
        $lines[] = 'Provider: '.(self::PROVIDERS[$provider] ?? $provider);

        try {
            if ($provider === 'jaas') {
                $app = $this->jaasAppId();
                $kid = $this->jaasKid();
                $key = $this->secret('video_jaas_private_key');
                if (! $app || ! str_starts_with($app, 'vpaas-magic-cookie-')) {
                    $ok = false;
                    $lines[] = '✗ App ID should look like vpaas-magic-cookie-…';
                }
                if (! $kid) {
                    $ok = false;
                    $lines[] = '✗ API key ID missing.';
                }
                $pub = $key ? JitsiJwt::publicKeyFromPrivate($key) : null;
                if (! $pub) {
                    $ok = false;
                    $lines[] = '✗ Private key missing or not a valid RSA PEM.';
                } elseif ($app && $kid) {
                    $jwt = JitsiJwt::encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $kid], ['aud' => 'jitsi', 'iss' => 'chat', 'sub' => $app, 'room' => '*', 'nbf' => time() - 10, 'exp' => time() + 60], $key);
                    JitsiJwt::decode($jwt, $pub, 'RS256');
                    $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pub));
                    $lines[] = '✓ Token signed and verified locally (public key SHA-256 '.substr(hash('sha256', (string) $der), 0, 16).'… — must match the key uploaded in JaaS).';
                }
            } elseif ($provider === 'self_hosted_jwt') {
                if (! $this->selfHostedDomain() || trim((string) Setting::get('video_selfhosted_app_id', '')) === '' || ! $this->secret('video_selfhosted_app_secret')) {
                    $ok = false;
                    $lines[] = '✗ Domain, app id and app secret are all required.';
                } else {
                    $lines[] = '✓ Domain, app id and app secret are set.';
                }
            } else {
                $lines[] = '! Public meet.jit.si asks the first participant to sign in with Google/GitHub. Use JaaS or self-hosted for sign-in-free calls.';
            }

            $res = Http::timeout(8)->get($this->externalApiUrl());
            if ($res->successful() && str_contains($res->body(), 'JitsiMeetExternalAPI')) {
                $lines[] = '✓ Reached '.$this->externalApiUrl();
            } else {
                $ok = false;
                $lines[] = '✗ Could not load '.$this->externalApiUrl().' (HTTP '.$res->status().'). Check the App ID / domain.';
            }
        } catch (Throwable $e) {
            $ok = false;
            $lines[] = '✗ '.$e->getMessage();
        }

        return ['ok' => $ok, 'lines' => $lines];
    }
}
