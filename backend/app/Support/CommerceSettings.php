<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Registry of the admin-editable settings for promo codes, referrals,
 * printable invoices, doctor "running late" / daily agenda alerts and
 * document expiry reminders. Values live as key/value rows in `settings`,
 * edited at Staff → System → Commerce & alerts (CommerceSettingsPage).
 * A missing row behaves exactly like the default below.
 */
class CommerceSettings
{
    public const DEFAULTS = [
        // --- Promo codes ---
        'promo_codes_enabled' => '1',

        // --- Referral programme ---
        'referrals_enabled' => '1',
        'referral_reward_text' => 'Invite friends and family to PromptAid. Every friend who signs up with your code helps more people get care quickly.',
        'referral_share_message' => 'Join me on PromptAid — book doctors, order medicine and get a shuttle to your appointment. Sign up with my code {code}: {link}',

        // --- Printable invoices (company details) ---
        'invoice_company_name' => 'PromptAid (Pty) Ltd',
        'invoice_company_address' => '',
        'invoice_company_email' => '',
        'invoice_company_phone' => '',
        'invoice_vat_number' => '',
        'invoice_registration_number' => '',
        'invoice_practice_number' => '',
        'invoice_bank_name' => '',
        'invoice_bank_account_name' => '',
        'invoice_bank_account_number' => '',
        'invoice_bank_branch_code' => '',
        'invoice_footer_note' => 'Thank you for choosing PromptAid. Please use the invoice number as your payment reference.',
        'invoice_auto_print' => '0',

        // --- Doctor "running late" ---
        'running_late_enabled' => '1',

        // --- Doctor daily agenda alert ---
        'doctor_agenda_enabled' => '1',
        'doctor_agenda_time' => '07:00',
        'doctor_agenda_timezone' => 'Africa/Johannesburg',

        // --- Document expiry reminders ---
        'doc_expiry_enabled' => '1',
        'doc_expiry_notify_admins' => '1',
    ];

    public static function get(string $key): mixed
    {
        return Setting::get($key, self::DEFAULTS[$key] ?? null);
    }

    public static function str(string $key): string
    {
        $v = self::get($key);

        return trim((string) ($v ?? ''));
    }

    public static function bool(string $key): bool
    {
        return in_array(strtolower((string) self::get($key)), ['1', 'true', 'yes', 'on'], true);
    }

    public static function set(string $key, mixed $value): void
    {
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        }

        Setting::set($key, $value === null ? '' : (string) $value);
    }

    public static function timezone(): string
    {
        $tz = self::str('doctor_agenda_timezone');

        return in_array($tz, timezone_identifiers_list(), true) ? $tz : 'Africa/Johannesburg';
    }

    /** @return array<string, string> company/banking details for the printable invoice */
    public static function invoiceDetails(): array
    {
        $out = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            if (str_starts_with($key, 'invoice_')) {
                $out[substr($key, 8)] = self::str($key);
            }
        }

        return $out;
    }
}
