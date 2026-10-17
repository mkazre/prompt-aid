<?php

namespace App\Services\Dispatch;

use App\Models\DoctorProfile;
use App\Models\DriverProfile;
use App\Models\ProviderAvailability;
use App\Models\User;
use App\Services\Doctors\DoctorMatchService;
use App\Services\Rides\RideLocationService;
use App\Support\DispatchSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Provider availability NOW / LATER / TOMORROW for every provider role.
 *
 * NOW is heartbeat-backed: every provider surface (Filament panels' global
 * alert poller, web driver console, the apps' inbox poll) calls
 * heartbeat(). Drivers and doctors keep their existing profile
 * `availability` column as the source of truth for "online" (shuttle and
 * doctor-now dispatch already read it); this service mirrors it.
 */
class AvailabilityService
{
    public const PROVIDER_ROLES = [User::ROLE_DOCTOR, User::ROLE_DRIVER, User::ROLE_PHARMACY_ADMIN, User::ROLE_THIRD_PARTY];

    public static function isProvider(?User $user): bool
    {
        return $user !== null && in_array($user->role, self::PROVIDER_ROLES, true);
    }

    public function forUser(User $user): ProviderAvailability
    {
        return ProviderAvailability::query()->firstOrCreate(['user_id' => $user->id], ['role' => (string) $user->role]);
    }

    /**
     * Cheap: writes at most every 20 s per user. Keeps doctors "fresh" for
     * doctor-now matching from ANY staff page (not only Live consults).
     */
    public function heartbeat(User $user, ?float $lat = null, ?float $lng = null): void
    {
        if (! self::isProvider($user) || ! Cache::add('dispatch:hb:'.$user->id, 1, 20)) {
            return;
        }
        $row = $this->forUser($user);
        $attrs = ['last_seen_at' => now()];
        if ($lat !== null && $lng !== null && abs($lat) <= 90 && abs($lng) <= 180) {
            $attrs += ['lat' => $lat, 'lng' => $lng, 'location_at' => now()];
        }
        if ($user->role === User::ROLE_DOCTOR && ($doc = $user->doctorProfile)) {
            $attrs['now_on'] = $doc->availability === DoctorProfile::AVAILABLE;
            if ($doc->availability === DoctorProfile::AVAILABLE) {
                $doc->forceFill(['last_seen_at' => now()])->saveQuietly();
            }
        } elseif ($user->role === User::ROLE_DRIVER && ($drv = $user->driverProfile)) {
            $attrs['now_on'] = $drv->availability !== DriverProfile::OFFLINE;
        }
        $row->forceFill($attrs)->saveQuietly();
    }

    /** Toggle "available NOW" (online) / offline. Returns the payload. */
    public function setNow(User $user, bool $on): array
    {
        if ($user->role === User::ROLE_DRIVER && ($drv = $user->driverProfile)) {
            $active = app(RideLocationService::class)->activeRideFor($drv);
            if ($on) {
                if ($drv->status !== 'active') {
                    throw new DispatchException('Your driver account is awaiting approval.', 403);
                }
                if ($blocker = $drv->complianceBlocker()) {
                    throw new DispatchException("You can't go online until your documents are up to date: {$blocker}.", 403);
                }
                $drv->update(['availability' => $active ? DriverProfile::BUSY : DriverProfile::AVAILABLE]);
            } else {
                if ($active) {
                    throw new DispatchException('Finish or cancel your current trip before going offline.', 409);
                }
                $drv->update(['availability' => DriverProfile::OFFLINE]);
            }
        } elseif ($user->role === User::ROLE_DOCTOR && ($doc = $user->doctorProfile)) {
            try {
                app(DoctorMatchService::class)->setAvailability($doc, $on ? DoctorProfile::AVAILABLE : DoctorProfile::OFFLINE);
            } catch (\App\Services\Doctors\DoctorMatchException $e) {
                throw new DispatchException($e->getMessage(), 409);
            }
        }

        $row = $this->forUser($user);
        $row->forceFill(['now_on' => $on, 'last_seen_at' => now()])->save();
        Cache::forget('dispatch:hb:'.$user->id);

        if ($on) {
            app(DispatchService::class)->offerOpenJobsTo($user);
        }

        return $this->payload($user);
    }

    /**
     * @param  string  $kind  later|tomorrow
     */
    public function setWindow(User $user, string $kind, ?string $from, ?string $to): array
    {
        if (! in_array($kind, ['later', 'tomorrow'], true)) {
            throw new DispatchException('Unknown window.');
        }
        $row = $this->forUser($user);
        if (! $from || ! $to) {
            $row->forceFill([$kind.'_date' => null, $kind.'_from' => null, $kind.'_to' => null])->save();

            return $this->payload($user);
        }
        if (! preg_match('/^\d{2}:\d{2}$/', $from) || ! preg_match('/^\d{2}:\d{2}$/', $to) || $to <= $from) {
            throw new DispatchException('Choose a start and end time (end after start).');
        }
        $local = ProviderAvailability::localNow();
        $date = $kind === 'later' ? $local->toDateString() : $local->copy()->addDay()->toDateString();
        $row->forceFill([$kind.'_date' => $date, $kind.'_from' => $from.':00', $kind.'_to' => $to.':00'])->save();

        app(DispatchService::class)->offerOpenJobsTo($user);

        return $this->payload($user);
    }

    /** @param  array<int, array{weekday: int, start: string, end: string}>  $weekly */
    public function setWeekly(User $user, array $weekly): array
    {
        $clean = [];
        foreach ($weekly as $r) {
            $wd = (int) ($r['weekday'] ?? -1);
            $s = substr((string) ($r['start'] ?? ''), 0, 5);
            $e = substr((string) ($r['end'] ?? ''), 0, 5);
            if ($wd >= 0 && $wd <= 6 && preg_match('/^\d{2}:\d{2}$/', $s) && preg_match('/^\d{2}:\d{2}$/', $e) && $e > $s) {
                $clean[] = ['weekday' => $wd, 'start' => $s, 'end' => $e];
            }
        }
        $this->forUser($user)->forceFill(['weekly' => $clean ?: null])->save();

        return $this->payload($user);
    }

    public function setRadius(User $user, ?float $km): array
    {
        $km = $km !== null && $km > 0 ? round(min(500, $km), 1) : null;
        $this->forUser($user)->forceFill(['max_radius_km' => $km])->save();

        return $this->payload($user);
    }

    public function isOnlineNow(User $user): bool
    {
        if ($user->role === User::ROLE_DRIVER) {
            return $user->driverProfile?->availability === DriverProfile::AVAILABLE;
        }
        if ($user->role === User::ROLE_DOCTOR) {
            $doc = $user->doctorProfile;

            return $doc !== null && $doc->availability === DoctorProfile::AVAILABLE && app(DoctorMatchService::class)->isFresh($doc);
        }

        return (bool) ProviderAvailability::query()->where('user_id', $user->id)->first()?->isOnlineNow();
    }

    /** @return array<string, mixed> */
    public function payload(User $user): array
    {
        $row = $this->forUser($user)->fresh();
        $online = $user->role === User::ROLE_DRIVER
            ? ($user->driverProfile?->availability ?? 'offline')
            : ($user->role === User::ROLE_DOCTOR ? ($user->doctorProfile?->availability ?? 'offline') : ($row->now_on ? 'available' : 'offline'));
        $win = function (string $kind) use ($row) {
            $date = $row->{$kind.'_date'};
            if (! $date || ! $row->{$kind.'_from'} || $date->toDateString() < ProviderAvailability::localNow()->toDateString()) {
                return null;
            }

            return ['date' => $date->toDateString(), 'from' => substr((string) $row->{$kind.'_from'}, 0, 5), 'to' => substr((string) $row->{$kind.'_to'}, 0, 5)];
        };

        return [
            'role' => $user->role,
            'now' => in_array($online, ['available', 'busy'], true),
            'availability' => $online,
            'later' => $win('later'),
            'tomorrow' => $win('tomorrow'),
            'weekly' => $row->weekly ?: [],
            'max_radius_km' => $row->max_radius_km !== null ? (float) $row->max_radius_km : null,
            'default_radius_km' => DispatchSettings::defaultRadius(),
            'last_seen_at' => $row->last_seen_at?->toIso8601String(),
        ];
    }

    /**
     * Does this provider qualify for a request at $at (null = now)?
     * NOW requests need the provider online; future requests need a
     * LATER/TOMORROW/weekly window covering the time, or being online when
     * the time is within the next 2 hours.
     */
    public function qualifiesAt(User $user, ?Carbon $at, ?Carbon $until = null): bool
    {
        $now = now();
        if ($at === null || $at->lte($now->copy()->addMinutes(30))) {
            return $this->isOnlineNow($user);
        }
        $row = ProviderAvailability::query()->where('user_id', $user->id)->first();
        if ($row && $row->covers($at, $until)) {
            return true;
        }
        if ($at->lte($now->copy()->addHours(2)) && $this->isOnlineNow($user)) {
            return true;
        }

        return false;
    }

    /** Provider's own max service radius (km) or null for no limit. */
    public function maxRadius(int $userId): ?float
    {
        $v = ProviderAvailability::query()->where('user_id', $userId)->value('max_radius_km');

        return $v !== null ? (float) $v : null;
    }
}
