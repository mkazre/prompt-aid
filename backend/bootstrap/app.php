<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();

        // Cron-optional background jobs after the response is sent (App\Support\Tick).
        $middleware->append(\App\Http\Middleware\RunTickAfterResponse::class);

        // POPIA: re-consent interstitial (web pages only) + access logging of health-data API show endpoints.
        $middleware->appendToGroup('web', \App\Http\Middleware\RequireCurrentConsents::class);
        $middleware->appendToGroup('api', \App\Http\Middleware\LogSensitiveApiAccess::class);

        // Feature switches (App\Support\Features): switched-off services answer with a "not available" page/JSON.
        $middleware->appendToGroup('web', \App\Http\Middleware\FeatureGate::class);
        $middleware->appendToGroup('api', \App\Http\Middleware\FeatureGate::class);

        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
            'feature' => \App\Http\Middleware\FeatureGate::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
