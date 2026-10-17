<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\Payments\SubscriptionService;
use Illuminate\Http\Request;
use RuntimeException;

/** Website: /account/subscriptions — plans you can join + the ones you have. */
class SubscriptionsController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $subscriptions = Subscription::query()->with('plan')->where('user_id', $user->id)
            ->where('kind', Subscription::KIND_RECURRING)
            ->where('status', '!=', Subscription::STATUS_PENDING)
            ->latest()->get();
        $activePlanIds = $subscriptions->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAUSED])->pluck('subscription_plan_id')->all();
        $plans = SubscriptionPlan::query()->active()->orderBy('sort_order')->orderBy('amount')->get()
            ->reject(fn ($p) => in_array($p->id, $activePlanIds, true))->values();
        $cardOnFile = app(SubscriptionService::class)->cardTokenFor($user);

        return view('account.subscriptions', compact('subscriptions', 'plans', 'cardOnFile'));
    }

    public function action(Request $request, Subscription $subscription, string $action)
    {
        abort_unless((int) $subscription->user_id === (int) $request->user()->id, 404);
        $svc = app(SubscriptionService::class);

        try {
            match ($action) {
                'cancel' => $svc->cancel($subscription, 'Cancelled by user (website)'),
                'pause' => $svc->pause($subscription),
                'resume' => $svc->resume($subscription),
                default => abort(404),
            };
        } catch (RuntimeException $e) {
            return back()->withErrors(['subscription' => $e->getMessage()]);
        }

        return back()->with('success', match ($action) {
            'cancel' => 'Your subscription was cancelled. No further payments will be taken.',
            'pause' => 'Your subscription is paused.',
            default => 'Your subscription is active again.',
        });
    }
}
