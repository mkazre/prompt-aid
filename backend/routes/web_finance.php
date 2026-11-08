<?php

/*
| Finance — website side (provider statements + driver console Earnings).
| Required from routes/web.php (before the page-builder catch-all).
*/

use App\Http\Controllers\Finance\EarningsController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/finance/statement/{format}', [EarningsController::class, 'statement'])
        ->whereIn('format', ['csv', 'pdf'])->middleware('throttle:30,1')->name('finance.statement');

    Route::middleware('role:'.\App\Models\User::ROLE_DRIVER)->prefix('driver')->name('driver.')->group(function () {
        Route::get('/earnings', [EarningsController::class, 'driver'])->name('earnings');
        Route::post('/earnings/bank', [EarningsController::class, 'driverBank'])->middleware('throttle:10,1')->name('earnings.bank');
    });
});
