<?php

namespace App\Filament\Resources\LoginEvents\Pages;

use App\Filament\Resources\LoginEvents\LoginEventResource;
use Filament\Resources\Pages\ListRecords;

class ListLoginEvents extends ListRecords
{
    protected static string $resource = LoginEventResource::class;

    public function getSubheading(): ?string
    {
        return 'Every sign-in to the website, the staff panels and the app, plus failed attempts for known emails. Kept for '.\App\Support\LoginRecorder::retentionDays().' days (Setting login_events_retention_days).';
    }
}
