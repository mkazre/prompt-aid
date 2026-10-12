<?php

namespace App\Services\Commerce;

use App\Models\DocumentExpiryNotice;
use App\Models\DriverDocument;
use App\Models\ProviderDocument;
use App\Models\User;
use App\Services\Notifications\InAppNotifier;
use App\Support\CommerceSettings;
use App\Support\StaffNotifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Expiry reminders for driver compliance documents and provider
 * (applicant) documents: the owner gets an in-app alert and super admins a
 * staff alert at 30, 7 and 0 days before expiry — once each, tracked in
 * document_expiry_notices (keyed by the expiry date, so a renewed document
 * starts a fresh cycle).
 */
class DocumentExpiryService
{
    public const THRESHOLDS = [30, 7, 0];

    /** Already-expired documents older than this are not chased (avoids a flood on first run). */
    public const EXPIRED_GRACE_DAYS = 30;

    public static function today(): Carbon
    {
        return now(CommerceSettings::timezone())->startOfDay();
    }

    /** Days until expiry (negative = expired). */
    public static function daysUntil(?Carbon $expires): ?int
    {
        if (! $expires) {
            return null;
        }

        return (int) self::today()->diffInDays(Carbon::parse($expires->toDateString(), CommerceSettings::timezone())->startOfDay(), false);
    }

    /** 'expired' | 'expiring' (<= 30 days) | 'valid' | null */
    public static function status(?Carbon $expires): ?string
    {
        $days = self::daysUntil($expires);
        if ($days === null) {
            return null;
        }

        return $days < 0 ? 'expired' : ($days <= 30 ? 'expiring' : 'valid');
    }

    public static function badgeColor(?Carbon $expires): string
    {
        return match (self::status($expires)) {
            'expired' => 'danger',
            'expiring' => 'warning',
            'valid' => 'success',
            default => 'gray',
        };
    }

    public static function label(?Carbon $expires): string
    {
        $days = self::daysUntil($expires);
        if ($days === null) {
            return 'No expiry';
        }
        if ($days < 0) {
            return 'Expired '.$expires->format('d M Y');
        }
        if ($days === 0) {
            return 'Expires today';
        }

        return $expires->format('d M Y').($days <= 30 ? " ({$days}d)" : '');
    }

    /**
     * Everything expired or expiring within $days, for the admin page.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function upcoming(int $days = 60, bool $includeExpired = true): Collection
    {
        $until = self::today()->addDays($days)->toDateString();

        $driver = DriverDocument::query()
            ->with('driver.user')
            ->whereNotNull('expires_at')
            ->where('status', '!=', 'rejected')
            ->whereDate('expires_at', '<=', $until)
            ->when(! $includeExpired, fn ($q) => $q->whereDate('expires_at', '>=', self::today()->toDateString()))
            ->get()
            ->map(fn (DriverDocument $d) => [
                'kind' => 'Driver',
                'document' => $d->typeLabel(),
                'owner' => $d->driver?->user?->name ?? 'Driver #'.$d->driver_profile_id,
                'owner_contact' => $d->driver?->user?->email,
                'expires_at' => $d->expires_at,
                'days' => self::daysUntil($d->expires_at),
                'status' => self::status($d->expires_at),
                'url' => $d->driver_profile_id ? route('filament.admin.resources.driver-profiles.edit', $d->driver_profile_id) : null,
            ]);

        $provider = ProviderDocument::query()
            ->with('application')
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<=', $until)
            ->when(! $includeExpired, fn ($q) => $q->whereDate('expires_at', '>=', self::today()->toDateString()))
            ->get()
            ->map(fn (ProviderDocument $d) => [
                'kind' => 'Provider',
                'document' => $d->label,
                'owner' => $d->application?->business_name ?? $d->application?->contact_name ?? 'Application #'.$d->provider_application_id,
                'owner_contact' => $d->application?->email,
                'expires_at' => $d->expires_at,
                'days' => self::daysUntil($d->expires_at),
                'status' => self::status($d->expires_at),
                'url' => $d->provider_application_id ? route('filament.admin.resources.provider-applications.edit', $d->provider_application_id) : null,
            ]);

        return $driver->concat($provider)->sortBy('days')->values();
    }

    /** Daily job. @return int reminders sent */
    public function run(): int
    {
        if (! CommerceSettings::bool('doc_expiry_enabled')) {
            return 0;
        }

        $from = self::today()->subDays(self::EXPIRED_GRACE_DAYS)->toDateString();
        $until = self::today()->addDays(max(self::THRESHOLDS))->toDateString();
        $sent = 0;

        DriverDocument::query()->with('driver.user')
            ->whereNotNull('expires_at')->where('status', '!=', 'rejected')
            ->whereDate('expires_at', '>=', $from)->whereDate('expires_at', '<=', $until)
            ->each(function (DriverDocument $d) use (&$sent) {
                $sent += $this->remind('driver', $d->id, $d->expires_at, $d->typeLabel(), $d->driver?->user,
                    $d->driver?->user?->name ?? 'A driver',
                    $d->driver_profile_id ? route('filament.admin.resources.driver-profiles.edit', $d->driver_profile_id) : null,
                    ['route' => 'driver_documents']);
            });

        ProviderDocument::query()->with('application')
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '>=', $from)->whereDate('expires_at', '<=', $until)
            ->each(function (ProviderDocument $d) use (&$sent) {
                $owner = $d->application?->email ? User::query()->where('email', $d->application->email)->first() : null;
                $sent += $this->remind('provider', $d->id, $d->expires_at, $d->label, $owner,
                    $d->application?->business_name ?? $d->application?->contact_name ?? 'A provider',
                    $d->provider_application_id ? route('filament.admin.resources.provider-applications.edit', $d->provider_application_id) : null,
                    []);
            });

