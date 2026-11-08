<?php

namespace App\Services\Finance;

use App\Models\CommissionRule;
use App\Support\Money;
use Carbon\CarbonInterface;

/**
 * Finds the commission / patient fee in force for a service at a moment.
 *
 * Specificity (most specific wins, field by field):
 *   consult / appointment : doctor → clinic → global
 *   shuttle               : driver → global
 *   pharmacy              : pharmacy → global
 *   lab                   : partner → global
 * For each scope the newest active rule with effective_from <= $at (null =
 * always) is used. A null percent / fixed_amount / patient_fee on an override
 * inherits the value from the next less specific level, so an override can
 * set only the percent and keep the global patient fee, for example.
 *
 * Commission = round(base × percent / 100) + fixed, clamped to [0, base],
 * where base = gross − patient fee (all in integer cents).
 */
class CommissionResolver
{
    /**
     * @param  array<string, int|null>  $scopes  scope => id, most specific first (e.g. ['doctor' => 4, 'clinic' => 2])
     * @return array{percent: string, fixed_cents: int, fee_cents: int, rule_ids: array<int, int>, from: array<string, string>}
     */
    public function resolve(string $service, array $scopes = [], ?CarbonInterface $at = null): array
    {
        $at ??= now();
        $wanted = [];
        foreach ($scopes as $scope => $id) {
            if ($id) {
                $wanted[] = [$scope, (int) $id];
            }
        }

        $rules = CommissionRule::query()
            ->where('service_type', $service)
            ->where('active', true)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $at))
            ->where(function ($q) use ($wanted) {
                $q->where('scope', 'global');
                foreach ($wanted as [$scope, $id]) {
                    $q->orWhere(fn ($w) => $w->where('scope', $scope)->where('scope_id', $id));
                }
            })
            ->get();

        // Newest rule per scope level.
        $pick = function (string $scope, ?int $id) use ($rules): ?CommissionRule {
            return $rules->filter(fn (CommissionRule $r) => $r->scope === $scope && ($scope === 'global' || (int) $r->scope_id === $id))
                ->sortByDesc(fn (CommissionRule $r) => sprintf('%020d-%012d', $r->effective_from?->getTimestamp() ?? 0, $r->id))
                ->first();
        };

        $chain = [];
        foreach ($wanted as [$scope, $id]) {
            if ($r = $pick($scope, $id)) {
                $chain[] = $r;
            }
        }
        if ($g = $pick('global', null)) {
            $chain[] = $g;
        }

        $out = ['percent' => '0', 'fixed_cents' => 0, 'fee_cents' => 0, 'rule_ids' => [], 'from' => []];
        foreach (['percent', 'fixed_amount', 'patient_fee'] as $field) {
            foreach ($chain as $r) {
                $v = $r->getRawOriginal($field);
                if ($v !== null && $v !== '') {
                    match ($field) {
                        'percent' => $out['percent'] = (string) $v,
                        'fixed_amount' => $out['fixed_cents'] = Money::cents((string) $v),
                        'patient_fee' => $out['fee_cents'] = Money::cents((string) $v),
                    };
                    $out['from'][$field] = $r->scope.($r->scope_id ? ':'.$r->scope_id : '');
                    $out['rule_ids'][] = (int) $r->id;
                    break;
                }
            }
        }
        $out['rule_ids'] = array_values(array_unique($out['rule_ids']));

        return $out;
    }

    /**
     * Split a captured gross amount (cents).
     *
     * @param  array{percent: string, fixed_cents: int, fee_cents: int}  $rule
     * @param  int|null  $commissionCents  pre-agreed commission (e.g. orders.commission_amount) instead of the rule's
     * @return array{gross: int, fee: int, base: int, commission: int, net: int}
     */
    public function split(int $grossCents, array $rule, ?int $commissionCents = null): array
    {
        $gross = max(0, $grossCents);
        $fee = min(max(0, (int) $rule['fee_cents']), $gross);
        $base = $gross - $fee;
        $commission = $commissionCents ?? (Money::percentOf($base, $rule['percent']) + (int) $rule['fixed_cents']);
        $commission = max(0, min($commission, $base));

        return ['gross' => $gross, 'fee' => $fee, 'base' => $base, 'commission' => $commission, 'net' => $base - $commission];
    }

    /** Scope chain for a service, most specific first. */
    public static function scopesFor(string $service, array $ids): array
    {
        $order = match ($service) {
            'consult', 'appointment' => ['doctor', 'clinic'],
            'shuttle' => ['driver'],
            'pharmacy' => ['pharmacy'],
            'lab' => ['partner'],
            default => [],
        };
        $out = [];
        foreach ($order as $scope) {
            $out[$scope] = $ids[$scope] ?? null;
        }

        return $out;
    }

    /** Commission (cents) on an amount for one provider — used when pricing (e.g. pharmacy order). */
    public function commissionOn(string $service, array $ids, int $amountCents, ?CarbonInterface $at = null): int
    {
        $rule = $this->resolve($service, static::scopesFor($service, $ids), $at);

        return max(0, min(Money::percentOf($amountCents, $rule['percent']) + $rule['fixed_cents'], $amountCents));
    }
}
