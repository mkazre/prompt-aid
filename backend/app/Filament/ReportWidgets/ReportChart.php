<?php

namespace App\Filament\ReportWidgets;

use App\Support\Reports;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * Base for the Reports page charts: reads the page's date range filter and
 * puts a "Download CSV" link (streamed, see ReportExportController) under
 * the heading. Not on the main dashboard — these live in
 * App\Filament\ReportWidgets, outside the discovered widgets folder.
 */
abstract class ReportChart extends ChartWidget
{
    use InteractsWithPageFilters;

    /** Reports::SECTIONS key used for the CSV link. */
    protected static string $section = '';

    protected ?string $maxHeight = '280px';

    public const COLORS = ['#D0211C', '#1F4E7A', '#1F7A4C', '#E4701E', '#F2C200', '#6E6A62', '#7A3E9D', '#101012'];

    /** @return array{0: Carbon, 1: Carbon} */
    protected function range(): array
    {
        return Reports::range($this->pageFilters);
    }

    /** @return array<int, string> */
    protected function dayLabels(): array
    {
        [$from, $to] = $this->range();

        return array_map(fn ($d) => Carbon::parse($d)->format('j M'), Reports::days($from, $to));
    }

    protected function csvUrl(): string
    {
        return route('platform.report-export', array_filter([
            'section' => static::$section,
            'preset' => $this->pageFilters['preset'] ?? '30d',
            'from' => $this->pageFilters['from'] ?? null,
            'to' => $this->pageFilters['to'] ?? null,
        ]));
    }

    protected function summary(): ?string
    {
        return null;
    }

    public function getDescription(): string|Htmlable|null
    {
        $summary = $this->summary();

        return new HtmlString(
            ($summary ? e($summary).' · ' : '')
            .'<a href="'.e($this->csvUrl()).'" style="color:#D0211C;font-weight:700;text-decoration:underline" download>Download CSV</a>'
        );
    }
}
