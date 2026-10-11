<?php

namespace App\Support\Widgets;

/**
 * Places a widget, banner or shortcut tile can link to — picked from a
 * dropdown in Site → App Widgets instead of typing route names. Each entry
 * has the app target ("Tab" or "Tab/Screen", see mobile/src/widgets/
 * helpers.ts goTo()) and the matching website path.
 */
class WidgetDestinations
{
    /** @var array<string, array{label: string, app: string, web: string}> */
    public const ALL = [
        'find_care' => ['label' => 'Find care (all doctors)', 'app' => 'Care/FindCare', 'web' => '/doctors'],
        'gp' => ['label' => 'General Practitioners', 'app' => 'Care/FindCare', 'web' => '/general-practitioners'],
        'dentists' => ['label' => 'Dentists', 'app' => 'Care/FindCare', 'web' => '/dentists'],
        'specialists' => ['label' => 'Specialists', 'app' => 'Care/FindCare', 'web' => '/specialists'],
        'virtual' => ['label' => 'Virtual / video consult', 'app' => 'Care/FindCare', 'web' => '/virtual-consult'],
        'doctor_now' => ['label' => 'See a doctor now', 'app' => 'Home/InstantConsult', 'web' => '/account/see-a-doctor'],
        'emergency' => ['label' => 'Emergency triage', 'app' => 'Home/TriageStart', 'web' => '/emergency'],
        'pharmacy' => ['label' => 'Pharmacy / shop', 'app' => 'Pharmacy/Shop', 'web' => '/shop'],
        'script_upload' => ['label' => 'Upload a script', 'app' => 'Pharmacy/ScriptUpload', 'web' => '/pharmacies'],
        'radiology' => ['label' => 'Radiology', 'app' => 'Care/FindCare', 'web' => '/radiology'],
        'laboratories' => ['label' => 'Laboratories', 'app' => 'Care/FindCare', 'web' => '/laboratories'],
        'allied' => ['label' => 'Allied practitioners', 'app' => 'Care/FindCare', 'web' => '/allied'],
        'clinics' => ['label' => 'Clinics', 'app' => 'Care/FindCare', 'web' => '/clinics'],
        'shuttle' => ['label' => 'Book a shuttle', 'app' => 'Rides/ShuttleRequest', 'web' => '/shuttle'],
        'appointments' => ['label' => 'My appointments', 'app' => 'Profile/Appointments', 'web' => '/account/appointments'],
        'lab_results' => ['label' => 'My lab results', 'app' => 'Profile/LabResults', 'web' => '/account/results'],
        'orders' => ['label' => 'My orders', 'app' => 'Profile/Orders', 'web' => '/account/orders'],
        'invoices' => ['label' => 'My invoices', 'app' => 'Profile/Invoices', 'web' => '/account/invoices'],
        'record' => ['label' => 'My medical record', 'app' => 'Profile/RecordHome', 'web' => '/account/record'],
        'health_articles' => ['label' => 'Health articles', 'app' => 'Home/HealthArticles', 'web' => '/health-articles'],
        'topic' => ['label' => 'Topic of the month', 'app' => '', 'web' => '/topic-of-the-month'],
        'testimonial' => ['label' => 'Submit a testimonial', 'app' => '', 'web' => '/testimonials/submit'],
        'about' => ['label' => 'About PromptAid', 'app' => '', 'web' => '/about'],
        'notifications' => ['label' => 'Notifications', 'app' => 'Notifications', 'web' => '/account/notifications'],
        'messages' => ['label' => 'Messages', 'app' => 'ChatList', 'web' => '/account/messages'],
    ];

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_map(fn (array $d) => $d['label'], self::ALL);
    }

    /** Resolve settings (destination key, or legacy cta_target/web_url) into app + web targets. */
    public static function resolve(array $item): array
    {
        $dest = self::ALL[$item['destination'] ?? ''] ?? null;

        return [
            'app' => ($item['cta_target'] ?? null) ?: ($dest['app'] ?? null),
            'web' => ($item['web_url'] ?? null) ?: ($dest['web'] ?? null),
            // Find care filters for destinations that land on a tab.
            'group' => in_array($item['destination'] ?? '', ['gp', 'dentists', 'specialists', 'virtual', 'radiology', 'laboratories', 'allied'], true)
                ? ($item['destination'] === 'virtual' ? 'video' : $item['destination'])
                : null,
        ];
    }

    /** Default shortcut tiles (patient search widget) until an admin edits them. */
    public const DEFAULT_SHORTCUTS = [
        ['label' => 'Doctor', 'icon' => 'user', 'color' => '#1F5FBF', 'destination' => 'find_care'],
        ['label' => 'Pharmacy', 'icon' => 'package', 'color' => '#1F7A4C', 'destination' => 'pharmacy'],
        ['label' => 'Hospital', 'icon' => 'plus-square', 'color' => '#D0211C', 'destination' => 'clinics'],
        ['label' => 'Ambulance', 'icon' => 'truck', 'color' => '#E4701E', 'destination' => 'emergency'],
        ['label' => 'Shuttle', 'icon' => 'navigation', 'color' => '#6A4C93', 'destination' => 'shuttle'],
        ['label' => 'Lab', 'icon' => 'droplet', 'color' => '#0F7C8C', 'destination' => 'laboratories'],
    ];
}
