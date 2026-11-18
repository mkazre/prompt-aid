<?php

namespace App\Filament\Pages;

use App\Filament\Resources\PracticeSubscriptions\PracticeSubscriptionResource;
use App\Models\Clinic;
use App\Models\DoctorProfile;
use App\Models\PracticeInvoice;
use App\Models\PracticeSubscription as Sub;
use App\Services\Billing\PracticeBilling;
use App\Support\Money;
use App\Support\PracticeBillingSettings as S;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/** Staff → Finance → Practice billing: MRR, status counts, upcoming renewals, failed payments. Super admin only. */
class PracticeBillingOverview extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Practice billing';

    protected static ?string $title = 'Practice billing';

    protected static ?string $slug = 'practice-billing';

    protected static ?int $navigationSort = 19;

    protected string $view = 'filament.pages.practice-billing-overview';

    public static function canAccess(): bool
    {
        $u = Auth::user();

        return (bool) ($u?->isSuperAdmin() && $u->hasPermission('practice-billing.view'));
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function runCheck(): void
    {
        abort_unless(static::canAccess(), 403);
        $n = app(PracticeBilling::class)->tick();
        Notification::make()->title('Billing check done: '.$n.' status change(s)')->success()->send();
    }

    protected function getViewData(): array
    {
        $rows = Sub::query()->with('plan')->get();
        $mrr = 0;
        foreach ($rows as $r) {
            if (in_array($r->status, [Sub::STATUS_ACTIVE, Sub::STATUS_PAST_DUE, Sub::STATUS_GRACE], true) && ! $r->isComped() && ! $r->cancel_at_period_end) {
                $mrr += $r->billing_cycle === 'annual' ? intdiv((int) $r->amount_cents, 12) : (int) $r->amount_cents;
            }
        }
        $counts = [];
        foreach (Sub::STATUSES as $k => $label) {
            $counts[$k] = ['label' => $label, 'n' => $rows->where('status', $k)->count(), 'color' => Sub::STATUS_COLORS[$k] ?? 'gray'];
        }
        $link = fn (Sub $r) => PracticeSubscriptionResource::getUrl('view', ['record' => $r]);
        $present = fn (Sub $r) => [
            'practice' => $r->subscriberLabel(), 'plan' => $r->plan?->name ?? '—', 'status' => $r->statusLabel(), 'amount' => $r->amountLabel(),
            'next' => ($r->next_billing_at ?? $r->trial_ends_at)?->format('d M Y'), 'grace' => $r->grace_ends_at?->format('d M Y'),
            'card' => $r->token ? 'Card' : ($r->gateway ?: '—'), 'url' => $link($r),
        ];

        $upcoming = Sub::query()->with('plan')
            ->where(fn ($q) => $q->where(fn ($a) => $a->where('status', Sub::STATUS_ACTIVE)->where('cancel_at_period_end', false)->whereBetween('next_billing_at', [now(), now()->addDays(14)]))
                ->orWhere(fn ($t) => $t->where('status', Sub::STATUS_TRIALING)->whereBetween('trial_ends_at', [now(), now()->addDays(14)])))
            ->orderByRaw('COALESCE(next_billing_at, trial_ends_at)')->limit(50)->get()->map($present)->all();

        $failing = Sub::query()->with('plan')->whereIn('status', [Sub::STATUS_PAST_DUE, Sub::STATUS_GRACE, Sub::STATUS_SUSPENDED])
            ->orderBy('grace_ends_at')->limit(100)->get()->map($present)->all();

        $paid30 = (int) PracticeInvoice::query()->where('status', 'paid')->where('paid_at', '>=', now()->subDays(30))->sum('amount_cents');

        $subscribedClinics = $rows->where('subscriber_type', 'clinic')->whereNotIn('status', [Sub::STATUS_INCOMPLETE])->pluck('subscriber_id')->all();
        $subscribedDoctors = $rows->where('subscriber_type', 'doctor')->whereNotIn('status', [Sub::STATUS_INCOMPLETE])->pluck('subscriber_id')->all();
        $unsubClinics = Clinic::query()->where('status', 'active')->whereNotIn('id', $subscribedClinics ?: [0])->count();
        $unsubDoctors = DoctorProfile::query()->where('status', 'active')->whereDoesntHave('clinics')->whereNotIn('id', $subscribedDoctors ?: [0])->count();

        return [
            'mrr' => Money::format($mrr),
            'arr' => Money::format($mrr * 12),
            'paid30' => Money::format($paid30),
            'counts' => $counts,
            'upcoming' => $upcoming,
            'failing' => $failing,
            'unsubClinics' => $unsubClinics,
            'unsubDoctors' => $unsubDoctors,
            'enforced' => S::enforced(),
            'gated' => array_keys(array_filter(array_map(fn ($k) => S::bool($k), S::FEATURES))),
            'unsubscribedPolicy' => S::str('practice_billing_unsubscribed'),
            'graceDays' => S::graceDays(),
            'retryHours' => S::retryHours(),
            'settingsUrl' => PracticeBillingSettingsPage::canAccess() ? PracticeBillingSettingsPage::getUrl() : null,
            'listUrl' => PracticeSubscriptionResource::getUrl('index'),
        ];
    }
}
