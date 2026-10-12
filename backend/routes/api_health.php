<?php

/*
| Patient self-care API (health stream). Required from routes/api.php at
| the top level (NOT inside auth:sanctum) because the .ics download is a
| public, temporary signed URL the app opens in the browser/calendar.
| Everything else is grouped under auth:sanctum + role:patient below.
*/

use App\Http\Controllers\Api\Health\AppointmentToolsController;
use App\Http\Controllers\Api\Health\HealthController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::get('/health/appointments/{appointment}/calendar.ics', [AppointmentToolsController::class, 'signedCalendar'])
    ->middleware(['signed', 'throttle:30,1'])
    ->whereNumber('appointment')
    ->name('api.health.appointments.ics');

Route::middleware(['auth:sanctum', 'role:'.User::ROLE_PATIENT])->group(function () {
    Route::get('/health/config', [HealthController::class, 'config']);

    Route::get('/favourites/doctors', [HealthController::class, 'favourites']);
    Route::post('/favourites/doctors', [HealthController::class, 'addFavourite']);
    Route::delete('/favourites/doctors', [HealthController::class, 'removeFavourite']);
    Route::delete('/favourites/doctors/{doctor}', [HealthController::class, 'removeFavourite'])->whereNumber('doctor');

    Route::get('/family-members', [HealthController::class, 'family']);
    Route::post('/family-members', [HealthController::class, 'storeFamily']);
    Route::put('/family-members/{id}', [HealthController::class, 'updateFamily'])->whereNumber('id');
    Route::delete('/family-members/{id}', [HealthController::class, 'destroyFamily'])->whereNumber('id');

    Route::get('/medications', [HealthController::class, 'medications']);
    Route::post('/medications', [HealthController::class, 'storeMedication']);
    Route::put('/medications/{id}', [HealthController::class, 'updateMedication'])->whereNumber('id');
    Route::delete('/medications/{id}', [HealthController::class, 'destroyMedication'])->whereNumber('id');
    Route::post('/medications/{id}/taken', [HealthController::class, 'medicationTaken'])->whereNumber('id');

    Route::get('/vitals', [HealthController::class, 'vitals']);
    Route::post('/vitals', [HealthController::class, 'storeVital'])->middleware('throttle:30,1');
    Route::delete('/vitals/{id}', [HealthController::class, 'destroyVital'])->whereNumber('id');

    Route::get('/medical-id', [HealthController::class, 'medicalId']);
    Route::put('/medical-id', [HealthController::class, 'updateMedicalId']);

    Route::get('/appointments/{id}/reschedule-slots', [AppointmentToolsController::class, 'slots'])->whereNumber('id')->middleware('throttle:60,1');
    Route::post('/appointments/{id}/reschedule', [AppointmentToolsController::class, 'reschedule'])->whereNumber('id')->middleware('throttle:10,1');
    Route::get('/appointments/{id}/calendar-link', [AppointmentToolsController::class, 'calendarLink'])->whereNumber('id');
});
