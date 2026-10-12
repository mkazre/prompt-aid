<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Admin-editable switches and copy for the support stream: help & support
 * tickets, the Help centre, the newsletter footer signup and the "How was
 * your visit?" survey. Stored as `settings` rows, edited at /staff →
 * Support → Support settings (App\Filament\Pages\SupportSettingsPage).
 * A missing row behaves like the default below.
 */
class SupportSettings
{
    public const DEFAULTS = [
        // --- Help & support tickets ---
        'support_tickets_enabled' => '1',
        'support_intro' => 'Questions about a booking, a payment or a shuttle trip? Open a ticket and our team will reply here and notify you.',
        'support_response_note' => 'We usually reply within one working day. For emergencies call the dispatch number — tickets are not monitored 24/7.',

        // --- Help centre ---
        'help_heading' => 'Help centre',
        'help_intro' => 'Answers to the questions we hear most. Can\'t find yours? Contact support and we\'ll help.',
        'help_faq_redirect' => '1',

        // --- Newsletter ---
        'newsletter_enabled' => '1',
        'newsletter_heading' => 'Health tips in your inbox',
        'newsletter_text' => 'Monthly health topics, new services and app updates. No spam.',
        'newsletter_consent_text' => 'I agree that PromptAid may email me news and health tips. I can unsubscribe at any time (POPIA).',
        'newsletter_success' => 'Thanks — you\'re subscribed.',

        // --- Satisfaction survey (NPS) ---
        'feedback_enabled' => '1',
        'feedback_appointments' => '1',
        'feedback_rides' => '1',
        'feedback_title' => 'How was your visit?',
        'feedback_body' => 'It takes 10 seconds: how likely are you to recommend PromptAid to a friend?',
        'feedback_lookback_days' => '7',

        // --- Contact form ---
        'contact_success' => 'Thanks {name}, we\'ve received your message and will get back to you soon.',
    ];

    public static function get(string $key): mixed
    {
        try {
            return Setting::get($key, self::DEFAULTS[$key] ?? null);
        } catch (\Throwable $e) {
            return self::DEFAULTS[$key] ?? null;
        }
    }

    public static function str(string $key): string
    {
        $value = trim((string) self::get($key));

        return $value !== '' ? $value : (string) (self::DEFAULTS[$key] ?? '');
    }

    public static function int(string $key): int
    {
        $v = self::get($key);

        return is_numeric($v) ? (int) $v : (int) (self::DEFAULTS[$key] ?? 0);
    }

    public static function bool(string $key): bool
    {
        return in_array(strtolower(trim((string) self::get($key))), ['1', 'true', 'yes', 'on'], true);
    }

    public static function set(string $key, mixed $value): void
    {
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        }

        Setting::set($key, $value === null ? '' : (string) $value);
    }
}
