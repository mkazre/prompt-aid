<?php

namespace App\Filament\Dashboard\Widgets;

use App\Models\User;

/** Clinic admin live board for the clinics this admin runs. */
class ClinicBoardWidget extends LiveDashboardWidget
{
    protected static string $role = User::ROLE_CLINIC_ADMIN;

    protected string $view = 'filament.dashboard.clinic';
}
