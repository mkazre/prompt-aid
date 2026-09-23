<?php

namespace App\Filament\Resources\TriageSubmissions\Pages;

use App\Filament\Resources\TriageSubmissions\TriageSubmissionResource;
use Filament\Resources\Pages\ListRecords;

class ListTriageSubmissions extends ListRecords
{
    protected static string $resource = TriageSubmissionResource::class;
}
