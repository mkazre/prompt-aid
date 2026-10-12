<?php

/*
 * Commerce & alerts web routes (promo codes, referrals, printable
 * invoices). Required from routes/web.php INSIDE the `auth` middleware
 * group; the one signed (app) print link opts out of `auth` explicitly.
 */

use App\Http\Controllers\Account\ReferralsController;
use App\Http\Controllers\InvoicePrintController;
use App\Http\Controllers\PromoCodeWebController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:30,1')->post('/promo-codes/validate', [PromoCodeWebController::class, 'validateCode'])->name('promo-codes.validate');

Route::prefix('account')->name('account.')->group(function () {
    Route::get('/referrals', [ReferralsController::class, 'index'])->name('referrals');
    Route::get('/invoices/{invoice}/print', [InvoicePrintController::class, 'account'])->whereNumber('invoice')->name('invoices.print');
    Route::get('/orders/{order}/print', [InvoicePrintController::class, 'order'])->whereNumber('order')->name('orders.print');
});

// Staff "Print" action on the Invoices resource (permission + tenant scope checked in the controller).
Route::get('/staff/print/invoices/{invoice}', [InvoicePrintController::class, 'staff'])->whereNumber('invoice')->name('staff.invoices.print');

// Temporary signed link the app opens in the browser (no web session there).
Route::get('/print/invoices/{invoice}', [InvoicePrintController::class, 'signed'])
    ->withoutMiddleware('auth')
    ->middleware(['signed', 'throttle:30,1'])
    ->whereNumber('invoice')
    ->name('invoices.print.signed');
