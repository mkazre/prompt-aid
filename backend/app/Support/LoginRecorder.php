<?php

namespace App\Support;

use App\Models\LoginEvent;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Writes the login history (`login_events`).
 *
 *  - Website + Staff/Vendor/Partner panel sign-ins come from Laravel's auth
 *    events (Login, Failed), wired in AppServiceProvider::boot().
 *  - App (Sanctum token) sign-ins are recorded explicitly by
 *    Api\AuthController::login(), because that is where the token is
 *    created; the event listeners skip /api requests so nothing is counted
 *    twice.
 *
 * Failed attempts are only stored when the email belongs to an account
 * (Laravel passes the user on Failed in that case) — random emails typed
 * into the form are not kept. Recording never breaks a sign-in.
 */
class LoginRecorder
{
    public static function onLogin(Login $event): void
    {
        $request = static::request();
        if (! $request || $request->is('api/*') || ! $event->user instanceof User) {
            return;
        }

        static::write($event->user, $event->user->email, static::channelFor($request), true, $request);
    }

    public static function onFailed(Failed $event): void
    {
        $request = static::request();
        if (! $request || $request->is('api/*') || ! $event->user instanceof User) {
            return;
        }

        static::write($event->user, $event->user->email, static::channelFor($request), false, $request);
    }

    /** Explicit hook for the API (mobile app) login. */
    public static function app(?User $user, string $email, bool $success, Request $request, ?int $tokenId = null): void
    {
        if (! $user) {
            return; // unknown email: not stored
        }

        static::write($user, $email, LoginEvent::CHANNEL_APP, $success, $request, $tokenId);
    }

    public static function retentionDays(): int
    {
        return max(7, (int) Setting::get('login_events_retention_days', 180));
    }

    /** Tick job: delete rows older than the retention period, in small batches. */
    public static function prune(): int
    {
        return LoginEvent::query()->where('created_at', '<', now()->subDays(static::retentionDays()))->limit(5000)->delete();
    }

    protected static function channelFor(Request $request): string
    {
        $routeName = $request->route()?->getName();

        // The public website login form, the patient sign-up flows and the
        // automatic re-login from a "remember me" cookie on website pages.
        if (in_array($routeName, ['login.store', 'register.store'], true) || str_starts_with((string) $routeName, 'register.wizard.')) {
            return LoginEvent::CHANNEL_WEB;
        }

        // Filament panels sign in through Livewire (POST /livewire*/update)
        // from /staff/login, /vendor/login or /partner/login.
        if (static::panelFor($request) !== null) {
            return LoginEvent::CHANNEL_STAFF;
        }

        return LoginEvent::CHANNEL_WEB;
    }

    protected static function panelFor(Request $request): ?string
    {
        foreach (['staff', 'vendor', 'partner'] as $panel) {
            if ($request->is($panel, $panel.'/*')) {
                return $panel;
            }
        }

        $referer = (string) $request->headers->get('referer', '');
        $path = trim((string) parse_url($referer, PHP_URL_PATH), '/');
        if ($path !== '' && str_contains((string) $request->path(), 'livewire')) {
            foreach (['staff', 'vendor', 'partner'] as $panel) {
                if ($path === $panel || str_starts_with($path, $panel.'/')) {
                    return $panel;
                }
            }
        }

        return null;
    }

    protected static function write(?User $user, ?string $email, string $channel, bool $success, Request $request, ?int $tokenId = null): void
    {
        try {
            LoginEvent::query()->create([
                'user_id' => $user?->id,
                'email' => $email ? mb_substr($email, 0, 255) : null,
                'channel' => $channel,
                'success' => $success,
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 512) ?: null,
                'panel' => $channel === LoginEvent::CHANNEL_STAFF ? static::panelFor($request) : null,
                'token_id' => $tokenId,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[LoginRecorder] '.$e->getMessage());
        }
    }

    protected static function request(): ?Request
    {
        return app()->bound('request') && ! app()->runningInConsole() ? app('request') : null;
    }
}
