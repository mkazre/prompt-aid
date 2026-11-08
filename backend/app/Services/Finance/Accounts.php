<?php

namespace App\Services\Finance;

use App\Models\Clinic;
use App\Models\DoctorProfile;
use App\Models\DriverProfile;
use App\Models\Pharmacy;
use App\Models\ThirdPartyProfile;
use App\Models\User;

/**
 * Ledger account identities: (account_type, account_id).
 *   platform/0, doctor/doctor_profiles.id, clinic/clinics.id,
 *   driver/driver_profiles.id, pharmacy/pharmacies.id,
 *   partner/third_party_profiles.id
 * and which of them a logged-in user may see (provider pages, API).
 */
class Accounts
{
    public const TYPES = [
        'platform' => 'Platform',
        'doctor' => 'Doctor',
        'clinic' => 'Clinic',
        'driver' => 'Driver',
        'pharmacy' => 'Pharmacy',
        'partner' => 'Partner',
    ];

    public const PROVIDER_TYPES = ['doctor', 'clinic', 'driver', 'pharmacy', 'partner'];

    /** @var array<string, string> */
    protected static array $labels = [];

    public static function label(string $type, int $id): string
    {
        if ($type === 'platform') {
            return 'Prompt Aid (platform)';
        }
        $key = $type.':'.$id;
        if (isset(static::$labels[$key])) {
            return static::$labels[$key];
        }
        try {
            $label = match ($type) {
                'doctor' => ($n = DoctorProfile::query()->with('user:id,name')->find($id)?->user?->name) ? 'Dr '.preg_replace('/^Dr\.?\s+/i', '', $n) : null,
                'clinic' => Clinic::query()->whereKey($id)->value('name'),
                'driver' => DriverProfile::query()->with('user:id,name')->find($id)?->user?->name,
                'pharmacy' => Pharmacy::query()->whereKey($id)->value('name'),
                'partner' => ThirdPartyProfile::query()->whereKey($id)->value('company_name'),
                default => null,
            };
        } catch (\Throwable) {
            $label = null;
        }

        return static::$labels[$key] = ($label ?: (static::TYPES[$type] ?? ucfirst($type)).' #'.$id);
    }

    /** Options for a Select of one account type: id => label. */
    public static function options(string $type, ?string $search = null, int $limit = 50): array
    {
        $q = match ($type) {
            'doctor' => DoctorProfile::query()->join('users', 'users.id', '=', 'doctor_profiles.user_id')->selectRaw('doctor_profiles.id as id, users.name as label'),
            'clinic' => Clinic::query()->selectRaw('id, name as label'),
            'driver' => DriverProfile::query()->join('users', 'users.id', '=', 'driver_profiles.user_id')->selectRaw('driver_profiles.id as id, users.name as label'),
            'pharmacy' => Pharmacy::query()->selectRaw('id, name as label'),
            'partner' => ThirdPartyProfile::query()->selectRaw('id, company_name as label'),
            default => null,
        };
        if (! $q) {
            return [];
        }
        if ($search !== null && trim($search) !== '') {
            $col = match ($type) {
                'doctor', 'driver' => 'users.name',
                'partner' => 'company_name',
                default => 'name',
            };
            $q->where($col, 'like', '%'.trim($search).'%');
        }

        return $q->orderBy('label')->limit($limit)->pluck('label', 'id')->map(fn ($l, $id) => ($l ?: '#'.$id))->all();
    }

    /**
     * The ledger accounts this user owns (strictly their own).
     *
     * @return array<int, array{type: string, id: int, label: string}>
     */
    public static function forUser(?User $user): array
    {
        if (! $user || $user->status !== 'active') {
            return [];
        }
        $out = [];
        $add = function (string $type, $id) use (&$out) {
            if ($id) {
                $out[] = ['type' => $type, 'id' => (int) $id, 'label' => static::label($type, (int) $id)];
            }
        };
        switch ($user->role) {
            case User::ROLE_DOCTOR:
                $add('doctor', $user->doctorProfile?->id);
                break;
            case User::ROLE_CLINIC_ADMIN:
                foreach ($user->clinicsAdministered()->pluck('id') as $id) {
                    $add('clinic', $id);
                }
                break;
            case User::ROLE_PHARMACY_ADMIN:
                foreach ($user->pharmacies()->pluck('id') as $id) {
                    $add('pharmacy', $id);
                }
                break;
            case User::ROLE_THIRD_PARTY:
                $add('partner', $user->thirdPartyProfile?->id);
                break;
            case User::ROLE_DRIVER:
                $add('driver', $user->driverProfile?->id);
                break;
        }

        return $out;
    }

    /** Pick one of the user's accounts (by "type:id" key or the first). */
    public static function pick(?User $user, ?string $key = null): ?array
    {
        $all = static::forUser($user);
        if ($key) {
            foreach ($all as $a) {
                if ($a['type'].':'.$a['id'] === $key) {
                    return $a;
                }
            }

            return null;
        }

        return $all[0] ?? null;
    }

    public static function owns(?User $user, string $type, int $id): bool
    {
        foreach (static::forUser($user) as $a) {
            if ($a['type'] === $type && $a['id'] === $id) {
                return true;
            }
        }

        return false;
    }
}
