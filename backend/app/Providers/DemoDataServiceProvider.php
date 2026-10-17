<?php

namespace App\Providers;

use App\Models\Clinic;
use App\Models\DoctorProfile;
use App\Models\DriverProfile;
use App\Models\Pharmacy;
use App\Models\Setting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Interim "Hide demo providers from public listings" switch (System → Demo data).
 *
 * Opt-in: does nothing unless the `demo_hide_providers` setting is "1". When
 * on, demo doctors / drivers (user.is_demo) and demo clinics / pharmacies
 * (is_demo) are filtered out of Eloquent queries on the public website and
 * the app API — only for guests and real (non-demo) patients. Never applied in
 * the staff/vendor/partner panels, on the console/queue, or for staff,
 * providers or demo accounts (so a demo login still sees its own data).
 */
class DemoDataServiceProvider extends ServiceProvider
{
    public const SETTING = 'demo_hide_providers';

    protected static ?bool $active = null;

    protected static bool $checking = false;

    public function boot(): void
    {
        $byUser = function (Builder $query) {
            if (static::hiding()) {
                $query->whereNotIn($query->qualifyColumn('user_id'), fn ($s) => $s->select('id')->from('users')->where('is_demo', true));
            }
        };
        $byFlag = function (Builder $query) {
            if (static::hiding()) {
                $col = $query->qualifyColumn('is_demo');
                $query->where(fn ($w) => $w->whereNull($col)->orWhere($col, false));
            }
        };

        DoctorProfile::addGlobalScope('hide_demo_providers', $byUser);
        DriverProfile::addGlobalScope('hide_demo_providers', $byUser);
        Clinic::addGlobalScope('hide_demo_providers', $byFlag);
        Pharmacy::addGlobalScope('hide_demo_providers', $byFlag);
    }

    /** Whether the hide filter applies to the current request. */
    public static function hiding(): bool
    {
        if (static::$active === false || static::$checking || app()->runningInConsole()) {
            return false;
        }

        static::$checking = true;
        try {
            if (static::$active === null) {
                static::$active = (string) Setting::get(self::SETTING, '0') === '1' && Schema::hasColumn('users', 'is_demo');
            }
            if (! static::$active) {
                return false;
            }
            if (class_exists(Filament::class) && Filament::isServing()) {
                return false;
            }
            $request = request();
            if ($request && $request->is('staff', 'staff/*', 'vendor', 'vendor/*', 'partner', 'partner/*', 'livewire/*')) {
                return false;
            }
            $user = Auth::user();
            if ($user && ($user->role !== User::ROLE_PATIENT || $user->is_demo)) {
                return false;
            }
        } catch (Throwable $e) {
            return false;
        } finally {
            static::$checking = false;
        }

        return true;
    }
}
