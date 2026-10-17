<?php

namespace App\Services\Dispatch\Handlers;

use App\Models\DispatchJob;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * One per generic service type. The engine (DispatchService) owns offers,
 * timing and the atomic first-accept; the handler owns the domain:
 * who qualifies, what accepting / expiring does to the subject, and how
 * the request looks on the provider's alert card.
 */
interface DispatchHandler
{
    public function service(): string;

    /**
     * Qualifying providers right now, best first.
     *
     * @return Collection<int, array{user: User, distance_km: float|null}>
     */
    public function candidates(DispatchJob $job): Collection;

    /** Seconds a single offer stays open. */
    public function offerTtl(DispatchJob $job): int;

    /** Is the subject still waiting for a provider (not handled elsewhere)? */
    public function stillOpen(DispatchJob $job): bool;

    /** Apply the acceptance to the subject. Runs inside the engine's transaction. Throw DispatchException to abort. */
    public function onAccepted(DispatchJob $job, User $provider, bool $manual): void;

    /** After commit: notify the patient etc. */
    public function afterAccepted(DispatchJob $job, User $provider, bool $manual): void;

    /** A provider declined (for single-target services this may close the request). */
    public function onDeclined(DispatchJob $job, User $provider, ?string $reason): void;

    /** Nobody accepted in time. */
    public function onExpired(DispatchJob $job): void;

    /**
     * Alert-card fields for the provider modal / admin console.
     *
     * @return array{icon: string, title: string, subtitle: string, patient_first_name: string|null, lines: array<int, string>, earning: float|null, when: string|null, url: string|null}
     */
    public function card(DispatchJob $job): array;

    /** Can this provider be assigned manually by an admin? */
    public function canServe(DispatchJob $job, User $provider): bool;
}
