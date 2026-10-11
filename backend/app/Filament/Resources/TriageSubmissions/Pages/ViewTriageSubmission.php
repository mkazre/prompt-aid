<?php

namespace App\Filament\Resources\TriageSubmissions\Pages;

use App\Filament\Resources\TriageSubmissions\TriageSubmissionResource;
use Filament\Resources\Pages\ViewRecord;

class ViewTriageSubmission extends ViewRecord
{
    // POPIA: logs each open of this record to data_access_logs.
    use \App\Filament\Concerns\LogsRecordAccess;

    protected static string $resource = TriageSubmissionResource::class;
}
