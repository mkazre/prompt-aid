<?php

namespace App\Filament\Resources\DoctorMatches\Pages;

use App\Filament\Resources\DoctorMatches\DoctorMatchRequestResource;
use App\Filament\Resources\DoctorMatches\Widgets\DoctorMatchKpis;
use App\Services\Doctors\DoctorMatchService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListDoctorMatchRequests extends ListRecords
{
    protected static string $resource = DoctorMatchRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('runMatcher')
                ->label('Run matcher now')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function () {
                    $n = app(DoctorMatchService::class)->tick(force: true);
                    Notification::make()->title("Checked {$n} open request(s)")->success()->send();
                }),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [DoctorMatchKpis::class];
    }
}
