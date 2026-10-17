<?php

namespace App\Filament\Resources\Appointments;

use App\Models\Appointment;
use App\Services\Video\VideoRoomService;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Auth;

/**
 * "Join video" for the appointment's own doctor, only inside the join
 * window (10 min before start → 30 min after end). Opens the in-panel call page
 * (ConsultCall), or the external Meet override in a new tab.
 */
class AppointmentVideoAction
{
    public static function make(): Action
    {
        return Action::make('joinVideo')
            ->label('Join video')
            ->icon('heroicon-o-video-camera')
            ->color('success')
            ->visible(function (?Appointment $record): bool {
                $user = Auth::user();

                return $record !== null
                    && $user !== null
                    && $record->canJoinVideoNow()
                    && app(VideoRoomService::class)->isParticipant($record, $user);
            })
            // In-panel call page (signed JWT — no Jitsi sign-in). Only an
            // external Meet override still opens in a new tab.
            ->url(fn (?Appointment $record) => $record && Auth::user()
                ? app(VideoRoomService::class)->callUrlFor($record, Auth::user())
                : null)
            ->openUrlInNewTab(fn (?Appointment $record) => (bool) ($record?->meet_url && app(VideoRoomService::class)->allowMeetOverride()));
    }
}
