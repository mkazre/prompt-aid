<?php

/*
| Unified dispatch API (apps). Required from routes/api.php INSIDE the
| auth:sanctum group, BEFORE the /rides/{ride} routes (so /rides/active
| is not captured by the {ride} binding).
*/

use App\Http\Controllers\DispatchController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

// Providers (doctor, driver, pharmacy, partner) — scoped to the signed-in provider in the controller.
Route::get('/dispatch/inbox', [DispatchController::class, 'inbox'])->middleware('throttle:120,1');
Route::post('/dispatch/offers/{key}/accept', [DispatchController::class, 'accept'])->where('key', '(ride|dm|job):[0-9]+')->middleware('throttle:30,1');
Route::post('/dispatch/offers/{key}/decline', [DispatchController::class, 'decline'])->where('key', '(ride|dm|job):[0-9]+')->middleware('throttle:30,1');
Route::get('/dispatch/availability', [DispatchController::class, 'availability']);
Route::post('/dispatch/availability', [DispatchController::class, 'updateAvailability'])->middleware('throttle:30,1');
Route::get('/dispatch/config', [DispatchController::class, 'config']);

// Patients.
Route::middleware('role:'.User::ROLE_PATIENT)->group(function () {
    Route::get('/rides/active', [DispatchController::class, 'activeRide']);
    Route::post('/rides/{ride}/retry', [DispatchController::class, 'retryRide'])->whereNumber('ride')->middleware('throttle:10,1');
    Route::get('/patient/search-radius', [DispatchController::class, 'radius']);
    Route::post('/patient/search-radius', [DispatchController::class, 'updateRadius']);
    Route::post('/doctor-requests', [DispatchController::class, 'requestDoctorLater'])->middleware('throttle:10,1');
    Route::get('/dispatch/requests/{job}', [DispatchController::class, 'showJob'])->whereNumber('job')->middleware('throttle:120,1');
    Route::post('/dispatch/requests/{job}/cancel', [DispatchController::class, 'cancelJob'])->whereNumber('job');
    Route::post('/dispatch/requests/{job}/retry', [DispatchController::class, 'retryJob'])->whereNumber('job')->middleware('throttle:10,1');
});
