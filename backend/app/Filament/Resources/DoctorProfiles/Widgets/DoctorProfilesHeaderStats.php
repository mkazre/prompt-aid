<?php

namespace App\Filament\Resources\DoctorProfiles\Widgets;

use App\Models\DoctorProfile;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class DoctorProfilesHeaderStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Total Doctors', DoctorProfile::query()->count())
                ->description('On the platform')
                ->descriptionIcon('heroicon-m-user-group')
                ->color('info'),
            Stat::make('Active', DoctorProfile::query()->where('status', 'active')->count())
                ->description('Approved & live')
                ->descriptionIcon('heroicon-m-check-badge')
                ->color('success'),
            Stat::make('Pending Approval', DoctorProfile::query()->where('status', 'pending_approval')->count())
                ->description('Need review')
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning'),
            Stat::make('Accepting Appointments', DoctorProfile::query()->where('is_accepting_appointments', true)->where('status', 'active')->count())
                ->description('Bookable right now')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('primary'),
        ];
    }
}
