<?php

namespace App\Filament\Dashboard\Widgets;

use App\Models\User;

/** Lab / allied partner board: nearby unassigned requests, jobs, turnaround, earnings. */
class PartnerBoardWidget extends LiveDashboardWidget
{
    protected static string $role = User::ROLE_THIRD_PARTY;

    protected string $view = 'filament.dashboard.partner';
}
