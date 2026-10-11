<?php

namespace App\Services\Privacy;

use App\Models\Consent;
use App\Models\LegalDocument;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Records and evaluates POPIA consents.
 *
 * Deployment decision (documented): existing users get NO consent rows
 * back-filled and are NOT prompted on deploy — v1 documents are seeded with
 * requires_reconsent = false. Consent is first captured at the next
 * registration; existing users are prompted only once an admin publishes a
 * new version with "require re-consent" switched on.
 */
class ConsentService
{
    /**
     * Purposes that block use of the site until accepted (when the current
     * document version requires re-consent), by role.
     *
     * @return array<int, string>
     */
    public function requiredPurposesFor(User $user): array
    {
        if ($user->isSuperAdmin()) {
            return [];
        }

        return $user->isPatient()
            ? [Consent::PURPOSE_TERMS, Consent::PURPOSE_PRIVACY, Consent::PURPOSE_HEALTH_DATA]
            : [Consent::PURPOSE_TERMS, Consent::PURPOSE_PRIVACY];
    }

    /**
     * Grant a set of purposes for a user, stamping the current document
     * version for documented purposes. Skips purposes already actively
     * granted at the same version (idempotent).
     *
     * @param  array<int, string>  $purposes
     */
    public function grant(User $user, array $purposes, string $channel = 'web', ?Request $request = null): void
    {
        $request ??= request();
        $current = LegalDocument::currentByPurpose();

        foreach (array_unique($purposes) as $purpose) {
            if (! array_key_exists($purpose, Consent::PURPOSES)) {
                continue;
            }

            $doc = $current[$purpose] ?? null;
            $version = $doc['version'] ?? null;

            $alreadyActive = Consent::query()
                ->where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->active()
                ->when($version !== null, fn ($q) => $q->where('version', $version))
                ->exists();

            if ($alreadyActive) {
                continue;
            }

            Consent::query()->create([
                'user_id' => $user->id,
                'purpose' => $purpose,
                'version' => $version,
                'legal_document_id' => $doc['id'] ?? null,
                'granted_at' => now(),
                'ip' => $request?->ip(),
                'user_agent' => mb_substr((string) $request?->userAgent(), 0, 512) ?: null,
                'channel' => in_array($channel, ['web', 'app', 'admin'], true) ? $channel : 'web',
            ]);
        }

        $this->forget($user);
    }

    public function withdraw(User $user, string $purpose): int
    {
        $count = Consent::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('withdrawn_at')
            ->update(['withdrawn_at' => now(), 'updated_at' => now()]);

        $this->forget($user);

        return $count;
    }

    public function withdrawAll(User $user): void
    {
        Consent::query()->where('user_id', $user->id)->whereNull('withdrawn_at')
            ->update(['withdrawn_at' => now(), 'updated_at' => now()]);
        $this->forget($user);
    }

    public function has(User $user, string $purpose): bool
    {
        return Consent::query()->where('user_id', $user->id)->where('purpose', $purpose)->active()->exists();
    }

    /**
     * Status of every purpose for the account page / API.
     *
     * @return array<int, array<string, mixed>>
     */
    public function statusFor(User $user): array
    {
        $current = LegalDocument::currentByPurpose();
        $active = Consent::query()->where('user_id', $user->id)->active()
            ->orderByDesc('granted_at')->get()->groupBy('purpose');
        $required = $this->requiredPurposesFor($user);

        $out = [];
        foreach (Consent::PURPOSES as $purpose => $label) {
            $row = $active->get($purpose)?->first();
            $doc = $current[$purpose] ?? null;
            $out[] = [
                'purpose' => $purpose,
                'label' => $label,
                'granted' => (bool) $row,
                'granted_at' => $row?->granted_at?->toIso8601String(),
                'version' => $row?->version,
                'current_version' => $doc['version'] ?? null,
                'required' => in_array($purpose, $required, true),
                'optional' => in_array($purpose, Consent::OPTIONAL, true),
                'document_url' => ! empty($doc['page_slug']) ? url('/'.ltrim($doc['page_slug'], '/')) : null,
            ];
        }

        return $out;
    }

