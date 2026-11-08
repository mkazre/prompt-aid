<?php

/*
| Finance API (provider earnings, payouts, bank details). Required from
| routes/api.php inside the auth:sanctum group. Scoped to the caller's own
| ledger account(s) — see App\Http\Controllers\Api\FinanceController.
*/

use App\Http\Controllers\Api\FinanceController;
use Illuminate\Support\Facades\Route;

Route::prefix('finance')->group(function () {
    Route::get('/earnings', [FinanceController::class, 'earnings'])->middleware('throttle:60,1');
    Route::get('/payouts', [FinanceController::class, 'payouts'])->middleware('throttle:60,1');
    Route::get('/payout-account', [FinanceController::class, 'payoutAccount'])->middleware('throttle:60,1');
    Route::post('/payout-account', [FinanceController::class, 'savePayoutAccount'])->middleware('throttle:10,1');
});
