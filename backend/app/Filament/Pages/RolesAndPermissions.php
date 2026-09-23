<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * A read-only reference, not an editor: access control here is role-based
 * (User::canAccessPanel() + each Filament resource's own canViewAny()),
 * not a granular per-permission matrix. Building a fake permission editor
 * that doesn't actually gate anything would be worse than not having this
 * page at all, so this documents what genuinely controls access instead.
 */
class RolesAndPermissions extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Roles & Permissions';

    protected string $view = 'filament.pages.roles-and-permissions';

    /**
     * @return array<int, array{role: string, panel: string, super_admin_only_resources: array<int, string>}>
     */
    public function getRoles(): array
    {
        return [
            [
                'role' => 'Super Admin',
                'description' => 'Full access to the staff panel and every resource, including Ride Rate Cards, Medical Schemes, Claims, Notification Log, Triage Monitor and this Audit Trail — all restricted to this role specifically via each resource\'s canViewAny().',
                'panel' => '/staff',
            ],
            [
                'role' => 'Clinic Admin',
                'description' => 'Staff panel access. Sees clinical + commerce resources; several (e.g. Invoices, Pharmacies) are scoped to the clinic(s) they administer via ScopesToClinicOrDoctor.',
                'panel' => '/staff',
            ],
            [
                'role' => 'Doctor',
                'description' => 'Staff panel access, scoped to their own doctor profile on resources using ScopesToClinicOrDoctor (Invoices, Appointments, Encounters, Prescriptions).',
                'panel' => '/staff',
            ],
            [
                'role' => 'Pharmacy Admin',
                'description' => 'A separate panel (/vendor) with only Pharmacies, Products and Orders — deliberately kept apart from /staff so a vendor never sees clinical navigation.',
                'panel' => '/vendor',
            ],
            [
                'role' => 'Third Party (Lab/Imaging Partner)',
                'description' => 'A separate panel (/partner) for lab/imaging request handling.',
                'panel' => '/partner',
            ],
            [
                'role' => 'Driver',
                'description' => 'No Filament panel access — the shuttle driver app (mobile) and its API only.',
                'panel' => 'API only',
            ],
            [
                'role' => 'Patient',
                'description' => 'No Filament panel access — the public website dashboard and mobile app only.',
                'panel' => 'Website + API only',
            ],
        ];
    }
}
