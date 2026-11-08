<?php

namespace App\Filament\Dashboard\Widgets;

use App\Models\User;

/** Super admin command centre: live requests, fleet, doctors, payments and alerts. */
class CommandCentreWidget extends LiveDashboardWidget
{
    protected static string $role = User::ROLE_SUPER_ADMIN;

    protected string $view = 'filament.dashboard.admin';
}
