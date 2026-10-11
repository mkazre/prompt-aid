<?php

namespace App\Support\Widgets;

/**
 * The single source of truth for which config-driven widgets exist, which
 * role/screen each one ships on, and what its `settings` keys mean. Used by
 * the create-table migration (seed), the Filament resource (helper text,
 * option lists) and WidgetDataService (which keys it knows how to compute).
 *
 * Mirrors app_widgets_migration.sql in the repo root — if you add a row
 * here, add the same INSERT there so FTP-deployed databases get it too.
 *
 * Any key ending in `_banner` is rendered as a promo banner on both the
 * app and the web, so admins can add extra banners to any screen without
 * a code change.
 */
class WidgetCatalog
{
    public const ROLES = [
        'patient' => 'Patient',
        'doctor' => 'Doctor',
        'driver' => 'Driver',
        'pharmacy_admin' => 'Pharmacy',
        'third_party' => 'Third-party partner',
    ];

    public const SCREENS = [
        'home' => 'Home',
        'find_care' => 'Find care',
        'shop' => 'Shop / pharmacy',
        'record' => 'My record',
        'calendar' => 'Calendar',
        'profile' => 'Profile',
    ];

    /**
     * Human explanation of every settings key a widget understands — shown
     * under the settings editor in the admin panel.
     */
    public const SETTINGS_HELP = [
        'limit' => 'How many items to show (number).',
        'days' => 'Look-back window in days (number).',
        'hours' => 'Only show if newer than this many hours (number).',
        'threshold' => 'Stock level at or below which a product counts as low (number).',
        'size' => '"half" to sit side by side with the next half-width widget, otherwise full width.',
        'accent' => 'Accent colour as a hex code, e.g. #1F4E7A.',
        'icon' => 'Feather icon name for the app, e.g. users, calendar, star (see feathericons.com).',
        'cta_label' => 'Button text, e.g. "Book now" or "Learn more".',
        'cta_target' => 'Where the app button goes: a tab, or tab/screen — e.g. Care, Pharmacy/ScriptUpload, Rides, Profile/Invoices.',
        'web_url' => 'Where the website button goes, e.g. /doctors or https://…',
        'body' => 'Banner body text.',
        'image_url' => 'External image URL (the uploaded image above wins if both are set).',
        'placeholder' => 'Search box placeholder text.',
    ];

