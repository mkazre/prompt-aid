<?php

namespace App\Filament\Actions;

use App\Services\Commerce\DoctorDelayService;
use App\Support\CommerceSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Header action for doctors (Appointments list): tell today's remaining
 * patients you're running X minutes late. See DoctorDelayService.
 */
class RunningLateAction
{
    public static function make(): Action
    {
        return Action::make('runningLate')
            ->label('Running late')
            ->icon('heroicon-o-clock')
            ->color('warning')
            ->visible(fn () => (bool) Auth::user()?->isDoctor() && Auth::user()?->doctorProfile && CommerceSettings::bool('running_late_enabled'))
            ->modalHeading("Tell today's patients you're running late")
            ->modalDescription(function () {
                $doctor = Auth::user()?->doctorProfile;
                $count = $doctor ? app(DoctorDelayService::class)->remainingToday($doctor)->count() : 0;

                return $count
                    ? "{$count} ".($count === 1 ? 'patient is' : 'patients are')." still waiting today (pending or confirmed). Each gets an alert in the app and on the website."
                    : 'You have no remaining patients today.';
            })
            ->modalSubmitActionLabel('Notify patients')
            ->schema([
                Select::make('minutes')
                    ->label('How late?')
                    ->options(collect(DoctorDelayService::MINUTE_OPTIONS)->mapWithKeys(fn ($m) => [$m => "{$m} minutes"])->all())
                    ->default(15)
                    ->required(),
                Textarea::make('message')->label('Message (optional)')->rows(3)->maxLength(500)
                    ->placeholder('e.g. An emergency came in. Thank you for your patience.'),
            ])
            ->action(function (array $data): void {
                $user = Auth::user();
                $doctor = $user?->doctorProfile;
                abort_unless($doctor, 403);

                try {
                    $notice = app(DoctorDelayService::class)->notify($doctor, (int) $data['minutes'], $data['message'] ?? null, $user, 'staff');
                } catch (RuntimeException $e) {
                    Notification::make()->title($e->getMessage())->warning()->send();

                    return;
                }

                Notification::make()
                    ->title("{$notice->patients_notified} ".($notice->patients_notified === 1 ? 'patient' : 'patients').' notified')
                    ->body("They were told you're running about {$notice->minutes} minutes late.")
                    ->success()
                    ->send();
            });
    }
}
