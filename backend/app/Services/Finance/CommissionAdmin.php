<?php

namespace App\Services\Finance;

use App\Models\AuditLog;
use App\Models\CommissionRule;
use App\Models\User;
use App\Support\Money;

/**
 * Admin-side editing of commission rules.
 *  - Global defaults are versioned: a change inserts a new global rule with
 *    effective_from = now, so payments captured earlier keep their rate.
 *  - Provider overrides (from the provider's edit page) are one row per
 *    service + provider, edited in place.
 */
class CommissionAdmin
{
    /** @return array<string, array{percent: ?string, fixed_amount: ?string, patient_fee: ?string}> */
    public function globals(): array
    {
        $out = [];
        foreach (array_keys(CommissionRule::SERVICES) as $svc) {
            $r = app(CommissionResolver::class)->resolve($svc);
            $out[$svc] = [
                'percent' => rtrim(rtrim(number_format((float) $r['percent'], 3, '.', ''), '0'), '.'),
                'fixed_amount' => Money::toDecimal($r['fixed_cents']),
                'patient_fee' => Money::toDecimal($r['fee_cents']),
            ];
        }

        return $out;
    }

    /** @param array<string, array{percent?: mixed, fixed_amount?: mixed, patient_fee?: mixed}> $data  @return int versions written */
    public function saveGlobals(array $data, ?User $by): int
    {
        $current = $this->globals();
        $n = 0;
        foreach (array_keys(CommissionRule::SERVICES) as $svc) {
            if (! isset($data[$svc])) {
                continue;
            }
            $new = [
                'percent' => (string) max(0, min(100, (float) ($data[$svc]['percent'] ?? 0))),
                'fixed_amount' => Money::toDecimal(max(0, Money::cents((string) ($data[$svc]['fixed_amount'] ?? '0')))),
                'patient_fee' => Money::toDecimal(max(0, Money::cents((string) ($data[$svc]['patient_fee'] ?? '0')))),
            ];
            $old = $current[$svc];
            if ((float) $new['percent'] === (float) $old['percent'] && $new['fixed_amount'] === $old['fixed_amount'] && $new['patient_fee'] === $old['patient_fee']) {
                continue;
            }
            CommissionRule::query()->create($new + [
                'service_type' => $svc, 'scope' => 'global', 'scope_id' => null, 'active' => true,
                'effective_from' => now(), 'note' => 'Changed by '.($by?->name ?? 'system'), 'created_by' => $by?->id,
            ]);
            $this->audit($by, 'finance.commission.global', ['service' => $svc, 'old' => $old, 'new' => $new]);
            $n++;
        }

        return $n;
    }

    /** Current override values for one provider: service => fields (null = inherit). */
    public function overridesFor(string $scope, int $id): array
    {
        $out = [];
        foreach (CommissionRule::SCOPES_FOR as $svc => $scopes) {
            if (! in_array($scope, $scopes, true)) {
                continue;
            }
            $r = CommissionRule::query()->where(['service_type' => $svc, 'scope' => $scope, 'scope_id' => $id])->latest('id')->first();
            $out[$svc] = [
                'percent' => $r?->getRawOriginal('percent') !== null ? (string) (float) $r->getRawOriginal('percent') : null,
                'fixed_amount' => $r?->getRawOriginal('fixed_amount'),
                'patient_fee' => $r?->getRawOriginal('patient_fee'),
                'active' => $r ? (bool) $r->active : false,
                'note' => $r?->note,
            ];
        }

        return $out;
    }

    /** Upsert overrides; a service with every field empty removes the override. */
    public function saveOverrides(string $scope, int $id, array $data, ?User $by): void
    {
        foreach (CommissionRule::SCOPES_FOR as $svc => $scopes) {
            if (! in_array($scope, $scopes, true) || ! isset($data[$svc])) {
                continue;
            }
            $d = $data[$svc];
            $vals = [
                'percent' => ($d['percent'] ?? '') === '' || $d['percent'] === null ? null : (string) max(0, min(100, (float) $d['percent'])),
                'fixed_amount' => ($d['fixed_amount'] ?? '') === '' || $d['fixed_amount'] === null ? null : Money::toDecimal(max(0, Money::cents((string) $d['fixed_amount']))),
                'patient_fee' => ($d['patient_fee'] ?? '') === '' || $d['patient_fee'] === null ? null : Money::toDecimal(max(0, Money::cents((string) $d['patient_fee']))),
            ];
            $existing = CommissionRule::query()->where(['service_type' => $svc, 'scope' => $scope, 'scope_id' => $id])->latest('id')->first();
            if ($vals['percent'] === null && $vals['fixed_amount'] === null && $vals['patient_fee'] === null) {
                if ($existing) {
                    CommissionRule::query()->where(['service_type' => $svc, 'scope' => $scope, 'scope_id' => $id])->update(['active' => false]);
                    $this->audit($by, 'finance.commission.override_removed', ['service' => $svc, 'scope' => $scope, 'id' => $id]);
                }

                continue;
            }
            $payload = $vals + ['active' => array_key_exists('active', $d) ? (bool) $d['active'] : true, 'note' => isset($d['note']) ? mb_substr((string) $d['note'], 0, 255) : $existing?->note];
            if ($existing) {
                $existing->update($payload);
            } else {
                CommissionRule::query()->create($payload + ['service_type' => $svc, 'scope' => $scope, 'scope_id' => $id, 'created_by' => $by?->id]);
            }
            $this->audit($by, 'finance.commission.override', ['service' => $svc, 'scope' => $scope, 'id' => $id] + $payload);
        }
    }

    protected function audit(?User $by, string $event, array $values): void
    {
        try {
            AuditLog::query()->create(['user_id' => $by?->id, 'event' => $event, 'auditable_type' => CommissionRule::class, 'auditable_id' => (int) ($values['id'] ?? 0),
                'old_values' => null, 'new_values' => $values, 'ip' => request()?->ip()]);
        } catch (\Throwable) {
        }
    }
}
