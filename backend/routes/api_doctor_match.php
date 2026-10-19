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

// Orange triage "speak to a doctor now" after accept: patient incoming call
// (ring) + doctor call-back card / Start / outcomes. Ownership-checked in
// ConsultCallController (404 for anyone else).
Route::prefix('consults')->group(function () {
    Route::middleware('throttle:60,1')->get('/ring', [\App\Http\Controllers\ConsultCallController::class, 'ring']);
    Route::post('/{appointment}/ring/answer', [\App\Http\Controllers\ConsultCallController::class, 'answer'])->whereNumber('appointment');
    Route::post('/{appointment}/ring/decline', [\App\Http\Controllers\ConsultCallController::class, 'decline'])->whereNumber('appointment');
    Route::get('/{appointment}/card', [\App\Http\Controllers\ConsultCallController::class, 'card'])->whereNumber('appointment');
    Route::middleware('throttle:30,1')->group(function () {
        Route::post('/{appointment}/start', [\App\Http\Controllers\ConsultCallController::class, 'start'])->whereNumber('appointment');
        Route::post('/{appointment}/calling', [\App\Http\Controllers\ConsultCallController::class, 'calling'])->whereNumber('appointment');
        Route::post('/{appointment}/outcome', [\App\Http\Controllers\ConsultCallController::class, 'outcome'])->whereNumber('appointment');
        Route::post('/{appointment}/video', [\App\Http\Controllers\ConsultCallController::class, 'switchToVideo'])->whereNumber('appointment');
    });
});
