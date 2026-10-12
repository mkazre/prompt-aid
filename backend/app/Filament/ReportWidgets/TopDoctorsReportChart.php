<?php

namespace App\Filament\ReportWidgets;

use App\Support\Reports;

/** Top 10 doctors by bookings in the range (Reports page; CSV has the top 50). */
class TopDoctorsReportChart extends ReportChart
{
    protected static string $section = 'doctors';

    protected static ?int $sort = 6;

    protected ?string $heading = 'Top doctors by bookings';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        [$from, $to] = $this->range();
        $rows = Reports::topDoctors($from, $to, 10);

        return [
            'datasets' => [
                ['label' => 'Bookings', 'data' => array_column($rows, 'bookings'), 'backgroundColor' => '#1F4E7A'],
                ['label' => 'Completed', 'data' => array_column($rows, 'completed'), 'backgroundColor' => '#1F7A4C'],
            ],
            'labels' => array_map(fn ($r) => $r['doctor'].($r['reviews'] ? ' (★'.number_format($r['rating'], 1).')' : ''), $rows),
        ];
    }

    protected function getOptions(): array
    {
        return ['indexAxis' => 'y', 'scales' => ['x' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]]];
    }
}
