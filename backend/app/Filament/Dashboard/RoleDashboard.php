<?php

namespace App\Filament\Dashboard;

use App\Filament\Dashboard\Widgets\ClinicBoardWidget;
use App\Filament\Dashboard\Widgets\CommandCentreWidget;
use App\Filament\Dashboard\Widgets\DoctorDeskWidget;
use App\Filament\Dashboard\Widgets\PartnerBoardWidget;
use App\Filament\Dashboard\Widgets\PharmacyBoardWidget;
use App\Filament\Widgets\ActivityChartWidget;
use App\Filament\Widgets\TopDoctorsWidget;
use App\Filament\Widgets\UpcomingAppointmentsWidget;
use App\Models\User;
use Filament\Pages\Dashboard;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;

/**
 * Landing page for /staff, /vendor and /partner: one live board per role
 * (each widget is role-gated and scoped in its own canView/data), followed by
 * the existing trend widgets. Registered in the three panel providers in
 * place of Filament's stock Dashboard (same route + name).
 */
class RoleDashboard extends Dashboard
{
    protected static ?string $title = 'Dashboard';

    // Keep Filament's stock route name (filament.{panel}.pages.dashboard) and URL.
    protected static ?string $slug = 'dashboard';

    public function getTitle(): string|Htmlable
    {
        return 'Dashboard';
    }

    /** The board draws its own big header. */
    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    public function getWidgets(): array
    {
        return match (Auth::user()?->role) {
            User::ROLE_SUPER_ADMIN => [CommandCentreWidget::class, ActivityChartWidget::class, TopDoctorsWidget::class],
            User::ROLE_CLINIC_ADMIN => [ClinicBoardWidget::class, ActivityChartWidget::class, TopDoctorsWidget::class],
            User::ROLE_DOCTOR => [DoctorDeskWidget::class, ActivityChartWidget::class, UpcomingAppointmentsWidget::class],
            User::ROLE_PHARMACY_ADMIN => [PharmacyBoardWidget::class, ActivityChartWidget::class],
            User::ROLE_THIRD_PARTY => [PartnerBoardWidget::class, ActivityChartWidget::class],
            default => [],
        };
    }

    public function getColumns(): int|array
    {
        return ['default' => 1, 'lg' => 3];
    }
}
