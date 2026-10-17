<?php

namespace App\Services\Dispatch\Handlers;

use App\Contracts\NotificationDispatcherInterface;
use App\Models\DispatchJob;
use App\Models\User;
use App\Services\Dispatch\DispatchService;
use App\Support\DispatchSettings;
use Illuminate\Support\Facades\Log;

abstract class BaseHandler implements DispatchHandler
{
    public function offerTtl(DispatchJob $job): int
    {
        return DispatchSettings::offerTimeout();
    }

    public function onDeclined(DispatchJob $job, User $provider, ?string $reason): void {}

    public function afterAccepted(DispatchJob $job, User $provider, bool $manual): void {}

    protected function firstName(?string $name): string
    {
        $first = trim(explode(' ', trim((string) $name))[0] ?? '');

        return $first !== '' ? $first : 'Patient';
    }

    /** @param  array<string, mixed>  $data */
    protected function notifyUser(?User $user, string $type, string $title, string $body, array $data = []): void
    {
        if (! $user) {
            return;
        }
        try {
            app(NotificationDispatcherInterface::class)->push($user, $title, $body, ['type' => $type, 'data' => $data]);
        } catch (\Throwable $e) {
            Log::warning('[Dispatch] notify failed: '.$e->getMessage());
        }
    }

    protected function alertAdmins(DispatchJob $job, string $title): void
    {
        app(DispatchService::class)->alertAdmins($job, $title);
    }

    protected function distanceKm(?float $aLat, ?float $aLng, ?float $bLat, ?float $bLng): ?float
    {
        if ($aLat === null || $aLng === null || $bLat === null || $bLng === null) {
            return null;
        }

        return round(\App\Services\Geo\RoutingService::haversineKm($aLat, $aLng, $bLat, $bLng), 2);
    }

    /** Effective radius: patient's (clamped) and the provider's own max, whichever is smaller. */
    protected function radiusFor(DispatchJob $job, ?float $providerMax): float
    {
        $r = DispatchSettings::clampRadius($job->radius_km);

        return $providerMax !== null ? min($r, $providerMax) : $r;
    }
}
