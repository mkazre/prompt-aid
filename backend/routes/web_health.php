<?php

/*
| Patient self-care web pages (health stream): My doctors, family members,
| medications, vitals, medical ID, reschedule and add-to-calendar.
| Required from routes/web.php INSIDE the `auth` middleware group; every
| controller also checks the patient profile, ownership and the feature
| switch (Staff → System → Patient health settings).
*/

use App\Http\Controllers\Account\Health\AppointmentToolsController;
use App\Http\Controllers\Account\Health\FamilyMembersController;
use App\Http\Controllers\Account\Health\FavouriteDoctorsController;
use App\Http\Controllers\Account\Health\MedicalIdController;
use App\Http\Controllers\Account\Health\MedicationsController;
use App\Http\Controllers\Account\Health\VitalsController;
use Illuminate\Support\Facades\Route;

Route::prefix('account')->name('account.health.')->group(function () {
    Route::get('/my-doctors', [FavouriteDoctorsController::class, 'index'])->name('favourites');
    Route::post('/my-doctors/{doctor}', [FavouriteDoctorsController::class, 'toggle'])->whereNumber('doctor')->name('favourites.toggle');

    Route::get('/family', [FamilyMembersController::class, 'index'])->name('family');
    Route::post('/family', [FamilyMembersController::class, 'store'])->name('family.store');
    Route::put('/family/{member}', [FamilyMembersController::class, 'update'])->whereNumber('member')->name('family.update');
    Route::delete('/family/{member}', [FamilyMembersController::class, 'destroy'])->whereNumber('member')->name('family.destroy');

    Route::get('/medications', [MedicationsController::class, 'index'])->name('medications');
    Route::post('/medications', [MedicationsController::class, 'store'])->name('medications.store');
    Route::put('/medications/{medication}', [MedicationsController::class, 'update'])->whereNumber('medication')->name('medications.update');
    Route::delete('/medications/{medication}', [MedicationsController::class, 'destroy'])->whereNumber('medication')->name('medications.destroy');
    Route::post('/medications/{medication}/taken', [MedicationsController::class, 'taken'])->whereNumber('medication')->name('medications.taken');

    Route::get('/vitals', [VitalsController::class, 'index'])->name('vitals');
    Route::post('/vitals', [VitalsController::class, 'store'])->name('vitals.store');
    Route::delete('/vitals/{reading}', [VitalsController::class, 'destroy'])->whereNumber('reading')->name('vitals.destroy');

    Route::get('/medical-id', [MedicalIdController::class, 'show'])->name('medical-id');
    Route::post('/medical-id', [MedicalIdController::class, 'update'])->name('medical-id.update');

    Route::get('/appointments/{appointment}/reschedule', [AppointmentToolsController::class, 'reschedule'])->whereNumber('appointment')->name('reschedule');
    Route::get('/appointments/{appointment}/reschedule/slots', [AppointmentToolsController::class, 'slots'])->whereNumber('appointment')->name('reschedule.slots');
    Route::post('/appointments/{appointment}/reschedule', [AppointmentToolsController::class, 'storeReschedule'])->whereNumber('appointment')->name('reschedule.store');
    Route::get('/appointments/{appointment}/calendar.ics', [AppointmentToolsController::class, 'calendar'])->whereNumber('appointment')->name('calendar');
});
