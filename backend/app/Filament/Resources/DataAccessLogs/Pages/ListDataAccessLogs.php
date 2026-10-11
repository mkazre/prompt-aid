<?php

namespace App\Filament\Resources\DataAccessLogs\Pages;

use App\Filament\Resources\DataAccessLogs\DataAccessLogResource;
use Filament\Resources\Pages\ListRecords;

class ListDataAccessLogs extends ListRecords
{
    protected static string $resource = DataAccessLogResource::class;
}
