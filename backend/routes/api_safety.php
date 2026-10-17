<?php

/*
 * Trip safety API (app). Required from routes/api.php INSIDE the
 * `auth:sanctum` group. Ownership (ride patient / assigned driver / staff
 * permission) is checked in RideRecordingController + RideRecordingService.
 * Media itself is served from the signed web route `ride-recordings.media`.
 */

use App\Http\Controllers\RideRecordingController;
use Illuminate\Support\Facades\Route;

Route::prefix('rides/{ride}/recordings')->whereNumber('ride')->group(function () {
    Route::get('/', [RideRecordingController::class, 'index'])->middleware('throttle:60,1')->name('api.ride-recordings.index');
    Route::post('/', [RideRecordingController::class, 'start'])->middleware('throttle:20,1')->name('api.ride-recordings.start');
    Route::post('/{recording}/chunks', [RideRecordingController::class, 'chunk'])->whereNumber('recording')->middleware('throttle:120,1')->name('api.ride-recordings.chunk');
    Route::post('/{recording}/complete', [RideRecordingController::class, 'complete'])->whereNumber('recording')->middleware('throttle:30,1')->name('api.ride-recordings.complete');
});
