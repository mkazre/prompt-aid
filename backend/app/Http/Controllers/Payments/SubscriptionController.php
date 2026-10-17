<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\Payments\PaymentService;
use App\Services\Payments\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/** Subscription plans + the user's subscriptions (app API and website JSON). */
class SubscriptionController extends Controller
{
    public function __construct(
        protected SubscriptionService $subs,
        protected PaymentService $payments,
    ) {}

    /** GET subscription-plans?scope= (public) */
    public function plans(Request $request): JsonResponse
    {
        $scope = $request->query('scope');
        $plans = SubscriptionPlan::query()->active()
            ->when(is_string($scope) && $scope !== '', fn ($q) => $q->where('scope', $scope))
            ->orderBy('sort_order')->orderBy('amount')->get();

        return response()->json(['data' => $plans->map->toApi()->values()]);
    }

    /** GET subscriptions — mine */
    public function index(Request $request): JsonResponse
    {
        $rows = Subscription::query()->with('plan')->where('user_id', $request->user()->id)
            ->where('kind', Subscription::KIND_RECURRING)
            ->where('status', '!=', Subscription::STATUS_PENDING)
            ->latest()->get();
        $card = $this->subs->cardTokenFor($request->user());

        return response()->json([
            'data' => $rows->map->toApi()->values(),
            'card_on_file' => $card ? ['gateway' => $card->gateway, 'saved_at' => $card->updated_at?->toIso8601String()] : null,
        ]);
    }

    /** POST subscriptions {plan_id, gateway?, idempotency_key?, subject_type?, subject_id?} */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:subscription_plans,id'],
            'gateway' => ['nullable', 'string', 'in:payfast,mock'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
            'subject_type' => ['nullable', 'string', 'in:ride_series,order,appointment'],
            'subject_id' => ['nullable', 'integer'],
        ]);
        $user = $request->user();
        $plan = SubscriptionPlan::query()->findOrFail($data['plan_id']);

        try {
            [$sub, $intent, $token] = $this->subs->subscribe(
                $user, $plan, $data['gateway'] ?? null, $request->is('api/*') ? 'app' : 'web',
                $data['subject_type'] ?? null, $data['subject_id'] ?? null,
                isset($data['idempotency_key']) ? $user->id.':sub:'.$data['idempotency_key'] : null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'subscription' => $sub->toApi(),
            'intent' => $this->payments->present($intent),
            'embed_url' => $this->payments->embedUrl($intent, $token),
            'return_prefix' => url('/pay/return/'.$intent->uuid),
        ], 201);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        return $this->act($request, $id, fn (Subscription $s) => $this->subs->cancel($s, (string) ($request->input('reason') ?: 'Cancelled by user')));
    }

    public function pause(Request $request, int $id): JsonResponse
    {
        return $this->act($request, $id, fn (Subscription $s) => $this->subs->pause($s));
    }

    public function resume(Request $request, int $id): JsonResponse
    {
        return $this->act($request, $id, fn (Subscription $s) => $this->subs->resume($s));
    }

    protected function act(Request $request, int $id, callable $fn): JsonResponse
    {
        $sub = Subscription::query()->where('user_id', $request->user()->id)->find($id);
        abort_unless($sub, 404);
        try {
            $sub = $fn($sub);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $sub->toApi()]);
    }
}
