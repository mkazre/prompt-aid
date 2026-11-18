<?php

namespace App\Providers;

use App\Models\PracticePlan;
use App\Models\PracticeSubscription as Sub;
use App\Models\User;
use App\Services\Billing\PracticeBilling;
use App\Support\PracticeBillingSettings as S;
use App\Support\Tick;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/**
 * Practice billing wiring: the lifecycle Tick job (trial end, missed renewals →
 * past_due → grace → suspended, daily reminders) and the staff-panel banner
 * for clinic admins and doctors. Everything is fail-safe.
 */
class PracticeBillingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Hourly evaluation keeps transitions punctual; reminders are at most one per day per practice.
        Tick::register('billing.practice_subscriptions', 3600, function () {
            if (static::ready()) {
                app(PracticeBilling::class)->tick();
            }
        });

        FilamentView::registerRenderHook(PanelsRenderHook::CONTENT_START, function (): string {
            try {
                if (Filament::getCurrentPanel()?->getId() !== 'admin' || ! static::ready()) {
                    return '';
                }
                $banner = static::banner(Auth::user());

                return $banner ? view('filament.practice-billing-banner', ['b' => $banner])->render() : '';
            } catch (\Throwable $e) {
                Log::warning('[PracticeBilling] banner failed: '.$e->getMessage());

                return '';
            }
        });
    }

    /**
     * What to tell this staff user about their practice's subscription (null = nothing).
     *
     * @return array{tone: string, title: string, body: string, action: ?string, url: ?string}|null
     */
    public static function banner(?User $user): ?array
    {
        if (! $user instanceof User || ! ($user->isClinicAdmin() || $user->isDoctor())) {
            return null;
        }
        if (request()->routeIs('filament.admin.pages.practice-subscription')) {
            return null; // the page itself shows the full status
        }
        $url = PracticeBilling::pageUrl();
        $managed = PracticeBilling::managedBy($user);

        if ($managed === []) {
            // Doctor working at a clinic: only tell them when their practice is lapsed.
            $doc = $user->isDoctor() ? $user->doctorProfile : null;
            if (! $doc) {
                return null;
            }
            $status = \App\Services\Billing\PracticeSubscription::status($doc);
            if (in_array($status, ['suspended', 'grace', 'past_due'], true)) {
                return ['tone' => $status === 'suspended' ? 'danger' : 'warning', 'title' => 'Your practice\'s PromptAid subscription needs attention',
                    'body' => $status === 'suspended' ? 'The practice subscription is suspended'.(S::enforced() ? ' — new patient requests are paused' : '').'. Please ask your practice administrator to update payment.' : 'The practice subscription payment is overdue. Please ask your practice administrator to update payment.',
                    'action' => null, 'url' => null];
            }

            return null;
        }

        $worst = null;
        $rank = ['suspended' => 0, 'grace' => 1, 'past_due' => 2, 'incomplete' => 3, 'none' => 4, 'cancelling' => 5, 'trialing' => 6];
        foreach ($managed as $m) {
            $row = Sub::query()->with('plan')->where('subscriber_type', $m['type'])->where('subscriber_id', $m['id'])->first();
            $b = static::bannerFor($row, $m['label'], count($managed) > 1);
            if ($b && ($worst === null || $rank[$b['key']] < $rank[$worst['key']])) {
                $worst = $b;
            }
        }
        if (! $worst) {
            return null;
        }
        unset($worst['key']);

        return $worst + ['url' => $url];
    }

    protected static function bannerFor(?Sub $row, string $label, bool $many): ?array
    {
        $who = $many ? $label.': ' : '';
        $enf = S::enforced();
        if (! $row || $row->status === Sub::STATUS_INCOMPLETE) {
            if (! PracticePlan::query()->active()->exists()) {
                return null;
            }

            return ['key' => $row ? 'incomplete' : 'none', 'tone' => 'info', 'title' => $who.'Choose a PromptAid practice plan',
                'body' => 'Practices pay a monthly subscription to receive patient requests on PromptAid. Consultation fees stay with your practice.', 'action' => 'View plans'];
        }
        if ($row->isComped()) {
            return null;
        }

        return match ($row->status) {
            Sub::STATUS_SUSPENDED => ['key' => 'suspended', 'tone' => 'danger', 'title' => $who.'Subscription suspended',
                'body' => 'We have not received your subscription payment'.($enf ? ' — your practice is not receiving new patient requests' : '').'. Pay now to reactivate immediately.', 'action' => 'Update payment'],
            Sub::STATUS_GRACE, Sub::STATUS_PAST_DUE => ['key' => $row->status, 'tone' => 'warning', 'title' => $who.'Payment failed',
                'body' => 'Your last subscription payment did not go through. Update payment before '.($row->grace_ends_at?->format('d M Y') ?? 'the grace period ends').' to keep receiving patient requests.', 'action' => 'Update payment'],
            Sub::STATUS_TRIALING => static::trialBanner($row, $who),
            Sub::STATUS_ACTIVE => $row->cancel_at_period_end && $row->current_period_end ? ['key' => 'cancelling', 'tone' => 'info', 'title' => $who.'Subscription ends '.$row->current_period_end->format('d M Y'),
                'body' => 'Your subscription was cancelled and stays active until then. Subscribe again to keep receiving patient requests.', 'action' => 'Manage'] : null,
            default => null,
        };
    }

    protected static function trialBanner(Sub $row, string $who): ?array
    {
        if (! $row->trial_ends_at) {
            return null;
        }
        if ($row->current_period_end && $row->current_period_end->gt($row->trial_ends_at)) {
            return null; // already subscribed for after the trial
        }
        $days = max(0, (int) ceil(now()->diffInHours($row->trial_ends_at, false) / 24));

        return ['key' => 'trialing', 'tone' => $days <= 3 ? 'warning' : 'info',
            'title' => $who.'Free trial: '.($days === 0 ? 'ends today' : $days.' day'.($days === 1 ? '' : 's').' left'),
            'body' => 'Subscribe before '.$row->trial_ends_at->format('d M Y').' to keep receiving patient requests. Your card is only charged from the end of the trial.', 'action' => 'Subscribe'];
    }

    public static function ready(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                $ok = Schema::hasTable('practice_subscriptions') && Schema::hasTable('practice_invoices') && Schema::hasTable('practice_billing_events');
            } catch (\Throwable) {
                $ok = false;
            }
        }

        return $ok;
    }
}
