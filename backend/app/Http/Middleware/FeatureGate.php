<?php

namespace App\Http\Middleware;

use App\Support\Features;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Feature switches (App\Support\Features).
 *
 *  - As `feature:shop` (or `feature:shop,shuttle`) on a route: refuses the
 *    request while any listed feature is off.
 *  - With no parameters (appended to the web and api groups in
 *    bootstrap/app.php): looks the current route up in Features::WEB_ROUTES /
 *    API_ROUTES, so existing routes are gated without editing their
 *    definitions (and route:cache keeps working).
 *
 * Web requests get the friendly "not available" page (HTTP 404, so search
 * engines drop the page and no proxy swaps in its own 5xx error page); API
 * requests get a JSON 403 with a message the app can show.
 */
class FeatureGate
{
    public function handle(Request $request, Closure $next, string ...$features): Response
    {
        if ($features === []) {
            $feature = $request->is('api/*')
                ? Features::forApiRequest($request)
                : Features::forRouteName($request->route()?->getName());
            $features = $feature ? [$feature] : [];
        }

        foreach ($features as $feature) {
            if (Features::off($feature)) {
                return $this->unavailable($request, $feature);
            }
        }

        return $next($request);
    }

    protected function unavailable(Request $request, string $feature): Response
    {
        $label = Features::FEATURES[$feature]['label'] ?? 'This service';

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json([
                'message' => "{$label} is not available at the moment.",
                'feature' => $feature,
                'available' => false,
            ], 403);
        }

        return response()->view('feature-unavailable', ['label' => $label, 'feature' => $feature], 404);
    }
}
