<?php

namespace App\Filament\ReportWidgets;

use App\Support\Reports;

/** New patient sign-ups per day (Reports page). */
class SignupsReportChart extends ReportChart
{
    protected static string $section = 'signups';

    protected static ?int $sort = 3;

    protected ?string $heading = 'New patient sign-ups';

    protected function getType(): string
    {
        return 'line';
    }

    protected function summary(): ?string
    {
        [$from, $to] = $this->range();

        return number_format(array_sum(Reports::signupsPerDay($from, $to))).' new patients';
    }

    protected function getData(): array
    {
        [$from, $to] = $this->range();

        return [
            'datasets' => [[
                'label' => 'New patients',
                'data' => array_values(Reports::signupsPerDay($from, $to)),
                'borderColor' => '#1F4E7A',
                'backgroundColor' => 'rgba(31, 78, 122, 0.12)',
                'fill' => true,
                'tension' => 0.3,
            ]],
            'labels' => $this->dayLabels(),
        ];
    }

    protected function getOptions(): array
    {
        return ['scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]]];
    }
}
