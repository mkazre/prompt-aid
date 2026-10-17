<?php

/*
| Online payments (PayFast / Paystack / Ozow / mock) — required from
| routes/api.php at top level; auth groups are declared here.
|
| Gateway dashboards must point at:
|   PayFast ITN (notify_url, sent per payment): {APP_URL}/api/payments/webhooks/payfast
|   Paystack webhook URL:                       {APP_URL}/api/payments/webhooks/paystack
|   Ozow notify URL (sent per payment):         {APP_URL}/api/payments/webhooks/ozow
*/

use App\Http\Controllers\Payments\PaymentIntentController;
use App\Http\Controllers\Payments\PaymentWebhookController;
use App\Http\Controllers\Payments\SubscriptionController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:240,1')
    ->post('/payments/webhooks/{gateway}', PaymentWebhookController::class)
    ->whereIn('gateway', ['payfast', 'paystack', 'ozow'])
    ->name('api.payments.webhook');

Route::middleware('throttle:60,1')->get('/subscription-plans', [SubscriptionController::class, 'plans']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/payments/options', [PaymentIntentController::class, 'options']);
    Route::middleware('throttle:20,1')->post('/payments/intents', [PaymentIntentController::class, 'store']);
    Route::middleware('throttle:120,1')->get('/payments/intents/{intent}', [PaymentIntentController::class, 'show'])->whereUuid('intent');
    Route::post('/payments/intents/{intent}/cancel', [PaymentIntentController::class, 'cancel'])->whereUuid('intent');

    Route::get('/subscriptions', [SubscriptionController::class, 'index']);
    Route::middleware('throttle:10,1')->post('/subscriptions', [SubscriptionController::class, 'store']);
    Route::middleware('throttle:20,1')->post('/subscriptions/{id}/cancel', [SubscriptionController::class, 'cancel'])->whereNumber('id');
    Route::middleware('throttle:20,1')->post('/subscriptions/{id}/pause', [SubscriptionController::class, 'pause'])->whereNumber('id');
    Route::middleware('throttle:20,1')->post('/subscriptions/{id}/resume', [SubscriptionController::class, 'resume'])->whereNumber('id');
});
