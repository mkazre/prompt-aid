<?php

namespace App\Filament\ReportWidgets;

use App\Support\Reports;

/** Pharmacy orders per day and paid revenue (Reports page). */
class OrdersReportChart extends ReportChart
{
    protected static string $section = 'orders';

    protected static ?int $sort = 5;

    protected ?string $heading = 'Pharmacy orders';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function summary(): ?string
    {
        [$from, $to] = $this->range();
        $t = Reports::orderTotals($from, $to);

        return $t['orders'].' orders · '.$t['paid'].' paid · R'.number_format($t['revenue'], 0).' revenue';
    }

    protected function getData(): array
    {
        [$from, $to] = $this->range();
        $o = Reports::ordersPerDay($from, $to);

        return [
            'datasets' => [
                ['label' => 'Orders', 'data' => array_values($o['orders']), 'backgroundColor' => '#1F4E7A', 'yAxisID' => 'y'],
                ['label' => 'Paid', 'data' => array_values($o['paid']), 'backgroundColor' => '#1F7A4C', 'yAxisID' => 'y'],
                ['label' => 'Revenue (R)', 'data' => array_values($o['revenue']), 'type' => 'line', 'borderColor' => '#D0211C', 'backgroundColor' => '#D0211C', 'tension' => 0.3, 'yAxisID' => 'y1'],
            ],
            'labels' => $this->dayLabels(),
        ];
    }

    protected function getOptions(): array
    {
        return ['scales' => [
            'y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0], 'position' => 'left'],
            'y1' => ['beginAtZero' => true, 'position' => 'right', 'grid' => ['drawOnChartArea' => false]],
        ]];
    }
}
