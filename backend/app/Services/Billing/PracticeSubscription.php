<?php

namespace App\Services\Billing;

use App\Models\Clinic;
use App\Models\DoctorProfile;
use App\Models\PracticeSubscription as Sub;
use App\Support\PracticeBillingSettings as S;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The practice-subscription GATE. Other code calls this (guarded with
 * class_exists) to ask "may this practice / doctor receive work right now?".
 *
 *   PracticeSubscription::canReceiveRequests($clinicOrDoctor)   instant / dispatched requests
 *   PracticeSubscription::allows($clinicOrDoctor, 'listing')    shown in search / directory
 *   PracticeSubscription::allows($clinicOrDoctor, 'bookings')   new online bookings
 *   PracticeSubscription::status($clinicOrDoctor)               'active'|'trialing'|...|'none'
 *
 * Rules:
 *  - Enforcement OFF (default) or the feature not gated → always true.
 *  - A clinic is judged on its own subscription row.
 *  - A doctor is allowed when their own (independent) subscription allows it
 *    OR any clinic they work at allows it.
 *  - No row at all → setting "practice_billing_unsubscribed" (allow|block).
 *  - Covered states: trialing (until trial end), active, past_due, grace,
 *    cancelled-but-period-not-ended, or an admin waiver (comped_until).
 *    Blocked: suspended, incomplete, cancelled after the period ended.
 *  - Plan feature flags (receives instant requests / listed in search /
 *    accepts bookings) are honoured while enforcement is on.
 *  - Fails OPEN: any error (e.g. tables missing mid-deploy) → true.
 */
class PracticeSubscription
{
    /** @var array<string, array{0: float, 1: ?Sub}> */
    protected static array $memo = [];

    protected const MEMO_SECONDS = 20;

    public static function canReceiveRequests(Clinic|DoctorProfile $practice): bool
    {
        return static::allows($practice, 'instant');
    }

    public static function allows(Clinic|DoctorProfile $practice, string $feature = 'instant'): bool
    {
        try {
            if (! S::enforced() || ! S::featureGated($feature) || ! static::ready()) {
                return true;
            }
            if ($practice instanceof Clinic) {
                return static::rowAllows(static::row(Sub::TYPE_CLINIC, (int) $practice->id), $feature);
            }
            $own = static::row(Sub::TYPE_DOCTOR, (int) $practice->id);
            if ($own && static::rowAllows($own, $feature)) {
                return true;
            }
            $clinicIds = static::clinicIdsFor($practice);
            foreach ($clinicIds as $cid) {
                if (static::rowAllows(static::row(Sub::TYPE_CLINIC, $cid), $feature)) {
                    return true;
                }
            }
            if ($own || $clinicIds !== []) {
                return false;
            }

            return static::rowAllows(null, $feature); // independent doctor, never subscribed
        } catch (\Throwable $e) {
            Log::warning('[PracticeBilling] gate failed open: '.$e->getMessage());

            return true;
        }
    }

    /**
     * Status of the subscription that governs this practice:
     * 'trialing'|'active'|'past_due'|'grace'|'suspended'|'cancelled'|'incomplete'|'comped'|'none'.
     * For a doctor in a clinic, the best status among their clinics (or their own row).
     */
    public static function status(Clinic|DoctorProfile $practice): string
    {
        try {
            if (! static::ready()) {
                return 'none';
            }
            $rows = $practice instanceof Clinic
                ? [static::row(Sub::TYPE_CLINIC, (int) $practice->id)]
                : array_merge([static::row(Sub::TYPE_DOCTOR, (int) $practice->id)], array_map(fn ($id) => static::row(Sub::TYPE_CLINIC, $id), static::clinicIdsFor($practice)));
            $rank = ['comped' => 0, 'active' => 1, 'trialing' => 2, 'past_due' => 3, 'grace' => 4, 'cancelled' => 5, 'incomplete' => 6, 'suspended' => 7, 'none' => 8];
            $best = 'none';
            foreach ($rows as $row) {
                $s = $row ? static::effectiveStatus($row) : 'none';
                if (($rank[$s] ?? 9) < ($rank[$best] ?? 9)) {
                    $best = $s;
                }
            }

            return $best;
        } catch (\Throwable) {
            return 'none';
        }
    }

    /** Status of one row as the gate sees it (expired trial / ended cancellation are not "covered"). */
    public static function effectiveStatus(Sub $row): string
    {
        if ($row->isComped()) {
            return 'comped';
        }

        return (string) $row->status;
    }

