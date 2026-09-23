<?php

namespace App\Filament\Resources\Appointments\Widgets;

use App\Models\Appointment;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

class AppointmentsHeaderStats extends StatsOverviewWidget
{
    protected function baseQuery()
    {
        /** @var User|null $user */
        $user = Auth::user();

        $query = Appointment::query();

        return match ($user?->role) {
            User::ROLE_CLINIC_ADMIN => $query->whereIn('clinic_id', $user->clinicsAdministered()->pluck('id')),
            User::ROLE_DOCTOR => $query->where('doctor_profile_id', $user->doctorProfile?->id),
            default => $query,
        };
    }

    protected function getStats(): array
    {
        return [
            Stat::make("Today", $this->baseQuery()->whereDate('date', today())->count())
                ->description('Appointments today')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('primary'),
            Stat::make('Pending', $this->baseQuery()->where('status', Appointment::STATUS_PENDING)->count())
                ->description('Awaiting confirmation')
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning'),
            Stat::make('Confirmed', $this->baseQuery()->where('status', Appointment::STATUS_CONFIRMED)->count())
                ->description('Ready to go')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('info'),
            Stat::make('Completed (this month)', $this->baseQuery()->where('status', Appointment::STATUS_COMPLETED)->whereMonth('date', now()->month)->count())
                ->description('Consultations done')
                ->descriptionIcon('heroicon-m-clipboard-document-check')
                ->color('success'),
        ];
    }
}
