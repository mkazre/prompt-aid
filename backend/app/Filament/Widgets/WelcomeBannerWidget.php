<?php

namespace App\Filament\Widgets;

use App\Models\User;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

class WelcomeBannerWidget extends Widget
{
    protected string $view = 'filament.widgets.welcome-banner';

    protected int | string | array $columnSpan = 'full';

    protected static ?int $sort = -20;

    public function getGreeting(): string
    {
        $hour = (int) now()->format('G');

        return match (true) {
            $hour < 12 => 'Good morning',
            $hour < 17 => 'Good afternoon',
            default => 'Good evening',
        };
    }

    public function getUser(): ?User
    {
        return Auth::user();
    }

    /**
     * @return array{label: string, description: string}
     */
    public function getRoleBlurb(): array
    {
        /** @var User|null $user */
        $user = Auth::user();

        return match ($user?->role) {
            User::ROLE_SUPER_ADMIN => ['label' => 'Platform Overview', 'description' => 'Here is what is happening across every clinic today.'],
            User::ROLE_CLINIC_ADMIN => ['label' => 'Clinic Overview', 'description' => 'Here is how your clinic is performing today.'],
            User::ROLE_DOCTOR => ['label' => 'Have a great day at work', 'description' => 'Here is your schedule and patient activity.'],
            User::ROLE_THIRD_PARTY => ['label' => 'Lab & Diagnostics', 'description' => 'Here are the requests waiting on you today.'],
            User::ROLE_PHARMACY_ADMIN => ['label' => 'Pharmacy Overview', 'description' => 'Here is how your store is performing today.'],
            default => ['label' => 'Welcome back', 'description' => 'Here is your overview.'],
        };
    }

    /**
     * @return array<int, array{label: string, url: string, icon: string, color: string}>
     */
    public function getQuickActions(): array
    {
        /** @var User|null $user */
        $user = Auth::user();

        return match ($user?->role) {
            User::ROLE_SUPER_ADMIN, User::ROLE_CLINIC_ADMIN => [
                ['label' => 'New Appointment', 'url' => route('filament.admin.resources.appointments.create'), 'icon' => 'heroicon-o-calendar-days', 'color' => 'primary'],
                ['label' => 'Add Doctor', 'url' => route('filament.admin.resources.doctor-profiles.create'), 'icon' => 'heroicon-o-user-plus', 'color' => 'gray'],
            ],
            User::ROLE_DOCTOR => [
                ['label' => 'View Schedule', 'url' => route('filament.admin.resources.appointments.index'), 'icon' => 'heroicon-o-calendar-days', 'color' => 'primary'],
                ['label' => 'My Patients', 'url' => route('filament.admin.resources.patient-profiles.index'), 'icon' => 'heroicon-o-users', 'color' => 'gray'],
            ],
            User::ROLE_THIRD_PARTY => [
                ['label' => 'Lab Requests', 'url' => route('filament.admin.resources.lab-requests.index'), 'icon' => 'heroicon-o-beaker', 'color' => 'primary'],
            ],
            User::ROLE_PHARMACY_ADMIN => [
                ['label' => 'Orders', 'url' => route('filament.admin.resources.orders.index'), 'icon' => 'heroicon-o-shopping-bag', 'color' => 'primary'],
                ['label' => 'Products', 'url' => route('filament.admin.resources.products.index'), 'icon' => 'heroicon-o-cube', 'color' => 'gray'],
            ],
            default => [],
        };
    }
}
