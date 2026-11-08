<?php

namespace App\Filament\Pages;

use App\Models\CommissionRule;
use App\Models\PayoutAccount;
use App\Models\PayoutBatch;
use App\Services\Finance\EarningsService;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/** Staff → Finance → Overview: platform revenue by service + outstanding provider balances. */
class FinanceOverviewPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Finance overview';

    protected static ?string $title = 'Finance overview';

    protected static ?string $slug = 'finance';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.finance-overview';

    public string $period = 'month';

    public ?string $from = null;

    public ?string $to = null;

    public static function canAccess(): bool
    {
        $u = Auth::user();

        return (bool) ($u?->isSuperAdmin() && $u->hasPermission('finance-overview.view'));
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        [$a, $b] = EarningsService::period($this->period, $this->from, $this->to);
        $earn = app(EarningsService::class);
        $rev = $earn->platformRevenue($a, $b);
        $tot = ['commission' => 0.0, 'fees' => 0.0, 'other' => 0.0, 'refunds' => 0.0, 'total' => 0.0];
        foreach ($rev as $r) {
            foreach ($tot as $k => $v) {
                $tot[$k] = round($v + $r[$k], 2);
            }
        }
        $labels = collect(CommissionRule::SERVICES)->map(fn ($l) => explode(' (', $l)[0])->all() + ['subscription' => 'Subscriptions', 'other' => 'Other'];

        return [
            'range' => EarningsService::rangeLabel($a, $b),
            'revenue' => $rev,
            'totals' => $tot,
            'labels' => $labels,
            'balances' => $earn->outstandingBalances(50),
            'openBatches' => PayoutBatch::query()->whereIn('status', ['pending', 'approved'])->latest('id')->limit(10)->get(),
            'unverified' => PayoutAccount::query()->whereNull('verified_at')->count(),
            'batchUrl' => fn ($b) => \App\Filament\Resources\PayoutBatches\PayoutBatchResource::getUrl('view', ['record' => $b]),
            'bankUrl' => \App\Filament\Resources\PayoutAccounts\PayoutAccountResource::getUrl('index'),
        ];
    }
}
