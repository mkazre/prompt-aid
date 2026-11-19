<?php

namespace App\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * `throttle:N,M` with one counter per user (or IP) PER ROUTE.
 *
 * Laravel's stock middleware keys plain `throttle:N,M` limits on the user
 * alone, so every throttled route shares a single bucket: the app's
 * background polling (call status, ring, presence) used up the budget and
 * the Accept / Start buttons (30 a minute) answered "Too Many Attempts".
 * Named limiters (`throttle:support-write`) are unaffected.
 */
class ThrottlePerRoute extends ThrottleRequests
{
    protected function resolveRequestSignature($request)
    {
        $route = $request->route();
        $routeKey = $route ? $request->method().'|'.($route->getDomain() ?? '').'|'.$route->uri() : $request->method().'|'.$request->path();

        if ($user = $request->user()) {
            return sha1($user->getAuthIdentifier().'|'.$routeKey);
        }

        return sha1($request->ip().'|'.$routeKey);
    }
}
