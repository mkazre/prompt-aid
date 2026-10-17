<?php

/*
| Online payments — website side. Required from routes/web.php (before the
| page-builder catch-all). /pay/* pages are token-authorised (no session)
| so they also work inside the app's in-app WebView; CSRF-exempt in
| bootstrap/app.php because gateways POST back to /pay/return/*.
*/

use App\Http\Controllers\Account\SubscriptionsController;
use App\Http\Controllers\Payments\PaymentEmbedController;
use App\Http\Controllers\Payments\PaymentIntentController;
use App\Http\Controllers\Payments\SubscriptionController;
use Illuminate\Support\Facades\Route;

Route::get('/pay/{intent}/embed', [PaymentEmbedController::class, 'embed'])->whereUuid('intent')->middleware('throttle:60,1')->name('payments.embed');
Route::post('/pay/{intent}/mock', [PaymentEmbedController::class, 'mock'])->whereUuid('intent')->middleware('throttle:20,1')->name('payments.mock');
Route::match(['get', 'post'], '/pay/return/{intent}/{outcome}', [PaymentEmbedController::class, 'returnPage'])
    ->whereUuid('intent')->whereIn('outcome', ['success', 'cancel', 'error'])->middleware('throttle:60,1')->name('payments.return');

Route::middleware('auth')->group(function () {
    Route::prefix('payments')->name('payments.')->group(function () {
        Route::get('/options', [PaymentIntentController::class, 'options'])->name('options');
        Route::middleware('throttle:20,1')->post('/intents', [PaymentIntentController::class, 'store'])->name('intents.store');
        Route::middleware('throttle:120,1')->get('/intents/{intent}', [PaymentIntentController::class, 'show'])->whereUuid('intent')->name('intents.show');
        Route::post('/intents/{intent}/cancel', [PaymentIntentController::class, 'cancel'])->whereUuid('intent')->name('intents.cancel');
        Route::middleware('throttle:10,1')->post('/subscriptions', [SubscriptionController::class, 'store'])->name('subscriptions.store');
    });

    Route::get('/account/subscriptions', [SubscriptionsController::class, 'index'])->name('account.subscriptions');
    Route::middleware('throttle:20,1')->post('/account/subscriptions/{subscription}/{action}', [SubscriptionsController::class, 'action'])
        ->whereNumber('subscription')->whereIn('action', ['cancel', 'pause', 'resume'])->name('account.subscriptions.action');
});
