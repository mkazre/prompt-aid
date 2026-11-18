<?php

namespace App\Filament\Resources\CallLogs\Pages;

use App\Filament\Pages\RecordingStorageSettingsPage;
use App\Filament\Resources\CallLogs\CallLogResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListCallLogs extends ListRecords
{
    protected static string $resource = CallLogResource::class;

    public function getSubheading(): ?string
    {
        return 'Every in-app voice and video call: who called, who answered, who ended it, when, how long, the outcome and the recording. Recordings are only playable by platform admins and the practice / doctor of the call; every play and download is logged.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('storage')->label('Recording storage')->icon('heroicon-o-cog-6-tooth')->color('gray')
                ->visible(fn () => RecordingStorageSettingsPage::canAccess())
                ->url(fn () => RecordingStorageSettingsPage::getUrl()),
        ];
    }
}
