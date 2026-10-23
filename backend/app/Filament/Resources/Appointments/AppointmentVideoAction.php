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
            ->label('Open consult (video)')
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

    /**
     * "Call patient" for the appointment's own doctor on a call-back
     * consult (patient chose "call me back" when booking, or an orange
     * triage call back). Opens the call card: Call / WhatsApp / outcomes.
     */
    public static function callback(): Action
    {
        return Action::make('callPatient')
            ->label('Open consult (call back)')
            ->icon('heroicon-o-phone')
            ->color('success')
            ->visible(fn (?Appointment $record): bool => $record !== null
                && $record->isCallback()
                && in_array($record->status, [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED, Appointment::STATUS_CHECKED_IN], true)
                && (int) $record->doctor_profile_id === (int) Auth::user()?->doctorProfile?->id)
            // Consult hub: in-app video / voice / chat first, phone + WhatsApp as fallback.
            ->url(fn (?Appointment $record) => $record ? \App\Filament\Pages\ConsultHub::getUrl(['appointment' => $record->id], panel: 'admin') : null);
    }
}
