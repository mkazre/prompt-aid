<?php

/*
| Consult hub API for the app (Sanctum) — required from routes/api.php
| inside the auth:sanctum group. Additive: older app builds keep using
| /consults/* and /appointments/{id}/video. Every endpoint 404s for anyone
| but the appointment's own patient / doctor.
*/

use App\Http\Controllers\ConsultHubController;
use Illuminate\Support\Facades\Route;

Route::prefix('consult-hub/{appointment}')->whereNumber('appointment')->group(function () {
    Route::middleware('throttle:120,1')->get('/', [ConsultHubController::class, 'show']);
    Route::middleware('throttle:120,1')->post('/presence', [ConsultHubController::class, 'presence']);
    Route::middleware('throttle:120,1')->get('/chat', [ConsultHubController::class, 'chat']);
    Route::middleware('throttle:60,1')->post('/chat', [ConsultHubController::class, 'sendChat']);
    Route::middleware('throttle:30,1')->group(function () {
        Route::post('/start', [ConsultHubController::class, 'start']);
        Route::post('/calling', [ConsultHubController::class, 'calling']);
        Route::post('/connected', [ConsultHubController::class, 'connected']);
        Route::post('/end', [ConsultHubController::class, 'end']);
        Route::post('/request-callback', [ConsultHubController::class, 'requestCallback']);
    });
});