    /**
     * Documents the user must (re-)accept before continuing: the purpose is
     * required for their role, the current version is flagged
     * requires_reconsent, and they hold no active consent at that version.
     * Cached per user + document set for 10 minutes.
     *
     * @return array<int, array<string, mixed>>
     */
    public function outstandingFor(User $user): array
    {
        $required = $this->requiredPurposesFor($user);
        if ($required === []) {
            return [];
        }

        $current = LegalDocument::currentByPurpose();
        $docs = array_values(array_filter(
            array_map(fn ($p) => $current[$p] ?? null, $required),
            fn ($doc) => $doc !== null && $doc['requires_reconsent']
        ));

        if ($docs === []) {
            return [];
        }

        $hash = md5(json_encode(array_map(fn ($d) => $d['purpose'].':'.$d['version'], $docs)));

        return Cache::remember($this->cacheKey($user).".{$hash}", 600, function () use ($user, $docs) {
            $accepted = Consent::query()->where('user_id', $user->id)->active()
                ->get(['purpose', 'version'])
                ->map(fn ($c) => $c->purpose.':'.$c->version)
                ->all();

            return array_values(array_filter($docs, fn ($d) => ! in_array($d['purpose'].':'.$d['version'], $accepted, true)));
        });
    }

    public function forget(User $user): void
    {
        // Version-hash suffixed keys can't be enumerated on file/database
        // cache stores, so bump a per-user generation counter instead.
        Cache::forever('consent.gen.'.$user->id, (int) Cache::get('consent.gen.'.$user->id, 0) + 1);
    }

    protected function cacheKey(User $user): string
    {
        return 'consent.outstanding.'.$user->id.'.g'.(int) Cache::get('consent.gen.'.$user->id, 0);
    }

    /**
     * Maps registration form/API fields to purposes:
     *   accept_terms (terms + privacy), accept_health_data,
     *   consent_marketing, consent_sms_whatsapp, consent_location,
     *   consent_share_with_medical_scheme — and, for the API, an optional
     *   `consents` array of purpose keys.
     *
     * @return array<int, string>
     */
    public static function purposesFromRequest(Request $request): array
    {
        $purposes = [];

        if ($request->boolean('accept_terms')) {
            $purposes[] = Consent::PURPOSE_TERMS;
            $purposes[] = Consent::PURPOSE_PRIVACY;
        }
        if ($request->boolean('accept_health_data')) {
            $purposes[] = Consent::PURPOSE_HEALTH_DATA;
        }

        $optional = [
            'consent_marketing' => Consent::PURPOSE_MARKETING,
            'consent_sms_whatsapp' => Consent::PURPOSE_SMS_WHATSAPP,
            'consent_location' => Consent::PURPOSE_LOCATION,
            'consent_share_with_medical_scheme' => Consent::PURPOSE_SCHEME_SHARING,
        ];
        foreach ($optional as $field => $purpose) {
            if ($request->boolean($field)) {
                $purposes[] = $purpose;
            }
        }

        $listed = $request->input('consents');
        if (is_array($listed)) {
            foreach ($listed as $purpose) {
                if (is_string($purpose) && array_key_exists($purpose, Consent::PURPOSES)) {
                    $purposes[] = $purpose;
                }
            }
        }

        return array_values(array_unique($purposes));
    }

    /**
     * Registration capture shared by /join, /register and the API.
     * $accepted are purposes the user ticked.
     *
     * @param  array<int, string>  $accepted
     */
    public function recordRegistration(User $user, array $accepted, string $channel, ?Request $request = null): void
    {
        try {
            $this->grant($user, $accepted, $channel, $request);
        } catch (\Throwable $e) {
            // Never fail a signup because the consent ledger write failed —
            // report it so it can be followed up.
            report($e);
        }
    }
}
