<?php

namespace App\Filament\ReportWidgets;

use App\Support\Reports;

/** Review ratings in the range: star distribution + average (Reports page). */
class ReviewsReportChart extends ReportChart
{
    protected static string $section = 'reviews';

    protected static ?int $sort = 7;

    protected ?string $heading = 'Review ratings';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function summary(): ?string
    {
        [$from, $to] = $this->range();
        $s = Reports::reviewSummary($from, $to);

        return $s['count'] ? 'Average '.number_format($s['average'], 2).' / 5 from '.$s['count'].' reviews' : 'No reviews in this period';
    }

    protected function getData(): array
    {
        [$from, $to] = $this->range();
        $s = Reports::reviewSummary($from, $to);

        return [
            'datasets' => [[
                'label' => 'Reviews',
                'data' => array_values($s['distribution']),
                'backgroundColor' => ['#1F7A4C', '#6BA368', '#F2C200', '#E4701E', '#D0211C'],
            ]],
            'labels' => array_map(fn ($stars) => $stars.' ★', array_keys($s['distribution'])),
        ];
    }

    protected function getOptions(): array
    {
        return ['plugins' => ['legend' => ['display' => false]], 'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]]];
    }
}
