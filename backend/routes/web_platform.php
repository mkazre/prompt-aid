<?php

/*
 * Platform web routes (reports, CSV exports, login activity). Required from
 * routes/web.php INSIDE the `auth` middleware group, so every route here is
 * authenticated; the staff downloads also check Filament permissions in
 * their controllers.
 */

use App\Http\Controllers\Account\SecurityController;
use App\Http\Controllers\Platform\CsvExportController;
use App\Http\Controllers\Platform\ReportExportController;
use Illuminate\Support\Facades\Route;

// Staff downloads (streamed CSV, no queue worker needed).
Route::get('/staff/exports/csv/{token}', CsvExportController::class)
    ->where('token', '[A-Za-z0-9]{40}')
    ->name('platform.csv-export');
Route::get('/staff/report-exports/{section}', ReportExportController::class)
    ->where('section', '[a-z]+')
    ->name('platform.report-export');

// Login activity + "sign out other devices" (any signed-in user).
Route::get('/account/security', [SecurityController::class, 'index'])->name('account.security');
Route::middleware('throttle:6,1')->post('/account/security/logout-others', [SecurityController::class, 'logoutOthers'])->name('account.security.logout-others');
