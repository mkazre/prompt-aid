<?php

namespace App\Support;

use App\Models\User;

/**
 * The full catalogue of granular permission keys the Staff panel
 * understands, grouped the same way as the panel's own navigation groups,
 * plus the default grant sets used when a user has no custom Role
 * assigned (User::role_id is null) — see User::hasPermission().
 *
 * Every Filament\Resources\* resource contributes four keys via
 * ChecksPermissions (`{prefix}.view|create|edit|delete`); a handful of
 * custom Pages contribute their own single keys below.
 */
class Permissions
{
    public const ACTIONS = ['view', 'create', 'edit', 'delete'];

    /**
     * @var array<string, string> permission-key prefix => navigation group
     */
    public const RESOURCES = [
        'appointments' => 'Clinical',
        'clinics' => 'Clinical',
        'doctor-profiles' => 'Clinical',
        'encounters' => 'Clinical',
        'lab-requests' => 'Clinical',
        'patient-profiles' => 'Clinical',
        'prescriptions' => 'Clinical',
        'reviews' => 'Clinical',
        'services' => 'Clinical',

        'claims' => 'Billing',
        'invoices' => 'Billing',
        'medical-schemes' => 'Billing',
        'payments' => 'Billing',

        'orders' => 'Marketplace',
        'pharmacies' => 'Marketplace',
        'products' => 'Marketplace',

        'menus' => 'Site',
        'page-templates' => 'Site',
        'pages' => 'Site',

        'service-categories' => 'Commerce',

        'driver-profiles' => 'Ride Service',
        'ride-rate-cards' => 'Ride Service',
        'rides' => 'Ride Service',
        'ride-series' => 'Ride Service',

        'third-party-profiles' => 'Users & Access',
        'users' => 'Users & Access',

        'audit-logs' => 'System',
        'notification-log' => 'System',
        'settings' => 'System',

        'triage-submissions' => 'Overview',
    ];

    /**
     * @var array<string, array{group: string, label: string}> single,
     * non-CRUD keys for custom Pages
     */
    public const PAGES = [
        'live-dispatch.view' => ['group' => 'Ride Service', 'label' => 'Live Dispatch'],
        'media-library.view' => ['group' => 'Site', 'label' => 'Media Library'],
        'roles-permissions.view' => ['group' => 'System', 'label' => 'Roles & Permissions (view)'],
        'roles-permissions.manage' => ['group' => 'System', 'label' => 'Roles & Permissions (manage)'],
    ];

    /**
     * @return array<int, string> every permission key that exists, in
     * `{prefix}.{action}` form for resources plus the standalone page keys
     */
    public static function all(): array
    {
        $keys = [];

        foreach (array_keys(self::RESOURCES) as $prefix) {
            foreach (self::ACTIONS as $action) {
                $keys[] = "{$prefix}.{$action}";
            }
        }

        return array_merge($keys, array_keys(self::PAGES));
    }

    /**
     * @return array<string, array<int, string>> group label => permission keys in it
     */
    public static function grouped(): array
    {
        $groups = [];

        foreach (self::RESOURCES as $prefix => $group) {
            foreach (self::ACTIONS as $action) {
                $groups[$group][] = "{$prefix}.{$action}";
            }
        }

        foreach (self::PAGES as $key => $meta) {
            $groups[$meta['group']][] = $key;
        }

        return $groups;
    }

    /**
     * The permission set a user gets when their `role_id` is null, keyed
     * by the legacy `User::role` enum value. This intentionally mirrors
     * what each enum role could already do before this permission layer
     * existed (see each resource's pre-existing canViewAny()/getEloquentQuery()),
     * with two deliberate tightenings flagged for review: `settings.*` and
     * ride-service/system-only resources are no longer implicitly open to
     * clinic_admin/doctor just because they weren't explicitly gated
     * before — see RoleSeeder for the full list assigned to each seeded
     * system Role, which is what actually governs behaviour once this
     * migration has run (this method is only the fallback for the
     * hopefully-brief window before every account has a role_id).
     *
     * @return array<int, string>|'*'
     */
    public static function defaultsForEnumRole(string $role): array|string
    {
        return match ($role) {
            User::ROLE_SUPER_ADMIN => '*',

            User::ROLE_CLINIC_ADMIN => self::keysFor([
                'appointments', 'clinics', 'doctor-profiles', 'encounters',
                'lab-requests', 'patient-profiles', 'prescriptions', 'reviews',
                'services', 'claims', 'invoices', 'medical-schemes', 'payments',
                'orders', 'pharmacies', 'products', 'service-categories',
                'third-party-profiles',
            ]),

            User::ROLE_DOCTOR => self::keysFor([
                'appointments', 'doctor-profiles', 'encounters', 'lab-requests',
                'patient-profiles', 'prescriptions', 'reviews', 'services',
                'invoices', 'claims',
            ]),

            default => [],
        };
    }

    /**
     * @param  array<int, string>  $prefixes
     * @return array<int, string>
     */
    protected static function keysFor(array $prefixes): array
    {
        $keys = [];

        foreach ($prefixes as $prefix) {
            foreach (self::ACTIONS as $action) {
                $keys[] = "{$prefix}.{$action}";
            }
        }

        return $keys;
    }
}
