<?php

/*
| Consult hub (website patient page + JSON used by the patient page and the
| doctor's Filament hub page, session auth). Required once from
| routes/web.php. Every endpoint 404s for anyone but the appointment's own
| patient / doctor (App\Http\Controllers\ConsultHubController).
*/

use App\Http\Controllers\ConsultHubController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/account/consults/{appointment}', [ConsultHubController::class, 'page'])
        ->whereNumber('appointment')
        ->name('account.consult-hub');

    Route::prefix('account/consult-hub/{appointment}')->whereNumber('appointment')->name('consult-hub.')->group(function () {
        Route::middleware('throttle:120,1')->get('/state', [ConsultHubController::class, 'show'])->name('state');
        Route::middleware('throttle:120,1')->post('/presence', [ConsultHubController::class, 'presence'])->name('presence');
        Route::middleware('throttle:120,1')->get('/chat', [ConsultHubController::class, 'chat'])->name('chat');
        Route::middleware('throttle:60,1')->post('/chat', [ConsultHubController::class, 'sendChat'])->name('chat.send');
        Route::middleware('throttle:30,1')->group(function () {
            Route::post('/start', [ConsultHubController::class, 'start'])->name('start');
            Route::post('/calling', [ConsultHubController::class, 'calling'])->name('calling');
            Route::post('/connected', [ConsultHubController::class, 'connected'])->name('connected');
            Route::post('/end', [ConsultHubController::class, 'end'])->name('end');
            Route::post('/request-callback', [ConsultHubController::class, 'requestCallback'])->name('request-callback');
        });
    });
});
