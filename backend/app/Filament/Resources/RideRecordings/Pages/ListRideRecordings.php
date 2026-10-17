<?php

namespace App\Filament\Resources\RideRecordings\Pages;

use App\Filament\Pages\RideRecordingSettingsPage;
use App\Filament\Resources\RideRecordings\RideRecordingResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListRideRecordings extends ListRecords
{
    protected static string $resource = RideRecordingResource::class;

    public function getSubheading(): ?string
    {
        return 'Audio recorded by patients and drivers during shuttle trips. Encrypted at rest; deleted automatically after the retention period unless on legal hold. Every play and download is logged.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('settings')
                ->label('Recording settings')
                ->icon('heroicon-o-cog-6-tooth')
                ->color('gray')
                ->visible(fn () => RideRecordingSettingsPage::canAccess())
                ->url(fn () => RideRecordingSettingsPage::getUrl()),
        ];
    }
}
