<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Single registry of every admin-configurable POPIA/privacy setting, with
 * its default. Values live as key/value rows in `settings` (Setting model),
 * edited from Staff → Privacy (POPIA) → Privacy settings. Defaults here are
 * identical to what popia_migration.sql seeds, so a missing row behaves
 * exactly like a freshly seeded one.
 *
 * Retention values are in days unless the key says otherwise; 0 means
 * "never purge automatically".
 */
class PrivacySettings
{
    public const DEFAULTS = [
        // --- Information Officer (POPIA s55; shown on the POPIA page + footer) ---
        'privacy_io_name' => '',
        'privacy_io_email' => '',
        'privacy_io_phone' => '',
        'privacy_io_show_in_footer' => '1',
        'privacy_regulator_contact' => 'Information Regulator (South Africa): enquiries@inforegulator.org.za / complaints.IR@inforegulator.org.za',

        // --- Retention ---
        // HPCSA Booklet 9: adult records 6 years after the last entry (minors:
        // until age 21; mentally incompetent patients: lifetime). Applied to
        // clinical records of accounts whose deletion request was approved.
        'privacy_clinical_retention_years' => '6',
        // Chat may contain clinical discussion, so aligned with clinical retention by default.
        'privacy_retention_messages_days' => '2190',
        'privacy_retention_notifications_days' => '180',
        'privacy_retention_audit_logs_days' => '1825',
        'privacy_retention_access_logs_days' => '1825',
        'privacy_retention_dsr_exports_days' => '7',
        // Triage submissions made without an account (user_id null).
        'privacy_retention_anon_triage_days' => '90',

        // --- DSR ---
        'privacy_dsr_sla_days' => '30',
        'privacy_export_link_minutes' => '30',

        // --- Cookie notice ---
        'privacy_cookie_notice_enabled' => '1',
        'privacy_cookie_notice_text' => 'We only use essential cookies to keep you signed in and keep the site secure. We do not use advertising or tracking cookies.',
    ];

    public static function get(string $key): string
    {
        return (string) Setting::get($key, self::DEFAULTS[$key] ?? '');
    }

    public static function int(string $key): int
    {
        return max(0, (int) self::get($key));
    }

    public static function bool(string $key): bool
    {
        return in_array(strtolower(self::get($key)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @return array{name:string,email:string,phone:string}|null null when no officer is configured yet
     */
    public static function informationOfficer(): ?array
    {
        try {
            $officer = [
                'name' => trim(self::get('privacy_io_name')),
                'email' => trim(self::get('privacy_io_email')),
                'phone' => trim(self::get('privacy_io_phone')),
            ];
        } catch (\Throwable) {
            return null;
        }

        return ($officer['name'] === '' && $officer['email'] === '') ? null : $officer;
    }
}
