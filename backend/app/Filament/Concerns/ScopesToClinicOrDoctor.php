<?php

namespace App\Filament\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Applied to resources whose underlying table has (or relates through)
 * `clinic_id` and/or `doctor_profile_id`. Super admins see everything;
 * a clinic admin only sees rows for the clinic(s) they administer; a
 * doctor only sees rows tied to their own doctor profile.
 */
trait ScopesToClinicOrDoctor
{
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

            return $query->when(
                in_array('clinic_id', static::getModel()::make()->getFillable(), true),
                fn (Builder $q) => $q->whereIn('clinic_id', $clinicIds),
            );
        }

        if ($user->isDoctor() && $user->doctorProfile) {
            $doctorId = $user->doctorProfile->id;

            return $query->when(
                in_array('doctor_profile_id', static::getModel()::make()->getFillable(), true),
                fn (Builder $q) => $q->where('doctor_profile_id', $doctorId),
            );
        }

        return $query;
    }
}
