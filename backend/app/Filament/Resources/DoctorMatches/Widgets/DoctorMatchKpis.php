<?php

namespace App\Filament\Resources\DoctorMatches\Widgets;

use App\Models\DoctorMatchRequest;
use App\Services\Doctors\DoctorMatchService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

/** Today's on-demand KPIs (super admin, header of the requests list). */
class DoctorMatchKpis extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '15s';

    public static function canView(): bool
    {
        return (bool) Auth::user()?->isSuperAdmin();
    }

    protected function getStats(): array
    {
        $today = DoctorMatchRequest::query()->where('created_at', '>=', today());
        $total = (clone $today)->count();
        $matched = (clone $today)->whereNotNull('matched_at')->count();
        $expired = (clone $today)->where('status', DoctorMatchRequest::EXPIRED)->count();
        $closed = (clone $today)->where('status', '!=', DoctorMatchRequest::SEARCHING)->count();
        $searching = (clone $today)->where('status', DoctorMatchRequest::SEARCHING)->count();

        $secs = (clone $today)->whereNotNull('matched_at')->get(['created_at', 'matched_at'])
            ->map(fn ($r) => max(0, $r->matched_at->getTimestamp() - $r->created_at->getTimestamp()))
            ->sort()->values();
        $median = $secs->isNotEmpty() ? (int) $secs[intdiv($secs->count(), 2)] : null;

        return [
            Stat::make('Requests today', $total)
                ->description("{$searching} searching now")
                ->color('primary'),
            Stat::make('Fill rate', $closed > 0 ? round($matched / $closed * 100).'%' : '—')
                ->description("{$matched} matched of {$closed} closed")
                ->color('success'),
            Stat::make('Median time to accept', $median === null ? '—' : gmdate($median >= 3600 ? 'H:i:s' : 'i:s', $median))
                ->description('Request → doctor accepted')
                ->color('info'),
            Stat::make('Expired today', $expired)
                ->description(DoctorMatchService::onlineQuery()->count().' doctors online now')
                ->color($expired > 0 ? 'danger' : 'gray'),
        ];
    }
}