    /**
     * @var array<int, array{key: string, title: string, subtitle: ?string, role: string, screen: string, sort_order: int, settings: array<string, string>}>
     */
    public const SEED = [
        // --- Patient · home ---
        ['key' => 'patient_search_shortcuts', 'title' => 'What do you need today?', 'subtitle' => null, 'role' => 'patient', 'screen' => 'home', 'sort_order' => 10, 'settings' => ['placeholder' => 'Search doctor, speciality or clinic']],
        ['key' => 'patient_promo_banner', 'title' => 'Early protection for your family health', 'subtitle' => 'Book a check-up for the whole family in a few taps.', 'role' => 'patient', 'screen' => 'home', 'sort_order' => 20, 'settings' => ['cta_label' => 'Learn more', 'cta_target' => 'Care', 'web_url' => '/doctors', 'accent' => '#1F4E7A']],
        ['key' => 'patient_next_appointment', 'title' => 'Next appointment', 'subtitle' => null, 'role' => 'patient', 'screen' => 'home', 'sort_order' => 30, 'settings' => []],
        ['key' => 'patient_latest_triage', 'title' => 'Your last triage result', 'subtitle' => null, 'role' => 'patient', 'screen' => 'home', 'sort_order' => 40, 'settings' => ['hours' => '48']],
        ['key' => 'patient_visit_stats', 'title' => 'My health at a glance', 'subtitle' => null, 'role' => 'patient', 'screen' => 'home', 'sort_order' => 50, 'settings' => []],
        ['key' => 'patient_upcoming_ride', 'title' => 'Your shuttle', 'subtitle' => null, 'role' => 'patient', 'screen' => 'home', 'sort_order' => 60, 'settings' => []],
        ['key' => 'patient_clinic_services', 'title' => 'Clinic services', 'subtitle' => null, 'role' => 'patient', 'screen' => 'home', 'sort_order' => 70, 'settings' => ['limit' => '8']],
        ['key' => 'patient_top_doctors', 'title' => 'Top doctors', 'subtitle' => 'Highest rated near you', 'role' => 'patient', 'screen' => 'home', 'sort_order' => 80, 'settings' => ['limit' => '6', 'cta_label' => 'Book now']],
        ['key' => 'patient_nearby_clinics', 'title' => 'Nearby clinics', 'subtitle' => null, 'role' => 'patient', 'screen' => 'home', 'sort_order' => 90, 'settings' => ['limit' => '4']],
        ['key' => 'patient_active_prescriptions', 'title' => 'Active prescriptions', 'subtitle' => null, 'role' => 'patient', 'screen' => 'home', 'sort_order' => 100, 'settings' => ['days' => '30', 'limit' => '5']],
        ['key' => 'patient_pending_lab_results', 'title' => 'Lab results', 'subtitle' => null, 'role' => 'patient', 'screen' => 'home', 'sort_order' => 110, 'settings' => ['limit' => '3']],
        ['key' => 'patient_recent_orders', 'title' => 'Recent orders', 'subtitle' => null, 'role' => 'patient', 'screen' => 'home', 'sort_order' => 120, 'settings' => ['limit' => '3']],
        ['key' => 'patient_outstanding_invoices', 'title' => 'Outstanding invoices', 'subtitle' => null, 'role' => 'patient', 'screen' => 'home', 'sort_order' => 130, 'settings' => ['limit' => '3']],
        ['key' => 'patient_medical_aid_card', 'title' => 'Medical aid', 'subtitle' => null, 'role' => 'patient', 'screen' => 'home', 'sort_order' => 140, 'settings' => ['accent' => '#101012']],
        ['key' => 'patient_emergency_quickdial', 'title' => 'Emergency numbers', 'subtitle' => 'Tap to call', 'role' => 'patient', 'screen' => 'home', 'sort_order' => 150, 'settings' => ['limit' => '4']],
        ['key' => 'patient_health_articles', 'title' => 'Health articles', 'subtitle' => null, 'role' => 'patient', 'screen' => 'home', 'sort_order' => 160, 'settings' => ['limit' => '4']],

        // --- Patient · find care ---
        ['key' => 'care_specialties', 'title' => 'Browse by speciality', 'subtitle' => null, 'role' => 'patient', 'screen' => 'find_care', 'sort_order' => 10, 'settings' => ['limit' => '10']],
        ['key' => 'care_top_doctors', 'title' => 'Top rated doctors', 'subtitle' => null, 'role' => 'patient', 'screen' => 'find_care', 'sort_order' => 20, 'settings' => ['limit' => '6', 'cta_label' => 'Book now']],
        ['key' => 'care_nearby_clinics', 'title' => 'Clinics near you', 'subtitle' => null, 'role' => 'patient', 'screen' => 'find_care', 'sort_order' => 30, 'settings' => ['limit' => '3']],

        // --- Patient · shop ---
        ['key' => 'shop_promo_banner', 'title' => 'Have a script? We deliver.', 'subtitle' => 'Upload it and a pharmacist reviews it before dispatch.', 'role' => 'patient', 'screen' => 'shop', 'sort_order' => 10, 'settings' => ['cta_label' => 'Upload script', 'cta_target' => 'Pharmacy/ScriptUpload', 'web_url' => '/shop', 'accent' => '#1F7A4C']],
        ['key' => 'shop_categories', 'title' => 'Shop by category', 'subtitle' => null, 'role' => 'patient', 'screen' => 'shop', 'sort_order' => 20, 'settings' => ['limit' => '8']],
        ['key' => 'shop_featured_products', 'title' => 'Featured products', 'subtitle' => null, 'role' => 'patient', 'screen' => 'shop', 'sort_order' => 30, 'settings' => ['limit' => '8']],

        // --- Patient · record ---
        ['key' => 'record_health_summary', 'title' => 'Health summary', 'subtitle' => null, 'role' => 'patient', 'screen' => 'record', 'sort_order' => 10, 'settings' => []],
        ['key' => 'record_recent_encounters', 'title' => 'Recent consultations', 'subtitle' => null, 'role' => 'patient', 'screen' => 'record', 'sort_order' => 20, 'settings' => ['limit' => '4']],

        // --- Doctor · home ---
        ['key' => 'doctor_total_patients', 'title' => 'Total patients', 'subtitle' => null, 'role' => 'doctor', 'screen' => 'home', 'sort_order' => 10, 'settings' => ['size' => 'half', 'icon' => 'users', 'accent' => '#1F4E7A']],
        ['key' => 'doctor_total_appointments', 'title' => 'Total appointments', 'subtitle' => null, 'role' => 'doctor', 'screen' => 'home', 'sort_order' => 20, 'settings' => ['size' => 'half', 'icon' => 'calendar', 'accent' => '#D0211C']],
        ['key' => 'doctor_weekly_chart', 'title' => 'Appointments this week', 'subtitle' => null, 'role' => 'doctor', 'screen' => 'home', 'sort_order' => 30, 'settings' => ['accent' => '#1F4E7A']],
        ['key' => 'doctor_availability', 'title' => 'On-demand availability', 'subtitle' => 'Receive instant consult requests', 'role' => 'doctor', 'screen' => 'home', 'sort_order' => 40, 'settings' => []],
        ['key' => 'doctor_pending_requests', 'title' => 'Waiting for a doctor', 'subtitle' => 'Patients requesting your speciality now', 'role' => 'doctor', 'screen' => 'home', 'sort_order' => 50, 'settings' => ['limit' => '3']],
        ['key' => 'doctor_today_appointments', 'title' => "Today's appointments", 'subtitle' => null, 'role' => 'doctor', 'screen' => 'home', 'sort_order' => 60, 'settings' => []],
        ['key' => 'doctor_rating', 'title' => 'Rating', 'subtitle' => null, 'role' => 'doctor', 'screen' => 'home', 'sort_order' => 70, 'settings' => ['size' => 'half', 'icon' => 'star', 'accent' => '#F2C200']],
        ['key' => 'doctor_completed_month', 'title' => 'Completed this month', 'subtitle' => null, 'role' => 'doctor', 'screen' => 'home', 'sort_order' => 80, 'settings' => ['size' => 'half', 'icon' => 'check-circle', 'accent' => '#1F7A4C']],

        // --- Doctor · calendar ---
        ['key' => 'doctor_month_calendar', 'title' => 'Calendar', 'subtitle' => null, 'role' => 'doctor', 'screen' => 'calendar', 'sort_order' => 10, 'settings' => []],

        // --- Driver · home ---
        ['key' => 'driver_next_pickup', 'title' => 'Next pickup', 'subtitle' => null, 'role' => 'driver', 'screen' => 'home', 'sort_order' => 10, 'settings' => []],
        ['key' => 'driver_today_trips', 'title' => 'Trips today', 'subtitle' => null, 'role' => 'driver', 'screen' => 'home', 'sort_order' => 20, 'settings' => ['size' => 'half', 'icon' => 'navigation', 'accent' => '#1F4E7A']],
        ['key' => 'driver_week_earnings', 'title' => 'Earnings this week', 'subtitle' => null, 'role' => 'driver', 'screen' => 'home', 'sort_order' => 30, 'settings' => ['size' => 'half', 'icon' => 'dollar-sign', 'accent' => '#1F7A4C']],
        ['key' => 'driver_weekly_chart', 'title' => 'Trips this week', 'subtitle' => null, 'role' => 'driver', 'screen' => 'home', 'sort_order' => 40, 'settings' => ['accent' => '#1F4E7A']],
        ['key' => 'driver_rating', 'title' => 'Your rating', 'subtitle' => null, 'role' => 'driver', 'screen' => 'home', 'sort_order' => 50, 'settings' => ['icon' => 'star', 'accent' => '#F2C200']],

        // --- Pharmacy · home ---
        ['key' => 'pharmacy_pending_orders', 'title' => 'Pending orders', 'subtitle' => null, 'role' => 'pharmacy_admin', 'screen' => 'home', 'sort_order' => 10, 'settings' => ['size' => 'half', 'icon' => 'shopping-bag', 'accent' => '#D0211C']],
        ['key' => 'pharmacy_pending_scripts', 'title' => 'Scripts to review', 'subtitle' => null, 'role' => 'pharmacy_admin', 'screen' => 'home', 'sort_order' => 20, 'settings' => ['size' => 'half', 'icon' => 'file-text', 'accent' => '#E4701E']],
        ['key' => 'pharmacy_revenue_month', 'title' => 'Revenue this month', 'subtitle' => null, 'role' => 'pharmacy_admin', 'screen' => 'home', 'sort_order' => 30, 'settings' => ['size' => 'half', 'icon' => 'trending-up', 'accent' => '#1F7A4C']],
        ['key' => 'pharmacy_low_stock', 'title' => 'Low stock', 'subtitle' => null, 'role' => 'pharmacy_admin', 'screen' => 'home', 'sort_order' => 40, 'settings' => ['size' => 'half', 'icon' => 'alert-triangle', 'accent' => '#9E1813', 'threshold' => '10']],

        // --- Third-party partner · home ---
        ['key' => 'tp_new_requests', 'title' => 'New requests', 'subtitle' => null, 'role' => 'third_party', 'screen' => 'home', 'sort_order' => 10, 'settings' => ['size' => 'half', 'icon' => 'inbox', 'accent' => '#D0211C']],
        ['key' => 'tp_active_requests', 'title' => 'In progress', 'subtitle' => null, 'role' => 'third_party', 'screen' => 'home', 'sort_order' => 20, 'settings' => ['size' => 'half', 'icon' => 'activity', 'accent' => '#1F4E7A']],
        ['key' => 'tp_completed_month', 'title' => 'Completed this month', 'subtitle' => null, 'role' => 'third_party', 'screen' => 'home', 'sort_order' => 30, 'settings' => ['size' => 'half', 'icon' => 'check-circle', 'accent' => '#1F7A4C']],
        ['key' => 'tp_rating', 'title' => 'Rating', 'subtitle' => null, 'role' => 'third_party', 'screen' => 'home', 'sort_order' => 40, 'settings' => ['size' => 'half', 'icon' => 'star', 'accent' => '#F2C200']],
    ];

