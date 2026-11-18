<?php

/*
| Practice billing (platform subscription paid by practices) — website side.
| Required from routes/web.php. The practice page itself is a Filament page
| (/staff/practice-subscription); admin pages live under Staff → Finance.
*/

use App\Http\Controllers\PracticeBillingReceiptController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'throttle:60,1'])->group(function () {
    Route::get('/staff/practice-billing/receipts/{invoice}', PracticeBillingReceiptController::class)
        ->whereNumber('invoice')->name('practice-billing.receipt');
});
