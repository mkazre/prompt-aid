<?php

/*
| "See a doctor now" (on-demand doctor matching) API — required from
| routes/api.php inside the auth:sanctum group. POST /request-doctor and
| the legacy /doctor/requests/* + /doctor/availability routes stay in
| api.php for backward compatibility.
*/

use App\Http\Controllers\Api\DoctorMatchController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::middleware('role:'.User::ROLE_PATIENT)->prefix('doctor-match')->group(function () {
    Route::get('/specialties', [DoctorMatchController::class, 'specialties']);
    Route::get('/quote', [DoctorMatchController::class, 'quote']);
    Route::get('/current', [DoctorMatchController::class, 'current']);
    Route::get('/{id}', [DoctorMatchController::class, 'show'])->whereNumber('id');
    Route::post('/{id}/cancel', [DoctorMatchController::class, 'cancel'])->whereNumber('id');
    Route::post('/{id}/waitlist', [DoctorMatchController::class, 'waitlist'])->whereNumber('id');
});

Route::middleware('role:'.User::ROLE_DOCTOR)->prefix('doctor')->group(function () {
    Route::middleware('throttle:20,1')->post('/heartbeat', [DoctorMatchController::class, 'heartbeat']);
    Route::get('/offers/current', [DoctorMatchController::class, 'currentOffer']);
    Route::post('/offers/{offer}/accept', [DoctorMatchController::class, 'acceptOffer'])->whereNumber('offer');
    Route::post('/offers/{offer}/decline', [DoctorMatchController::class, 'declineOffer'])->whereNumber('offer');
    Route::get('/consults/current', [DoctorMatchController::class, 'currentConsult']);
});
