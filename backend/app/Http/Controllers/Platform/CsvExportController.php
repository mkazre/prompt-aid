<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Support\CsvExport;
use Illuminate\Http\Request;

/**
 * GET /staff/exports/csv/{token} — streams a Filament table export prepared
 * by CsvExport::headerAction()/bulkAction(). The token is random, expires
 * after 10 minutes and only works for the user who started the export.
 */
class CsvExportController extends Controller
{
    public function __invoke(Request $request, string $token)
    {
        $response = CsvExport::downloadFromToken($token, $request->user());

        abort_if($response === null, 404, 'This export link has expired. Start the export again from the table.');

        return $response;
    }
}
