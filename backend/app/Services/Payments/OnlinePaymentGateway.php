<?php

namespace App\Services\Payments;

use App\Contracts\Payable;
use App\Contracts\PaymentGatewayInterface;
use App\Models\PaymentEvent;
use App\Models\PaymentIntent;
use App\Services\Payments\Gateways\PaystackGateway;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the original synchronous PaymentGatewayInterface callers working
 * (InvoiceController::pay, OrderService::pay, DoctorMatchService::pay,
 * RideChargeService) now that real gateways exist:
 *
 *  - No gateway configured  → behaves exactly like MockPaymentGateway.
 *  - Gateway(s) live        → charge() never claims success synchronously.
 *    If the user has a card on file (PayFast token / Paystack reusable
 *    authorisation) an ad-hoc charge is submitted and the webhook settles
 *    the payable; otherwise it returns `pending` and the patient pays from
 *    the payment sheet. Result carries 'pending' => true in both cases.
 */
class OnlinePaymentGateway implements PaymentGatewayInterface
{
    public function __construct(
        protected GatewayManager $gateways,
        protected MockPaymentGateway $mock,
    ) {}

    public function charge(Payable $payable, string $method): array
    {
        if ($this->gateways->mockActive() || ! $this->gateways->onlineActive()) {
            return $this->mock->charge($payable, $method) + ['gateway' => 'mock'];
        }

        $pending = [
            'success' => false,
            'pending' => true,
            'reference' => null,
            'gateway' => 'online',
            'message' => 'Awaiting online payment.',
        ];

        if (! $payable instanceof Model) {
            return $pending;
        }

        try {
            $user = $payable->patient?->user ?? $payable->user ?? null;
            if (! $user) {
                return $pending;
            }
            $subs = app(SubscriptionService::class);
            $card = $subs->cardTokenFor($user);
            if (! $card) {
                return $pending;
            }

            $payables = app(Payables::class);
            $intent = PaymentIntent::query()->create([
                'user_id' => $user->id,
                'payable_type' => $payable->getMorphClass(),
                'payable_id' => $payable->getKey(),
                'amount' => $payables->amountFor($payable),
                'gateway' => $card->gateway,
                'channel' => 'server',
                'status' => PaymentIntent::STATUS_PROCESSING,
                'description' => $payables->describe($payable),
                'metadata' => ['card_on_file' => true],
            ]);
            $result = $card->gateway === 'payfast'
                ? $this->gateways->payfast()->adhocCharge((string) $card->token, $intent)
                : (new PaystackGateway)->chargeAuthorization($intent, (string) $card->token);

            PaymentEvent::query()->create([
                'payment_intent_id' => $intent->id, 'gateway' => $card->gateway, 'event_type' => 'card_on_file_charge',
                'reference' => $intent->reference, 'verified' => true, 'verification_note' => 'Outbound API call',
                'outcome' => $result['success'] ? 'submitted' : 'error', 'payload' => ['message' => $result['message']],
            ]);
            if (! $result['success']) {
                $intent->update(['status' => PaymentIntent::STATUS_FAILED, 'failure_reason' => mb_substr($result['message'], 0, 255)]);

                return $pending;
            }

            return ['reference' => $intent->reference, 'gateway' => $card->gateway, 'message' => 'Charged to card on file; awaiting confirmation.'] + $pending;
        } catch (\Throwable $e) {
            Log::warning('[Payments] card-on-file charge failed: '.$e->getMessage());

            return $pending;
        }
    }

    public function refund(string $reference, float $amount): array
    {
        if ($reference === '' || str_starts_with($reference, 'MOCK-')) {
            return $this->mock->refund($reference, $amount);
        }
        $intent = PaymentIntent::query()
            ->where(fn ($q) => $q->where('gateway_ref', $reference)->orWhere('reference', $reference))
            ->where('status', PaymentIntent::STATUS_PAID)->latest('id')->first();
        if (! $intent) {
            return ['success' => false, 'message' => "No online payment found for {$reference}."];
        }

        return app(PaymentService::class)->refund($intent, $amount, 'Refund');
    }
}
