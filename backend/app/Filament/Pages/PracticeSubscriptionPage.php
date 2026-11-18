<?php

namespace App\Filament\Pages;

use App\Models\PaymentIntent;
use App\Models\PracticePlan;
use App\Models\PracticeSubscription as Sub;
use App\Models\User;
use App\Services\Billing\PracticeBilling;
use App\Services\Billing\PracticeSubscription as Gate;
use App\Services\Payments\PaymentService;
use App\Support\Money;
use App\Support\PracticeBillingSettings;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Practice side of practice billing: clinic admins (per clinic they run) and
 * independent doctors (no clinic) choose a plan, start a trial, subscribe by
 * card (PayFast recurring, through the existing payment sheet), see receipts,
 * update their card and cancel at the end of the period. Strictly scoped to
 * the practices PracticeBilling::managedBy() returns for the logged-in user.
 */
class PracticeSubscriptionPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';

    protected static string|UnitEnum|null $navigationGroup = null;

    protected static ?string $navigationLabel = 'Practice subscription';

    protected static ?string $title = 'Practice subscription';

    protected static ?string $slug = 'practice-subscription';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.practice-subscription';

    public ?string $practice = null;

    public string $cycle = 'monthly';

    public ?string $payUrl = null;

    public ?string $payIntent = null;

    public static function canAccess(): bool
    {
        $u = Auth::user();

        return $u instanceof User && ($u->isClinicAdmin() || $u->isDoctor()) && PracticeBilling::managedBy($u) !== [];
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $first = PracticeBilling::managedBy(Auth::user())[0];
        $this->practice = $first['type'].':'.$first['id'];
    }

    /** @return array{type: string, id: int, label: string} */
    protected function current(): array
    {
        foreach (PracticeBilling::managedBy(Auth::user()) as $m) {
            if ($m['type'].':'.$m['id'] === $this->practice) {
                return $m;
            }
        }
        abort(403);
    }

    protected function subscriber()
    {
        $m = $this->current();
        $p = PracticeBilling::resolveSubscriber($m['type'], $m['id']);
        abort_unless($p, 404);

        return $p;
    }

    protected function row(): ?Sub
    {
        return app(PracticeBilling::class)->rowFor($this->subscriber());
    }

    protected function svc(): PracticeBilling
    {
        return app(PracticeBilling::class);
    }

    protected function attempt(callable $fn, string $ok): void
    {
        try {
            $fn();
            Notification::make()->title($ok)->success()->send();
        } catch (\Throwable $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    protected function plan(int $id): PracticePlan
    {
        $m = $this->current();
        $plan = PracticePlan::query()->active()->for($m['type'])->find($id);
        abort_unless($plan, 404);

        return $plan;
    }

    public function updatedPractice(): void
    {
        $this->current();
        $this->payUrl = null;
        $this->payIntent = null;
    }

    public function startTrial(int $planId): void
    {
        $this->attempt(fn () => $this->svc()->startTrial($this->subscriber(), $this->plan($planId), Auth::user()), 'Free trial started');
    }

    public function subscribe(int $planId): void
    {
        try {
            [, $intent, $token] = $this->svc()->checkout(Auth::user(), $this->subscriber(), $this->plan($planId), $this->cycle);
            $this->payIntent = $intent->uuid;
            $this->payUrl = app(PaymentService::class)->embedUrl($intent, $token);
        } catch (\Throwable $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public function changePlan(int $planId): void
    {
        $row = $this->row();
        abort_unless($row, 404);
        $this->attempt(fn () => $this->svc()->changePlan($row, $this->plan($planId), (string) $row->billing_cycle, Auth::user()), 'Plan changed — the new amount applies from your next payment');
    }

    public function syncSeats(): void
    {
        $row = $this->row();
        abort_unless($row, 404);
        $this->attempt(fn () => $this->svc()->syncSeats($row, Auth::user()), 'Seats updated');
    }

    public function cancelSubscription(): void
    {
        $row = $this->row();
        abort_unless($row, 404);
        $this->attempt(fn () => $this->svc()->cancel($row, Auth::user(), 'Cancelled by the practice'), 'Subscription cancelled');
    }

    /** The payment sheet reported back (postMessage) or the practice closed it. */
    public function paymentFinished(?string $event = null): void
    {
        $this->payUrl = null;
        $this->checkPending();
    }

    /** Polled while a checkout is open / confirming: server-side status check, never the browser's word. */
    public function checkPending(): void
    {
        if (! $this->payIntent) {
            return;
        }
        $intent = PaymentIntent::query()->where('uuid', $this->payIntent)->first();
        if (! $intent || (int) $intent->user_id !== (int) Auth::id()) {
            $this->payIntent = null;

            return;
        }
        $intent = app(PaymentService::class)->refresh($intent);
        if ($intent->status === PaymentIntent::STATUS_PAID) {
            $this->payIntent = null;
            $this->payUrl = null;
            Notification::make()->title('Payment received — your subscription is active')->success()->send();
        } elseif (in_array($intent->status, [PaymentIntent::STATUS_FAILED, PaymentIntent::STATUS_CANCELLED], true)) {
            $this->payIntent = null;
            Notification::make()->title($intent->status === PaymentIntent::STATUS_FAILED ? ($intent->failure_reason ?: 'The payment did not go through') : 'Payment cancelled')->warning()->send();
        }
    }

    protected function getViewData(): array
    {
        $m = $this->current();
        $p = $this->subscriber();
        $row = $this->row();
        $doctors = PracticeBilling::doctorCount($p);
        $plans = PracticePlan::query()->active()->for($m['type'])->orderBy('sort_order')->orderBy('price_cents')->get();
        $isCard = $row && $row->token && $row->status === Sub::STATUS_ACTIVE && ! $row->cancel_at_period_end;
        $trialUsed = $row && ($row->trial_ends_at !== null || $row->last_paid_at !== null);

        $planCards = $plans->map(function (PracticePlan $plan) use ($row, $doctors, $isCard, $trialUsed) {
            $seats = $plan->seatsFor($doctors);
            $cycle = $this->cycle === 'annual' && $plan->hasAnnual() ? 'annual' : 'monthly';
            $current = $row && (int) $row->practice_plan_id === (int) $plan->id && in_array($row->status, [Sub::STATUS_ACTIVE, Sub::STATUS_TRIALING, Sub::STATUS_PAST_DUE, Sub::STATUS_GRACE], true);

            return [
                'id' => $plan->id,
                'name' => $plan->name,
                'description' => $plan->description,
                'price' => Money::format($plan->priceFor($cycle, $seats)).($cycle === 'annual' ? ' / year' : ' / month'),
                'basis' => $plan->isPerSeat() ? $seats.' doctor seat'.($seats === 1 ? '' : 's').' ('.$plan->included_seats.' included'.($plan->extra_seat_cents > 0 ? ', '.Money::format((int) $plan->extra_seat_cents).' per extra doctor / month' : '').')' : 'Flat price for the practice',
                'features' => array_values(array_filter(array_merge((array) ($plan->features ?? []), [
                    $plan->receives_instant_requests ? 'Instant patient requests' : null,
                    $plan->listed_in_search ? 'Listed in search' : null,
                    $plan->accepts_bookings ? 'Online bookings' : null,
                    $plan->max_doctors ? 'Up to '.$plan->max_doctors.' doctors' : null,
                ]))),
                'over_limit' => $plan->max_doctors && $doctors > $plan->max_doctors,
                'trial_days' => (int) $plan->trial_days,
                'current' => $current,
                'can_trial' => ! $trialUsed && (int) $plan->trial_days > 0 && ! $current,
                'can_subscribe' => ! $isCard && (! $current || $row->status !== Sub::STATUS_ACTIVE || $row->cancel_at_period_end || ! $row->last_paid_at),
                'can_switch' => $isCard && ! $current,
                'annual_only_monthly' => $this->cycle === 'annual' && ! $plan->hasAnnual(),
            ];
        })->all();

        $status = $row ? [
            'label' => $row->isComped() ? 'Waived by PromptAid' : $row->statusLabel(),
            'color' => match (true) {
                $row->isComped() => '#1D5E99',
                in_array($row->status, [Sub::STATUS_ACTIVE], true) => '#1F7A4C',
                in_array($row->status, [Sub::STATUS_TRIALING], true) => '#1D5E99',
                in_array($row->status, [Sub::STATUS_PAST_DUE, Sub::STATUS_GRACE], true) => '#B07900',
                $row->status === Sub::STATUS_SUSPENDED => '#C8102E',
                default => '#6E6A62',
            },
            'plan' => $row->plan?->name,
            'amount' => $row->amount_cents > 0 ? $row->amountLabel() : null,
            'seats' => (int) $row->seats,
            'trial_ends' => $row->status === Sub::STATUS_TRIALING ? $row->trial_ends_at?->format('d M Y') : null,
            'trial_days_left' => $row->status === Sub::STATUS_TRIALING && $row->trial_ends_at ? max(0, (int) ceil(now()->diffInHours($row->trial_ends_at, false) / 24)) : null,
            'paid_until' => $row->current_period_end?->format('d M Y'),
            'next_billing' => $row->next_billing_at?->format('d M Y'),
            'grace_ends' => in_array($row->status, [Sub::STATUS_PAST_DUE, Sub::STATUS_GRACE], true) ? $row->grace_ends_at?->format('d M Y') : null,
            'cancelling' => $row->cancel_at_period_end,
            'comped_until' => $row->isComped() ? $row->comped_until->format('d M Y') : null,
            'card' => (bool) $row->token,
            'can_cancel' => $row->status !== Sub::STATUS_CANCELLED && ! $row->cancel_at_period_end && in_array($row->status, [Sub::STATUS_ACTIVE, Sub::STATUS_TRIALING, Sub::STATUS_PAST_DUE, Sub::STATUS_GRACE], true),
            'needs_payment' => in_array($row->status, [Sub::STATUS_PAST_DUE, Sub::STATUS_GRACE, Sub::STATUS_SUSPENDED], true),
            'seat_mismatch' => $row->plan && $row->plan->isPerSeat() && $row->plan->seatsFor($doctors) !== (int) $row->seats,
            'update_card_url' => $this->svc()->updateCardUrl($row, PracticeBilling::pageUrl()),
        ] : null;

        return [
            'practices' => PracticeBilling::managedBy(Auth::user()),
            'status' => $status,
            'doctors' => $doctors,
            'receiving' => Gate::canReceiveRequests($p),
            'enforced' => PracticeBillingSettings::enforced(),
            'planCards' => $planCards,
            'hasAnnual' => $plans->contains(fn (PracticePlan $pl) => $pl->hasAnnual()),
            'invoices' => $row ? $row->invoices()->latest('id')->limit(24)->get()->map(fn ($i) => [
                'number' => $i->number, 'date' => ($i->paid_at ?? $i->created_at)?->format('d M Y'), 'description' => $i->description,
                'period' => ($i->period_start?->format('d M Y') ?? '—').' – '.($i->period_end?->format('d M Y') ?? '—'),
                'amount' => $i->amountLabel(), 'status' => \App\Models\PracticeInvoice::STATUSES[$i->status] ?? $i->status,
                'url' => route('practice-billing.receipt', $i),
            ])->all() : [],
            'events' => $row ? $row->events()->latest('id')->limit(10)->get()->map(fn ($e) => ['at' => $e->created_at?->format('d M Y H:i'), 'message' => $e->message])->all() : [],
            'pendingIntent' => $this->payIntent,
        ];
    }
}
