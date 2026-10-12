<?php

/*
 * Platform API routes: feature switches and login activity / security.
 * Required from routes/api.php at the TOP level (not inside the
 * auth:sanctum group), because GET /features is public — the app reads it
 * before sign-in. Authenticated routes declare auth:sanctum themselves.
 */

use App\Http\Controllers\Api\FeaturesController;
use App\Http\Controllers\Api\SecurityController;
use Illuminate\Support\Facades\Route;

Route::get('/features', FeaturesController::class)->name('api.features');

Route::middleware('auth:sanctum')->prefix('security')->name('api.security.')->group(function () {
    Route::get('/logins', [SecurityController::class, 'logins'])->name('logins');
    Route::middleware('throttle:6,1')->post('/logout-others', [SecurityController::class, 'logoutOthers'])->name('logout-others');
});
