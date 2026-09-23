<?php

namespace App\Filament\Pages;

use App\Models\Ride;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

/**
 * A live board of every ride that's currently in flight (requested through
 * in_progress) — the dispatcher's view of "what's happening right now",
 * polling the same Ride rows the driver app and patient tracking page use.
 */
class LiveDispatch extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|UnitEnum|null $navigationGroup = 'Ride Service';

    protected static ?string $navigationLabel = 'Live Dispatch';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.live-dispatch';

    /**
     * @return Collection<int, Ride>
     */
    public function getRides(): Collection
    {
        return Ride::query()
            ->whereNotIn('status', [Ride::STATUS_COMPLETED, Ride::STATUS_CANCELLED])
            ->with(['patient.user', 'driver.user'])
            ->orderByRaw("FIELD(status, 'requested', 'accepted', 'driver_enroute', 'arrived', 'in_progress')")
            ->latest('requested_at')
            ->get();
    }
}
