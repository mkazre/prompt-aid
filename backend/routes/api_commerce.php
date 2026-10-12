<?php

/*
 * Commerce & alerts API routes (promo codes, referrals, printable invoices,
 * doctor "running late"). Required from routes/api.php INSIDE the
 * `auth:sanctum` group, so every route here is authenticated.
 */

use App\Http\Controllers\Api\CommerceController;
use App\Http\Controllers\Api\PromoCodeController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::get('/referrals', [CommerceController::class, 'referrals']);

Route::middleware('role:'.User::ROLE_PATIENT)->group(function () {
    Route::middleware('throttle:30,1')->post('/promo-codes/validate', [PromoCodeController::class, 'validateCode']);
    Route::get('/invoices/{id}/print-link', [CommerceController::class, 'invoicePrintLink'])->whereNumber('id');
});

Route::middleware('role:'.User::ROLE_DOCTOR)->prefix('doctor')->group(function () {
    Route::get('/running-late', [CommerceController::class, 'runningLateInfo']);
    Route::middleware('throttle:10,1')->post('/running-late', [CommerceController::class, 'runningLate']);
});
