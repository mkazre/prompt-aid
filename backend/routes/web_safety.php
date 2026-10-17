<?php

/*
 * Trip safety (website + web driver console). Required from routes/web.php
 * OUTSIDE the auth group (the signed media route must work for <audio>
 * elements and the app's player, which send no session/token); every other
 * route here is wrapped in `auth` below.
 */

use App\Http\Controllers\RideRecordingController;
use Illuminate\Support\Facades\Route;

// Signed, short-lived (15 min) audio URL. `u` = the viewer it was issued to;
// access is re-checked and logged on every request.
Route::get('/rides/recordings/{recording}/media', [RideRecordingController::class, 'media'])
    ->whereNumber('recording')
    ->middleware(['signed', 'throttle:240,1'])
    ->name('ride-recordings.media');

Route::middleware('auth')->group(function () {
    Route::prefix('rides/{ride}/recordings')->whereNumber('ride')->name('ride-recordings.')->group(function () {
        Route::get('/', [RideRecordingController::class, 'index'])->middleware('throttle:60,1')->name('index');
        Route::post('/', [RideRecordingController::class, 'start'])->middleware('throttle:20,1')->name('start');
        Route::post('/{recording}/chunks', [RideRecordingController::class, 'chunk'])->whereNumber('recording')->middleware('throttle:120,1')->name('chunk');
        Route::post('/{recording}/complete', [RideRecordingController::class, 'complete'])->whereNumber('recording')->middleware('throttle:30,1')->name('complete');
    });

    Route::get('/account/rides/{ride}/recordings', [RideRecordingController::class, 'accountPage'])
        ->whereNumber('ride')->name('account.rides.recordings');
});