        return $sent;
    }

    /**
     * Send the most urgent due reminder for one document, marking every
     * threshold it has passed so earlier ones never fire late.
     */
    protected function remind(string $type, int $id, Carbon $expires, string $docLabel, ?User $owner, string $ownerName, ?string $adminUrl, array $appData): int
    {
        $days = self::daysUntil($expires);
        $due = array_values(array_filter(self::THRESHOLDS, fn ($t) => $days <= $t));
        if (! $due) {
            return 0;
        }
        $threshold = min($due);

        $already = DocumentExpiryNotice::query()
            ->where('document_type', $type)->where('document_id', $id)
            ->whereDate('expires_on', $expires->toDateString())
            ->where('threshold_days', $threshold)->exists();
        if ($already) {
            return 0;
        }

        // Claim every passed threshold first (unique index = idempotent under concurrency).
        $claimed = 0;
        foreach ($due as $t) {
            $claimed += DocumentExpiryNotice::query()->insertOrIgnore([
                'document_type' => $type,
                'document_id' => $id,
                'expires_on' => $expires->toDateString(),
                'threshold_days' => $t,
                'sent_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        if ($claimed === 0) {
            return 0;
        }

        $when = $days < 0 ? 'expired on '.$expires->format('d M Y') : ($days === 0 ? 'expires today' : "expires in {$days} days ({$expires->format('d M Y')})");
        $title = $days <= 0 ? "Document expired: {$docLabel}" : "Document expiring soon: {$docLabel}";

        try {
            if ($owner) {
                app(InAppNotifier::class)->send($owner, 'account', $title,
                    "Your {$docLabel} {$when}. Please upload a renewed copy to keep your account active.",
                    $appData + ['url' => $type === 'driver' ? '/driver' : '/']);
            }

            if (CommerceSettings::bool('doc_expiry_notify_admins')) {
                $admins = User::query()->where('role', User::ROLE_SUPER_ADMIN)->where('status', 'active')->get();
                if ($admins->isNotEmpty()) {
                    StaffNotifier::alert($admins, $title, "{$ownerName}: {$docLabel} {$when}.",
                        icon: 'heroicon-o-document-text',
                        color: $days <= 0 ? 'danger' : 'warning',
                        url: $adminUrl ?? route('filament.admin.pages.expiring-documents'),
                        actionLabel: 'Review');
                }
            }
        } catch (\Throwable $e) {
            Log::warning("[DocumentExpiry] reminder failed for {$type} #{$id}: ".$e->getMessage());
        }

        return 1;
    }
}