    /** @return array<int, string> every seeded key */
    public static function seededKeys(): array
    {
        return array_column(self::SEED, 'key');
    }

    public static function isSeeded(?string $key): bool
    {
        return $key !== null && in_array($key, self::seededKeys(), true);
    }

    public static function isBanner(?string $key): bool
    {
        return $key !== null && str_ends_with($key, '_banner');
    }

    /** Two-letter initials, skipping honorifics ("Dr. Mr. Dion Willms" → "DW"). */
    public static function initials(?string $name): string
    {
        $parts = array_values(array_filter(
            preg_split('/\s+/', trim((string) $name)) ?: [],
            fn ($p) => $p !== '' && ! preg_match('/^(dr|mr|mrs|ms|miss|prof|sr|jr|md|i{1,3}|iv|v)\.?$/i', $p)
        ));

        if ($parts === []) {
            return '?';
        }

        $first = mb_substr($parts[0], 0, 1);
        $last = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';

        return mb_strtoupper($first.$last);
    }

    /**
     * Best-guess Feather icon for a free-text category/speciality name —
     * mirrored in mobile/src/widgets/icons.ts.
     */
    public static function iconFor(?string $name): string
    {
        $n = strtolower((string) $name);

        $map = [
            'dent' => 'smile', 'tooth' => 'smile', 'eye' => 'eye', 'opt' => 'eye', 'vision' => 'eye',
            'cardi' => 'heart', 'heart' => 'heart', 'lab' => 'droplet', 'blood' => 'droplet', 'patho' => 'droplet',
            'imag' => 'activity', 'x-ray' => 'activity', 'radio' => 'activity', 'physio' => 'activity',
            'pharm' => 'package', 'medic' => 'package', 'vitamin' => 'package', 'supplement' => 'package',
            'baby' => 'smile', 'child' => 'smile', 'paed' => 'smile', 'pediat' => 'smile',
            'emerg' => 'alert-triangle', 'ambul' => 'truck', 'shuttle' => 'navigation', 'transport' => 'navigation',
            'video' => 'video', 'tele' => 'video', 'virtual' => 'video',
            'gp' => 'user', 'general' => 'user', 'family' => 'users', 'consult' => 'user',
            'nurs' => 'plus-square', 'home' => 'plus-square', 'hospital' => 'plus-square', 'clinic' => 'plus-square',
            'fever' => 'thermometer', 'flu' => 'thermometer', 'device' => 'thermometer',
            'skin' => 'shield', 'derm' => 'shield', 'immun' => 'shield', 'vaccin' => 'shield',
            'mental' => 'heart', 'psych' => 'heart', 'counsel' => 'heart',
        ];

        foreach ($map as $needle => $icon) {
            if (str_contains($n, $needle)) {
                return $icon;
            }
        }

        return 'plus-square';
    }
}
