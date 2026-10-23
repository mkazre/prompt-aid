<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\PaymentIntent;
use App\Services\Payments\PayableIntent;
use App\Services\Payments\PaymentService;
use App\Support\PaymentSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * JSON endpoints behind the payment sheet — served both to the app
 * (/api/payments/*, Sanctum) and the website (/payments/*, session).
 */
class PaymentIntentController extends Controller
{
    public function __construct(protected PaymentService $payments) {}

    protected function channel(Request $request): string
    {
        return $request->is('api/*') ? 'app' : 'web';
    }

    /** GET payments/options?service=pharmacy|invoice|ride|subscription */
    public function options(Request $request): JsonResponse
    {
        $service = (string) $request->query('service', 'invoice');
        if (! array_key_exists($service, PaymentSettings::SERVICES)) {
            $service = 'invoice';
        }
        $gm = $this->payments->gateways();
        $methods = $gm->optionsFor($service, $this->channel($request));

        return response()->json([
            'service' => $service,
            'mock' => $gm->mockActive(),
            'online' => $gm->onlineActive(),
            'methods' => $methods,
            'save_card_supported' => $service !== 'subscription' && collect($methods)->contains(fn ($m) => in_array($m['key'], ['payfast', 'paystack'], true)),
        ]);
    }

    /** POST payments/intents {payable_type, payable_id, gateway?, idempotency_key?, save_card?} */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'payable_type' => ['required', 'in:invoice,order,ride_charge,consult,ride'],
            'payable_id' => ['required', 'integer', 'min:1'],
            'gateway' => ['nullable', 'string', 'in:payfast,paystack,ozow,mock'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
            'save_card' => ['nullable', 'boolean'],
        ]);
        $user = $request->user();

        try {
            $payables = $this->payments->payables();
            $payable = $payables->resolve($data['payable_type'], (int) $data['payable_id'], $user);
            $gateway = $data['gateway'] ?? null;
            $meta = [];
            if (! empty($data['save_card'])) {
                $meta['save_card'] = true;
                if (($gateway ?? 'payfast') === 'payfast' && PaymentSettings::bool('payfast_tokenise_cards')) {
                    $meta['payfast_subscription'] = ['type' => 2];
                }
            }

            [$intent, $token] = $this->payments->start(new PayableIntent(
                user: $user,
                payable: $payable,
                service: $payables->serviceFor($payable),
                amount: $payables->amountFor($payable),
                description: $payables->describe($payable),
                gateway: $gateway,
                channel: $this->channel($request),
                idempotencyKey: isset($data['idempotency_key']) ? $user->id.':'.$data['idempotency_key'] : null,
                metadata: $meta,
            ));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'intent' => $this->payments->present($intent),
            'embed_url' => $this->payments->embedUrl($intent, $token),
            'return_prefix' => url('/pay/return/'.$intent->uuid),
        ], 201);
    }

    /** GET payments/intents/{uuid} — poll; may trigger a server-side status query. */
    public function show(Request $request, string $intent): JsonResponse
    {
        $model = $this->owned($request, $intent);
        $model = $this->payments->refresh($model);

        return response()->json(['intent' => $this->payments->present($model)]);
    }

    public function cancel(Request $request, string $intent): JsonResponse
    {
        $model = $this->owned($request, $intent);

        return response()->json(['intent' => $this->payments->present($this->payments->cancelByUser($model))]);
    }

    protected function owned(Request $request, string $uuid): PaymentIntent
    {
        $model = PaymentIntent::query()->where('uuid', $uuid)->first();
        abort_unless($model && $this->payments->userCanSee($model, $request->user()), 404);

        return $model;
    }
}
