<?php

namespace App\Filament\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Applied to resources whose underlying model has (or relates through) a
 * clinic and/or a doctor. Super admins see everything; a clinic admin only
 * sees rows for the clinic(s) they administer; a doctor only sees rows tied
 * to their own doctor profile.
 *
 * `$clinicScope`/`$doctorScope` are either a plain column name on the
 * model itself (e.g. `clinic_id`) or a `relation.column` path resolved via
 * `whereHas` (e.g. `appointment.doctor_profile_id`) for models that don't
 * carry the id directly. If a resource doesn't override the relevant scope
 * and the model has no matching column, we fail closed (empty result) for
 * clinic_admin/doctor rather than silently showing every row — a resource
 * that genuinely has no clinic/doctor concept shouldn't use this trait.
 */
trait ScopesToClinicOrDoctor
{
    protected static function clinicScope(): string
    {
        return 'clinic_id';
    }

    protected static function doctorScope(): string
    {
        return 'doctor_profile_id';
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        /** @var User|null $user */
        $user = Auth::user();

        if (! $user || $user->isSuperAdmin()) {
            return $query;
        }

        if ($user->isClinicAdmin()) {
            $clinicIds = $user->clinicsAdministered()->pluck('id');

            return static::applyScope($query, static::clinicScope(), $clinicIds->all(), true);
        }

        if ($user->isDoctor() && $user->doctorProfile) {
            return static::applyScope($query, static::doctorScope(), $user->doctorProfile->id, false);
        }

        return $query;
    }

    protected static function applyScope(Builder $query, string $path, mixed $value, bool $whereIn): Builder
    {
        if (str_contains($path, '.')) {
            $relation = substr($path, 0, strrpos($path, '.'));
            $column = substr($path, strrpos($path, '.') + 1);

            return $query->whereHas($relation, fn (Builder $q) => $whereIn
                ? $q->whereIn($column, $value)
                : $q->where($column, $value));
        }

        if (! in_array($path, static::getModel()::make()->getFillable(), true)) {
            // No column to scope on and no relation override given — fail
            // closed rather than leak every row to a non-super-admin.
            return $query->whereRaw('1 = 0');
        }

        return $whereIn ? $query->whereIn($path, $value) : $query->where($path, $value);
    }
}
