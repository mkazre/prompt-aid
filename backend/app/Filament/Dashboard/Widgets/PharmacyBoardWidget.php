<?php

namespace App\Filament\Dashboard\Widgets;

use App\Models\User;

/** Pharmacy vendor board: orders to accept, scripts, pipeline, stock, revenue. */
class PharmacyBoardWidget extends LiveDashboardWidget
{
    protected static string $role = User::ROLE_PHARMACY_ADMIN;

    protected string $view = 'filament.dashboard.pharmacy';
}
