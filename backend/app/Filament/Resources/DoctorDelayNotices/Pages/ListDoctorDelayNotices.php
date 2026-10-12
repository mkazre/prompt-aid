<?php

namespace App\Filament\Resources\DoctorDelayNotices\Pages;

use App\Filament\Resources\DoctorDelayNotices\DoctorDelayNoticeResource;
use Filament\Resources\Pages\ListRecords;

class ListDoctorDelayNotices extends ListRecords
{
    protected static string $resource = DoctorDelayNoticeResource::class;
}
