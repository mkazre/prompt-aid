<?php

namespace App\Http\Middleware;

use App\Support\Tick;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Terminable middleware: runs due Tick jobs AFTER the response has been
 * sent (fastcgi/litespeed finish_request), throttled to once per
 * Tick::THROTTLE_SECONDS platform-wide. Never affects the request.
 */
class RunTickAfterResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (app()->runningInConsole() || app()->runningUnitTests()) {
            return;
        }

        Tick::maybeRunAfterResponse();
    }
}
