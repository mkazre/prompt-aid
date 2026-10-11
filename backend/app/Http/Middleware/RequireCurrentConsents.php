<?php

namespace App\Http\Middleware;

use App\Services\Privacy\ConsentService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Web-only re-consent interstitial: when an admin publishes a new version
 * of a required legal document with "require re-consent" on, a logged-in
 * user who hasn't accepted it is redirected to /account/privacy/consent on
 * their next normal page view.
 *
 * Deliberately narrow: only GET page navigations; never JSON/AJAX/API,
 * Livewire, the Filament panels (/staff, /vendor, /partner), assets, the
 * consent pages themselves or logout. Any error fails OPEN (request
 * continues) — this is a notice, not an authorization check.
 */
class RequireCurrentConsents
{
    /** @var array<int, string> path prefixes never intercepted */
    protected const SKIP = [
        'account/privacy', 'logout', 'staff', 'vendor', 'partner', 'livewire', 'filament',
        'api', 'storage', 'assets', 'build', 'up', 'sanctum', 'broadcasting', 'login', 'register', 'join',
    ];

    public function __construct(protected ConsentService $consents) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            if ($this->shouldIntercept($request)) {
                $outstanding = $this->consents->outstandingFor($request->user());

                if ($outstanding !== []) {
                    $request->session()->put('privacy.reconsent_next', $request->getRequestUri());

                    return redirect()->route('account.privacy.reconsent');
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return $next($request);
    }

    protected function shouldIntercept(Request $request): bool
    {
        if (! $request->isMethod('GET') || $request->expectsJson() || $request->ajax()
            || $request->header('X-Livewire') || ! $request->hasSession()) {
            return false;
        }

        $user = $request->user();
        if (! $user) {
            return false;
        }

        $path = ltrim($request->path(), '/');
        foreach (self::SKIP as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return false;
            }
        }

        return true;
    }
}
