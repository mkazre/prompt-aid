<?php

namespace App\Http\Controllers\Platform;

use App\Filament\Pages\Reports as ReportsPage;
use App\Http\Controllers\Controller;
use App\Support\CsvExport;
use App\Support\Reports;
use Illuminate\Http\Request;

/**
 * GET /staff/report-exports/{section}?preset=30d|...&from=&to= — the
 * "Download CSV" links on Staff → Overview → Reports. Streamed, no queue.
 */
class ReportExportController extends Controller
{
    public function __invoke(Request $request, string $section)
    {
        abort_unless(ReportsPage::canAccess(), 403);
        abort_unless(array_key_exists($section, Reports::SECTIONS), 404);

        [$from, $to] = Reports::range($request->only(['preset', 'from', 'to']));
        [$headers, $rows] = Reports::csv($section, $from, $to);

        $name = 'report-'.$section.'-'.$from->format('Ymd').'-'.$to->format('Ymd').'.csv';

        return CsvExport::stream($name, $headers, $rows);
    }
}