    /** Is this row covering its practice right now (ignoring plan feature flags)? */
    public static function covered(?Sub $row): bool
    {
        if (! $row) {
            return S::str('practice_billing_unsubscribed') !== 'block';
        }
        if ($row->isComped()) {
            return true;
        }

        return match ($row->status) {
            Sub::STATUS_ACTIVE, Sub::STATUS_PAST_DUE, Sub::STATUS_GRACE => true,
            // An expired trial / ended period stays covered until the daily
            // billing check moves it to past_due → grace → suspended.
            Sub::STATUS_TRIALING => true,
            Sub::STATUS_CANCELLED => $row->current_period_end !== null && $row->current_period_end->isFuture(),
            default => false, // suspended, incomplete
        };
    }

    protected static function rowAllows(?Sub $row, string $feature): bool
    {
        if (! static::covered($row)) {
            return false;
        }
        $plan = $row?->plan;
        if ($plan) {
            return match ($feature) {
                'instant' => (bool) $plan->receives_instant_requests,
                'listing' => (bool) $plan->listed_in_search,
                'bookings' => (bool) $plan->accepts_bookings,
                default => true,
            };
        }

        return true;
    }

    /**
     * Narrow a DoctorProfile or Clinic listing query to practices allowed to be
     * listed. No-op unless enforcement + the "listing" gate are on.
     */
    public static function scopeListed(Builder $query): Builder
    {
        try {
            if (! S::enforced() || ! S::featureGated('listing') || ! static::ready()) {
                return $query;
            }
            $model = $query->getModel();
            $table = $model->getTable();
            if ($model instanceof Clinic) {
                $blocked = static::blockedClinicIds('listing');

                return $blocked === [] ? $query : $query->whereNotIn($table.'.id', $blocked);
            }
            if ($model instanceof DoctorProfile) {
                $blocked = static::blockedDoctorIds('listing');

                return $blocked === [] ? $query : $query->whereNotIn($table.'.id', $blocked);
            }
        } catch (\Throwable $e) {
            Log::warning('[PracticeBilling] listing scope failed open: '.$e->getMessage());
        }

        return $query;
    }

    /** @return array<int, int> */
    public static function blockedClinicIds(string $feature): array
    {
        return cache()->remember('practice_billing:blocked_clinics:'.$feature, 60, function () use ($feature) {
            $out = [];
            Clinic::query()->select('id')->orderBy('id')->chunk(500, function ($clinics) use (&$out, $feature) {
                foreach ($clinics as $c) {
                    if (! static::allows($c, $feature)) {
                        $out[] = (int) $c->id;
                    }
                }
            });

            return $out;
        });
    }

    /** @return array<int, int> */
    public static function blockedDoctorIds(string $feature): array
    {
        return cache()->remember('practice_billing:blocked_doctors:'.$feature, 60, function () use ($feature) {
            $out = [];
            DoctorProfile::query()->select('id')->orderBy('id')->chunk(500, function ($doctors) use (&$out, $feature) {
                foreach ($doctors as $d) {
                    if (! static::allows($d, $feature)) {
                        $out[] = (int) $d->id;
                    }
                }
            });

            return $out;
        });
    }

    /** Clear the per-process memo + listing caches (after any subscription change). */
    public static function flush(): void
    {
        static::$memo = [];
        foreach (array_keys(S::FEATURES) as $f) {
            cache()->forget('practice_billing:blocked_clinics:'.$f);
            cache()->forget('practice_billing:blocked_doctors:'.$f);
        }
    }

    protected static function row(string $type, int $id): ?Sub
    {
        $key = $type.':'.$id;
        $hit = static::$memo[$key] ?? null;
        if ($hit && (microtime(true) - $hit[0]) < static::MEMO_SECONDS) {
            return $hit[1];
        }
        $row = Sub::query()->with('plan')->where('subscriber_type', $type)->where('subscriber_id', $id)->first();
        static::$memo[$key] = [microtime(true), $row];

        return $row;
    }

    /** @return array<int, int> */
    protected static function clinicIdsFor(DoctorProfile $doctor): array
    {
        if ($doctor->relationLoaded('clinics')) {
            return $doctor->clinics->pluck('id')->map(fn ($v) => (int) $v)->all();
        }

        return DB::table('clinic_doctor')->where('doctor_profile_id', $doctor->id)->pluck('clinic_id')->map(fn ($v) => (int) $v)->all();
    }

    protected static function ready(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                $ok = Schema::hasTable('practice_subscriptions') && Schema::hasTable('practice_plans');
            } catch (\Throwable) {
                $ok = false;
            }
        }

        return $ok;
    }
}
