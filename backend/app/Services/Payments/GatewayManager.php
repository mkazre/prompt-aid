<?php

namespace App\Services\Payments;

use App\Contracts\CheckoutGatewayInterface;
use App\Services\Payments\Gateways\MockGateway;
use App\Services\Payments\Gateways\OzowGateway;
use App\Services\Payments\Gateways\PayFastGateway;
use App\Services\Payments\Gateways\PaystackGateway;
use App\Support\PaymentSettings;

/** Resolves gateway drivers and what a given service/channel may offer. */
class GatewayManager
{
    public const META = [
        'payfast' => ['hint' => 'Card, instant EFT, SnapScan, Zapper', 'color' => '#e4202c', 'mark' => 'PF'],
        'paystack' => ['hint' => 'Visa, Mastercard, Apple Pay', 'color' => '#0ba4db', 'mark' => 'PS'],
        'ozow' => ['hint' => 'Instant EFT from your banking app', 'color' => '#1db954', 'mark' => 'OZ'],
        'mock' => ['hint' => 'No real money moves (test mode)', 'color' => '#6b7280', 'mark' => 'T'],
    ];

    /** @var array<string, CheckoutGatewayInterface> */
    protected array $drivers = [];

    public function get(string $key): ?CheckoutGatewayInterface
    {
        return $this->drivers[$key] ??= match ($key) {
            'payfast' => new PayFastGateway,
            'paystack' => new PaystackGateway,
            'ozow' => new OzowGateway,
            'mock' => new MockGateway,
            default => null,
        };
    }

    public function payfast(): PayFastGateway
    {
        /** @var PayFastGateway */
        return $this->get('payfast');
    }

    /** @return array<string, CheckoutGatewayInterface> real gateways that are enabled + have credentials */
    public function configured(): array
    {
        $out = [];
        foreach (PaymentSettings::GATEWAYS as $key) {
            $g = $this->get($key);
            if ($g && $g->isConfigured()) {
                $out[$key] = $g;
            }
        }

        return $out;
    }

    /** True while no real gateway is live: the mock keeps the product working. */
    public function mockActive(): bool
    {
        return $this->configured() === [] && PaymentSettings::bool('payments_mock_when_unconfigured');
    }

    public function onlineActive(): bool
    {
        return $this->configured() !== [];
    }

    /**
     * Gateways offered for a service on a channel, default first.
     *
     * @return array<int, CheckoutGatewayInterface>
     */
    public function availableFor(string $service, string $channel = 'web'): array
    {
        $configured = $this->configured();
        if ($configured === []) {
            return $this->mockActive() ? [$this->get('mock')] : [];
        }

        $allowed = PaymentSettings::allowedFor($service);
        $list = [];
        foreach ($allowed as $key) {
            if (! isset($configured[$key])) {
                continue;
            }
            if ($service === 'subscription' && ! $configured[$key]->supportsSubscriptions()) {
                continue;
            }
            $list[$key] = $configured[$key];
        }

        $default = PaymentSettings::str('payments_default_gateway_'.($channel === 'app' ? 'app' : 'web'));
        if (isset($list[$default])) {
            $list = [$default => $list[$default]] + $list;
        }

        return array_values($list);
    }

    /** @return array<int, array<string, mixed>> JSON-friendly method list */
    public function optionsFor(string $service, string $channel = 'web'): array
    {
        return array_map(fn (CheckoutGatewayInterface $g) => [
            'key' => $g->key(),
            'label' => $g->label(),
            'hint' => self::META[$g->key()]['hint'] ?? '',
            'color' => self::META[$g->key()]['color'] ?? '#111827',
            'mark' => self::META[$g->key()]['mark'] ?? strtoupper(substr($g->key(), 0, 2)),
            'test_mode' => $g->key() === 'mock'
                || ($g->key() === 'payfast' && PaymentSettings::bool('payfast_sandbox'))
                || ($g->key() === 'ozow' && PaymentSettings::bool('ozow_sandbox'))
                || ($g->key() === 'paystack' && str_starts_with(PaymentSettings::str('paystack_public_key'), 'pk_test_')),
        ], $this->availableFor($service, $channel));
    }
}
