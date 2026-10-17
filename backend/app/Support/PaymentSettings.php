<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;

/**
 * Registry of the online-payment settings edited at Staff → System →
 * Payment settings (PaymentSettingsPage). Values live in `settings`.
 *
 * Every gateway ships DISABLED: while none is enabled + configured, the
 * built-in mock gateway keeps the whole flow working end to end.
 *
 * Secrets (merchant key, passphrase, secret keys, private keys) are stored
 * encrypted with Crypt (APP_KEY) and are never echoed back to the admin UI —
 * the page only shows "configured ✓".
 */
class PaymentSettings
{
    public const GATEWAYS = ['payfast', 'paystack', 'ozow'];

    /** Services a payment can be for (used for "allowed methods per service"). */
    public const SERVICES = [
        'pharmacy' => 'Pharmacy orders',
        'invoice' => 'Consult invoices (appointments & see-a-doctor-now)',
        'ride' => 'Shuttle fares & fees',
        'subscription' => 'Subscriptions',
    ];

    /** Keys whose values are encrypted at rest. */
    public const SECRETS = [
        'payfast_merchant_key', 'payfast_passphrase',
        'paystack_secret_key',
        'ozow_private_key', 'ozow_api_key',
    ];

    public const DEFAULTS = [
        // --- PayFast ---
        'payfast_enabled' => '0',
        'payfast_sandbox' => '1',
        'payfast_merchant_id' => '',
        'payfast_merchant_key' => '',
        'payfast_passphrase' => '',
        'payfast_onsite' => '1',
        'payfast_validate_ip' => '1',
        'payfast_subscriptions_enabled' => '1',
        'payfast_tokenise_cards' => '1',

        // --- Paystack ---
        'paystack_enabled' => '0',
        'paystack_public_key' => '',
        'paystack_secret_key' => '',
        'paystack_validate_ip' => '0',

        // --- Ozow ---
        'ozow_enabled' => '0',
        'ozow_sandbox' => '1',
        'ozow_site_code' => '',
        'ozow_private_key' => '',
        'ozow_api_key' => '',

        // --- Routing ---
        'payments_default_gateway_web' => 'payfast',
        'payments_default_gateway_app' => 'payfast',
        'payments_methods_pharmacy' => 'payfast,paystack,ozow',
        'payments_methods_invoice' => 'payfast,paystack,ozow',
        'payments_methods_ride' => 'payfast,paystack,ozow',
        'payments_methods_subscription' => 'payfast',

        // --- Behaviour ---
        'payments_mock_when_unconfigured' => '1',
        'payments_intent_ttl_minutes' => '30',
    ];

    public static function get(string $key): mixed
    {
        return Setting::get($key, self::DEFAULTS[$key] ?? null);
    }

    public static function str(string $key): string
    {
        return trim((string) (self::get($key) ?? ''));
    }

    public static function bool(string $key): bool
    {
        return in_array(strtolower(self::str($key)), ['1', 'true', 'yes', 'on'], true);
    }

    public static function set(string $key, mixed $value): void
    {
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        }

        Setting::set($key, $value === null ? '' : (string) $value);
    }

    /** Decrypted secret, '' when unset or undecryptable (e.g. APP_KEY rotated). */
    public static function secret(string $key): string
    {
        $raw = self::str($key);
        if ($raw === '') {
            return '';
        }

        try {
            return (string) Crypt::decryptString($raw);
        } catch (\Throwable) {
            return '';
        }
    }

    public static function setSecret(string $key, ?string $plain): void
    {
        $plain = trim((string) $plain);
        Setting::set($key, $plain === '' ? '' : Crypt::encryptString($plain));
    }

    public static function hasSecret(string $key): bool
    {
        return self::secret($key) !== '';
    }

    /** @return array<int, string> gateways allowed for a service (pharmacy|invoice|ride|subscription) */
    public static function allowedFor(string $service): array
    {
        $list = array_filter(array_map('trim', explode(',', self::str('payments_methods_'.$service))));

        return array_values(array_intersect(self::GATEWAYS, $list));
    }
}
