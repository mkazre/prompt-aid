<?php

namespace App\Filament\ReportWidgets;

use App\Support\Reports;

/** Appointments by status for the range (Reports page). */
class AppointmentStatusReportChart extends ReportChart
{
    protected static string $section = 'appointments';

    protected static ?int $sort = 2;

    protected ?string $heading = 'Appointments by status';

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        [$from, $to] = $this->range();
        $byStatus = Reports::appointmentsByStatus($from, $to);
        $palette = ['completed' => '#1F7A4C', 'confirmed' => '#1F4E7A', 'pending' => '#F2C200', 'checked_in' => '#E4701E', 'matching' => '#7A3E9D', 'cancelled' => '#D0211C', 'no_show' => '#6E6A62'];

        return [
            'datasets' => [[
                'label' => 'Appointments',
                'data' => array_values($byStatus),
                'backgroundColor' => array_map(fn ($s) => $palette[$s] ?? '#101012', array_keys($byStatus)),
            ]],
            'labels' => array_map(fn ($s) => str($s)->headline()->toString(), array_keys($byStatus)),
        ];
    }

    protected function getOptions(): array
    {
        return ['scales' => ['x' => ['display' => false], 'y' => ['display' => false]], 'plugins' => ['legend' => ['position' => 'right']]];
    }
}
