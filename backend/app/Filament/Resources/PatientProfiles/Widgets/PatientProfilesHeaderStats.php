<?php

namespace App\Filament\Resources\PatientProfiles\Widgets;

use App\Models\PatientProfile;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PatientProfilesHeaderStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Total Patients', PatientProfile::query()->count())
                ->description('Registered')
                ->descriptionIcon('heroicon-m-users')
                ->color('info'),
            Stat::make('New (this month)', PatientProfile::query()->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->count())
                ->description('Signed up')
                ->descriptionIcon('heroicon-m-user-plus')
                ->color('success'),
            Stat::make('With Appointments', PatientProfile::query()->has('appointments')->count())
                ->description('Have booked at least once')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('primary'),
        ];
    }
}
