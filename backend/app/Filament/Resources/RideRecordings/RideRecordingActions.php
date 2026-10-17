<?php

namespace App\Filament\Resources\RideRecordings;

use App\Models\RideRecording;
use App\Services\Safety\RideRecordingService;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

/**
 * Legal hold / release / delete actions shared by the recordings table, the
 * recording view page and the ride view page. All require
 * `ride-recordings.manage` (super admins always) and are written to the
 * recording's access log.
 */
class RideRecordingActions
{
    public static function hold(?Closure $record = null, string $name = 'hold'): Action
    {
        $a = Action::make($name)
            ->label('Legal hold')
            ->icon('heroicon-o-lock-closed')
            ->color('danger')
            ->visible(fn (?RideRecording $record): bool => $record && ! $record->legal_hold && RideRecordingService::isStaffManager(Auth::user()))
            ->modalHeading('Place on legal hold')
            ->modalDescription('A held recording is never deleted automatically, whatever the retention setting, until the hold is released.')
            ->schema([
                Textarea::make('reason')->label('Reason (e.g. complaint ref, SAPS case no.)')->required()->maxLength(255),
            ])
            ->action(function (array $data, RideRecording $record): void {
                abort_unless(RideRecordingService::isStaffManager(Auth::user()), 403);
                app(RideRecordingService::class)->hold($record, Auth::user(), $data['reason']);
                Notification::make()->title('Recording placed on legal hold')->success()->send();
            });

        return $record ? $a->record($record) : $a;
    }

    public static function release(?Closure $record = null, string $name = 'release'): Action
    {
        $a = Action::make($name)
            ->label('Release hold')
            ->icon('heroicon-o-lock-open')
            ->color('warning')
            ->visible(fn (?RideRecording $record): bool => $record && $record->legal_hold && RideRecordingService::isStaffManager(Auth::user()))
            ->modalDescription('Normal retention applies again: the recording will be deleted when its retention period ends (at the earliest 24 hours from now). Automatic holds will not be re-applied to it.')
            ->schema([
                Textarea::make('note')->label('Note')->maxLength(255),
            ])
            ->action(function (array $data, RideRecording $record): void {
                abort_unless(RideRecordingService::isStaffManager(Auth::user()), 403);
                app(RideRecordingService::class)->release($record, Auth::user(), $data['note'] ?? null);
                Notification::make()->title('Hold released')->success()->send();
            });

        return $record ? $a->record($record) : $a;
    }

    public static function delete(?Closure $record = null, string $name = 'deleteRecording', ?Closure $after = null): Action
    {
        $a = Action::make($name)
            ->label('Delete')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->visible(fn (?RideRecording $record): bool => $record && ! $record->legal_hold && RideRecordingService::isStaffManager(Auth::user()))
            ->requiresConfirmation()
            ->modalHeading('Delete this recording?')
            ->modalDescription('The audio is destroyed permanently. A record that it existed (times, hash, access log) is kept for 30 days.')
            ->action(function (RideRecording $record) use ($after): void {
                abort_unless(RideRecordingService::isStaffManager(Auth::user()), 403);
                if (app(RideRecordingService::class)->deleteRecording($record, Auth::user(), 'delete', 'Deleted by staff')) {
                    Notification::make()->title('Recording deleted')->success()->send();
                    if ($after) {
                        $after();
                    }
                } else {
                    Notification::make()->title('Release the legal hold first')->danger()->send();
                }
            });

        return $record ? $a->record($record) : $a;
    }
}
