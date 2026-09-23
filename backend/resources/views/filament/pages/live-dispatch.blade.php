<x-filament-panels::page>
    <div wire:poll.10s>
        <x-filament::section>
            <table class="fi-ta-table w-full text-sm">
                <thead>
                    <tr class="text-left">
                        <th class="p-2">Ride</th>
                        <th class="p-2">Patient</th>
                        <th class="p-2">Driver</th>
                        <th class="p-2">Status</th>
                        <th class="p-2">ETA</th>
                        <th class="p-2">Pickup</th>
                        <th class="p-2">Drop-off</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->getRides() as $ride)
                        <tr class="border-t">
                            <td class="p-2 font-semibold">{{ $ride->ride_ref }}</td>
                            <td class="p-2">{{ $ride->patient?->user?->name ?? '—' }}</td>
                            <td class="p-2">{{ $ride->driver?->user?->name ?? 'Unassigned' }}</td>
                            <td class="p-2">
                                <x-filament::badge :color="match($ride->status) {
                                    'requested' => 'gray', 'accepted', 'driver_enroute', 'arrived' => 'warning',
                                    'in_progress' => 'info', default => 'gray',
                                }">
                                    {{ str($ride->status)->headline() }}
                                </x-filament::badge>
                            </td>
                            <td class="p-2">{{ $ride->eta_minutes ? "{$ride->eta_minutes} min" : '—' }}</td>
                            <td class="p-2">{{ str($ride->pickup_address)->limit(30) }}</td>
                            <td class="p-2">{{ str($ride->dropoff_address)->limit(30) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="p-4 text-center text-gray-500">No rides in flight right now.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-filament::section>
    </div>
</x-filament-panels::page>
