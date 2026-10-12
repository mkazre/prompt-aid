<?php

namespace App\Filament\ReportWidgets;

use App\Support\Reports;

/** Shuttle rides completed / cancelled per day, with revenue (Reports page). */
class RidesReportChart extends ReportChart
{
    protected static string $section = 'rides';

    protected static ?int $sort = 4;

    protected ?string $heading = 'Shuttle rides';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function summary(): ?string
    {
        [$from, $to] = $this->range();
        $t = Reports::rideTotals($from, $to);

        return $t['completed'].' completed · '.$t['cancelled'].' cancelled · R'.number_format($t['revenue'], 0).' revenue';
    }

    protected function getData(): array
    {
        [$from, $to] = $this->range();
        $r = Reports::ridesPerDay($from, $to);

        return [
            'datasets' => [
                ['label' => 'Completed', 'data' => array_values($r['completed']), 'backgroundColor' => '#1F7A4C', 'yAxisID' => 'y'],
                ['label' => 'Cancelled', 'data' => array_values($r['cancelled']), 'backgroundColor' => '#D0211C', 'yAxisID' => 'y'],
                ['label' => 'Revenue (R)', 'data' => array_values($r['revenue']), 'type' => 'line', 'borderColor' => '#F2C200', 'backgroundColor' => '#F2C200', 'tension' => 0.3, 'yAxisID' => 'y1'],
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
