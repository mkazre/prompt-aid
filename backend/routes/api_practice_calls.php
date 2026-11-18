<?php

/*
| Orange practice calls for the app (Sanctum) + the JaaS recording webhook
| (public, HMAC-signed). Required once from routes/api.php OUTSIDE the
| auth:sanctum group (this file adds its own). Additive: older app builds
| never call these.
*/

use App\Http\Controllers\CallRecordingController;
use App\Http\Controllers\PracticeCallController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:60,1')->post('/webhooks/jaas', [CallRecordingController::class, 'jaasWebhook'])->name('api.webhooks.jaas');

Route::middleware('auth:sanctum')->group(function () {
    Route::middleware('throttle:120,1')->get('/practice-calls/ring', [PracticeCallController::class, 'ring']);
    Route::middleware('throttle:120,1')->get('/practice-calls', [PracticeCallController::class, 'index']);
    Route::prefix('practice-calls/{call}')->whereNumber('call')->group(function () {
        Route::middleware('throttle:180,1')->get('/', [PracticeCallController::class, 'show']);
        Route::middleware('throttle:120,1')->post('/presence', [PracticeCallController::class, 'presence']);
        Route::middleware('throttle:60,1')->get('/slots', [PracticeCallController::class, 'slots']);
        Route::middleware('throttle:30,1')->group(function () {
            Route::post('/accept', [PracticeCallController::class, 'accept']);
            Route::post('/decline', [PracticeCallController::class, 'decline']);
            Route::post('/ring-again', [PracticeCallController::class, 'ringAgain']);
            Route::post('/end', [PracticeCallController::class, 'end']);
            Route::post('/location', [PracticeCallController::class, 'location']);
            Route::post('/booking', [PracticeCallController::class, 'book']);
            Route::post('/shuttle/quote', [PracticeCallController::class, 'shuttleQuote']);
            Route::post('/shuttle/choose', [PracticeCallController::class, 'shuttleChoose']);
        });
    });
    Route::middleware('throttle:60,1')->get('/consult-evidence/{appointment}', [PracticeCallController::class, 'evidence'])->whereNumber('appointment');
});
