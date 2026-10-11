<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Rides\RideResource;
use App\Models\DriverProfile;
use App\Models\Ride;
use App\Services\Rides\RideDispatchService;
use App\Services\Rides\RideLocationService;
use App\Services\Rides\RideSimulator;
use App\Support\ShuttleSettings;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use UnitEnum;

/**
 * Live board of every ride in flight + every online driver: a Leaflet map
 * (refreshed every 5 s through mapData()) and the table below it (wire:poll).
 * Each refresh also performs the lazy dispatch/simulator progression.
 */
class LiveDispatch extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|UnitEnum|null $navigationGroup = 'Ride Service';

    protected static ?string $navigationLabel = 'Live Dispatch';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.live-dispatch';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('live-dispatch.view');
    }

    /**
     * @return Collection<int, Ride>
     */
    public function getRides(): Collection
    {
        return Ride::query()
            ->whereNotIn('status', [Ride::STATUS_COMPLETED, Ride::STATUS_CANCELLED])
            ->with(['patient.user', 'driver.user'])
            ->orderByRaw("CASE status WHEN 'requested' THEN 0 WHEN 'accepted' THEN 1 WHEN 'driver_enroute' THEN 2 WHEN 'arrived' THEN 3 WHEN 'in_progress' THEN 4 ELSE 5 END")
            ->latest('requested_at')
            ->get();
    }

    /** Polled by the map every 5 s. */
    public function mapData(): array
    {
        abort_unless(static::canAccess(), 403);

        if (Cache::add('admin-dispatch:tick', 1, 5)) {
            app(RideDispatchService::class)->tickAll();
        }

        $locations = app(RideLocationService::class);
        $simulator = app(RideSimulator::class);
        $stale = now()->subMinutes(max(1, ShuttleSettings::int('shuttle_driver_stale_minutes')));

        $rides = $this->getRides()->map(function (Ride $r) use ($locations, $simulator) {
            if ($r->isSimulated()) {
                $simulator->tick($r);
                $r = $r->fresh(['driver.user', 'patient.user']);
            }
            $eta = $r->hasDriverEnRoute() ? $locations->driverEta($r) : null;

            return [
                'id' => $r->id,
                'ref' => $r->ride_ref,
                'status' => $r->status,
                'dispatch' => $r->dispatch_state,
                'vehicle' => $r->vehicle_type,
                'patient' => $r->patient?->user?->name,
                'driver' => $r->driver?->user?->name,
                'pickup' => [(float) $r->pickup_lat, (float) $r->pickup_lng],
                'dropoff' => [(float) $r->dropoff_lat, (float) $r->dropoff_lng],
                'eta' => $eta['minutes'] ?? null,
                'leg' => $eta['geometry'] ?? null,
                'trip' => is_array($r->route_geometry) ? $r->route_geometry : null,
                'simulated' => $r->isSimulated(),
                'url' => RideResource::getUrl('view', ['record' => $r]),
            ];
        })->values()->all();

        $drivers = DriverProfile::query()->with('user')
            ->where('availability', '!=', DriverProfile::OFFLINE)
            ->whereNotNull('current_lat')
            ->where('location_updated_at', '>=', $stale)
            ->get()
            ->map(fn (DriverProfile $d) => [
                'id' => $d->id,
                'name' => $d->user?->name,
                'availability' => $d->availability,
                'vehicle' => $d->vehicle_type,
                'pos' => [(float) $d->current_lat, (float) $d->current_lng],
                'heading' => $d->current_heading !== null ? (float) $d->current_heading : 0,
                'age' => $d->location_updated_at ? (int) $d->location_updated_at->diffInSeconds(now(), true) : null,
            ])->values()->all();

        return ['rides' => $rides, 'drivers' => $drivers, 'at' => now()->format('H:i:s')];
    }
}
