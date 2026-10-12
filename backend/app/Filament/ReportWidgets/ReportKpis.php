<?php

namespace App\Filament\ReportWidgets;

use App\Support\Reports;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** KPI cards for the selected date range (Reports page). */
class ReportKpis extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int|array|null
    {
        return ['default' => 1, 'md' => 3, 'xl' => 6];
    }

    protected function getStats(): array
    {
        [$from, $to] = Reports::range($this->pageFilters);

        $appts = Reports::appointmentsPerDay($from, $to);
        $byStatus = Reports::appointmentsByStatus($from, $to);
        $signups = Reports::signupsPerDay($from, $to);
        $rides = Reports::rideTotals($from, $to);
        $ridesDaily = Reports::ridesPerDay($from, $to);
        $orders = Reports::orderTotals($from, $to);
        $ordersDaily = Reports::ordersPerDay($from, $to);
        $reviews = Reports::reviewSummary($from, $to);

        $completed = $byStatus['completed'] ?? 0;
        $cancelled = ($byStatus['cancelled'] ?? 0) + ($byStatus['no_show'] ?? 0);

        return [
            Stat::make('Appointments', number_format(array_sum($appts)))
                ->description($completed.' completed · '.$cancelled.' cancelled/no-show')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('primary')
                ->chart(array_values($appts)),
            Stat::make('New patients', number_format(array_sum($signups)))
                ->description('Sign-ups in this period')
                ->descriptionIcon('heroicon-m-user-plus')
                ->color('info')
                ->chart(array_values($signups)),
            Stat::make('Shuttle rides', number_format($rides['completed']))
                ->description($rides['cancelled'].' cancelled of '.$rides['requested'].' requested')
                ->descriptionIcon('heroicon-m-truck')
                ->color('warning')
                ->chart(array_values($ridesDaily['completed'])),
            Stat::make('Shuttle revenue', 'R'.number_format($rides['revenue'], 0))
                ->description('Completed trips'.($rides['cancellation_fees'] > 0 ? ' + R'.number_format($rides['cancellation_fees'], 0).' cancel fees' : ''))
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success')
                ->chart(array_values($ridesDaily['revenue'])),
            Stat::make('Pharmacy revenue', 'R'.number_format($orders['revenue'], 0))
                ->description($orders['paid'].' paid of '.$orders['orders'].' orders')
                ->descriptionIcon('heroicon-m-shopping-bag')
                ->color('success')
                ->chart(array_values($ordersDaily['revenue'])),
            Stat::make('Average rating', $reviews['count'] ? number_format($reviews['average'], 2).' / 5' : '—')
                ->description($reviews['count'].' '.str('review')->plural($reviews['count']))
                ->descriptionIcon('heroicon-m-star')
                ->color('warning'),
        ];
    }
}
