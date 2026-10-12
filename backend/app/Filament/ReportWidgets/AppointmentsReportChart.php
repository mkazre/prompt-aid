<?php

namespace App\Filament\ReportWidgets;

use App\Support\Reports;

/** Appointments per day, stacked by status (Reports page). */
class AppointmentsReportChart extends ReportChart
{
    protected static string $section = 'appointments';

    protected static ?int $sort = 1;

    protected ?string $heading = 'Appointments per day';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function summary(): ?string
    {
        [$from, $to] = $this->range();

        return number_format(array_sum(Reports::appointmentsPerDay($from, $to))).' appointments';
    }

    protected function getData(): array
    {
        [$from, $to] = $this->range();
        $statuses = ['completed' => '#1F7A4C', 'confirmed' => '#1F4E7A', 'pending' => '#F2C200', 'checked_in' => '#E4701E', 'matching' => '#7A3E9D', 'cancelled' => '#D0211C', 'no_show' => '#6E6A62'];

        $datasets = [];
        foreach ($statuses as $status => $color) {
            $values = Reports::appointmentsPerDay($from, $to, $status);
            if (array_sum($values) > 0) {
                $datasets[] = ['label' => str($status)->headline()->toString(), 'data' => array_values($values), 'backgroundColor' => $color, 'stack' => 'a'];
            }
        }

        return ['datasets' => $datasets ?: [['label' => 'Appointments', 'data' => array_values(Reports::appointmentsPerDay($from, $to)), 'backgroundColor' => '#1F4E7A']], 'labels' => $this->dayLabels()];
    }

    protected function getOptions(): array
    {
        return ['scales' => ['x' => ['stacked' => true], 'y' => ['stacked' => true, 'beginAtZero' => true, 'ticks' => ['precision' => 0]]]];
    }
}
