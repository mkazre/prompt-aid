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
        'conversations' => 'Clinical',
        'clinics' => 'Clinical',
        'doctor-profiles' => 'Clinical',
        'doctor-matches' => 'Clinical',
        'doctor-delay-notices' => 'Clinical',
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
        'promo-codes' => 'Marketplace',

        'app-widgets' => 'Site',
        'health-articles' => 'Site',
        'menus' => 'Site',
        'page-templates' => 'Site',
        'pages' => 'Site',
        'health-topics' => 'Site',
        'testimonials' => 'Site',

        'service-categories' => 'Commerce',

        'driver-profiles' => 'Ride Service',
        'ride-rate-cards' => 'Ride Service',
        'rides' => 'Ride Service',
        'ride-series' => 'Ride Service',

        'third-party-profiles' => 'Users & Access',
        'users' => 'Users & Access',

        'audit-logs' => 'System',
        'conditions' => 'System',
        'emergency-contacts' => 'System',
        'notification-log' => 'System',
        'provider-applications' => 'System',
        'settings' => 'System',
        'triage-config' => 'System',

        'triage-submissions' => 'Overview',

        // Support stream: tickets, help centre, announcements, inbox, newsletter, NPS.
        'support-tickets' => 'Support',
        'faqs' => 'Support',
        'faq-categories' => 'Support',
        'announcements' => 'Support',
        'contact-messages' => 'Support',
        'newsletter-subscribers' => 'Support',
        'feedback-surveys' => 'Support',

        // POPIA compliance pack (super_admin-only resources).
        'consents' => 'Privacy',
        'data-access-logs' => 'Privacy',
        'dsr-requests' => 'Privacy',
        'legal-documents' => 'Privacy',
        'security-incidents' => 'Privacy',
    ];

    /**
     * @var array<string, array{group: string, label: string}> single,
     * non-CRUD keys for custom Pages
     */
    public const PAGES = [
        'live-dispatch.view' => ['group' => 'Ride Service', 'label' => 'Live Dispatch'],
        'shuttle-settings.manage' => ['group' => 'Ride Service', 'label' => 'Shuttle settings (pricing, cancellation, dispatch)'],
        'ride-simulator.run' => ['group' => 'Ride Service', 'label' => 'TEST trip simulator'],
        'media-library.view' => ['group' => 'Site', 'label' => 'Media Library'],
        'site-content.view' => ['group' => 'Site', 'label' => 'Site content (home page, emergency popup, labels)'],
        'roles-permissions.view' => ['group' => 'System', 'label' => 'Roles & Permissions (view)'],
        'roles-permissions.manage' => ['group' => 'System', 'label' => 'Roles & Permissions (manage)'],
        'broadcast-notifications.send' => ['group' => 'System', 'label' => 'Broadcast notifications'],
        'privacy-settings.manage' => ['group' => 'Privacy', 'label' => 'Privacy settings (retention, Information Officer)'],
        'doctor-now-settings.manage' => ['group' => 'System', 'label' => 'On-demand doctors settings'],
        'health-settings.manage' => ['group' => 'System', 'label' => 'Patient health settings (reminders, vitals ranges, feature switches)'],
        'referrals.view' => ['group' => 'Marketplace', 'label' => 'Referrals report'],
        'commerce-settings.manage' => ['group' => 'System', 'label' => 'Commerce & alerts settings (invoices, referrals, agenda, document expiry)'],
        'expiring-documents.view' => ['group' => 'System', 'label' => 'Expiring documents'],
        'support-settings.manage' => ['group' => 'Support', 'label' => 'Support settings (tickets, help centre, newsletter, surveys)'],
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
                'invoices', 'claims', 'conversations',
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
