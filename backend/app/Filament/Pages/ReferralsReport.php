<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Support\CommerceSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/** Referral programme report: totals, top referrers and sign-ups per period. */
class ReferralsReport extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-plus';

    protected static string|UnitEnum|null $navigationGroup = 'Marketplace';

    protected static ?string $navigationLabel = 'Referrals';

    protected static ?string $title = 'Referrals';

    protected static ?string $slug = 'referrals';

    protected static ?int $navigationSort = 21;

    protected string $view = 'filament.pages.referrals-report';

    /** week | month */
    public string $period = 'month';

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->hasPermission('referrals.view');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('settings')
                ->label('Reward text & settings')
                ->icon('heroicon-o-cog-6-tooth')
                ->color('gray')
                ->url(fn () => CommerceSettingsPage::getUrl())
                ->visible(fn () => CommerceSettingsPage::canAccess()),
        ];
    }

    public function setPeriod(string $period): void
    {
        $this->period = in_array($period, ['week', 'month'], true) ? $period : 'month';
    }

    protected function getViewData(): array
    {
        $referred = User::query()->whereNotNull('referred_by_user_id');

        $top = User::query()
            ->select(['id', 'name', 'email', 'role', 'referral_code'])
            ->withCount('referrals')
            ->whereHas('referrals')
            ->orderByDesc('referrals_count')
            ->limit(15)
            ->get();

        // Sign-ups per period (last 12 weeks or 12 months), bucketed in PHP so it works on MySQL and SQLite alike.
        $tz = CommerceSettings::timezone();
        $buckets = [];
        $start = $this->period === 'week'
            ? now($tz)->startOfWeek()->subWeeks(11)
            : now($tz)->startOfMonth()->subMonths(11);
        for ($i = 0; $i < 12; $i++) {
            $from = $this->period === 'week' ? $start->copy()->addWeeks($i) : $start->copy()->addMonths($i);
            $key = $from->format('Y-m-d');
            $buckets[$key] = [
                'label' => $this->period === 'week' ? 'Week of '.$from->format('d M') : $from->format('M Y'),
                'count' => 0,
            ];
        }
        (clone $referred)->where('created_at', '>=', $start->copy()->utc())->get(['created_at'])
            ->each(function (User $u) use (&$buckets, $tz) {
                $at = Carbon::parse($u->created_at)->setTimezone($tz);
                $key = ($this->period === 'week' ? $at->copy()->startOfWeek() : $at->copy()->startOfMonth())->format('Y-m-d');
                if (isset($buckets[$key])) {
                    $buckets[$key]['count']++;
                }
            });

        $max = max(1, ...array_column($buckets, 'count'));

        return [
            'enabled' => CommerceSettings::bool('referrals_enabled'),
            'rewardText' => CommerceSettings::str('referral_reward_text'),
            'totalReferred' => (clone $referred)->count(),
            'last30' => (clone $referred)->where('created_at', '>=', now()->subDays(30))->count(),
            'referrers' => User::query()->whereHas('referrals')->count(),
            'codes' => User::query()->whereNotNull('referral_code')->count(),
            'top' => $top,
            'buckets' => array_values($buckets),
            'max' => $max,
            'recent' => (clone $referred)->with('referrer:id,name')->latest()->limit(10)->get(['id', 'name', 'role', 'created_at', 'referred_by_user_id']),
        ];
    }
}
