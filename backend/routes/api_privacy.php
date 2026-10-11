<?php

/*
 * POPIA / privacy API routes. Required from routes/api.php INSIDE the
 * `auth:sanctum` group, so every route here is token-authenticated.
 */

use App\Http\Controllers\Api\PrivacyController;
use Illuminate\Support\Facades\Route;

Route::prefix('privacy')->group(function () {
    Route::get('/consents', [PrivacyController::class, 'consents']);
    Route::get('/consents/required', [PrivacyController::class, 'required']);
    Route::post('/consents', [PrivacyController::class, 'updateConsents']);
    Route::get('/requests', [PrivacyController::class, 'requests']);
    Route::middleware('throttle:6,1')->post('/requests', [PrivacyController::class, 'storeRequest']);
    Route::get('/requests/{dsr}/download', [PrivacyController::class, 'download'])->whereNumber('dsr');
    Route::get('/access-log', [PrivacyController::class, 'accessLog']);
});
