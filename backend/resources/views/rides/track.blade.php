<x-layout title="Track Your Ride — Prompt Aid">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />

    <section class="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-secondary-500">Track your ride</h1>
                <p class="text-sm text-gray-500">{{ $ride->ride_ref }}</p>
            </div>
            <span id="status-badge" class="badge badge-warning">{{ str($ride->status)->headline() }}</span>
        </div>

        <div class="mt-6 card !p-0 overflow-hidden">
            <div id="map" style="height: 420px; width: 100%;"></div>
        </div>

        <div class="mt-6 grid gap-4 sm:grid-cols-3">
            <div class="card">
                <p class="text-xs font-semibold text-gray-500">Driver</p>
                <p id="driver-name" class="mt-1 font-semibold text-secondary-500">{{ $ride->driver?->user->name ?? 'Matching you with a driver...' }}</p>
                <p id="driver-vehicle" class="text-xs text-gray-500">{{ $ride->driver ? "{$ride->driver->vehicle_make} {$ride->driver->vehicle_model} · {$ride->driver->vehicle_plate_no}" : '' }}</p>
            </div>
            <div class="card">
                <p class="text-xs font-semibold text-gray-500">ETA</p>
                <p id="eta" class="mt-1 font-semibold text-secondary-500">{{ $ride->eta_minutes ? "{$ride->eta_minutes} min" : '—' }}</p>
            </div>
            <div class="card">
                <p class="text-xs font-semibold text-gray-500">Fare</p>
                <p class="mt-1 font-semibold text-secondary-500">R{{ number_format($ride->fare_final ?? $ride->fare_estimate, 2) }}</p>
            </div>
        </div>

        <div class="mt-6 card">
            <p class="text-xs font-semibold text-gray-500 mb-2">Pickup</p>
            <p class="text-sm text-gray-700">📍 {{ $ride->pickup_address }}</p>
            <p class="text-xs font-semibold text-gray-500 mt-4 mb-2">Drop-off</p>
            <p class="text-sm text-gray-700">🏥 {{ $ride->dropoff_address }}</p>
        </div>

        @if ($ride->status === 'completed' && ! $ride->is_return && ! $ride->returnLeg)
            <div class="mt-6 card flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p class="text-sm font-semibold text-secondary-500">Need a ride back?</p>
                    <p class="text-xs text-gray-500">Request the return leg — same route, reversed.</p>
                </div>
                <form method="POST" action="{{ route('rides.return', $ride) }}">
                    @csrf
                    <button type="submit" class="btn-primary !px-5 !py-2.5 text-xs">Request return ride</button>
                </form>
            </div>
        @endif
    </section>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script>
        const rideId = {{ $ride->id }};
        const pickup = [{{ $ride->pickup_lat }}, {{ $ride->pickup_lng }}];
        const dropoff = [{{ $ride->dropoff_lat }}, {{ $ride->dropoff_lng }}];

        const map = L.map('map').setView(pickup, 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors',
            maxZoom: 19,
        }).addTo(map);

        const pickupIcon = L.divIcon({ html: '📍', className: 'text-2xl', iconSize: [24, 24] });
        const dropoffIcon = L.divIcon({ html: '🏥', className: 'text-2xl', iconSize: [24, 24] });
        const driverIcon = L.divIcon({ html: '🚗', className: 'text-2xl', iconSize: [24, 24] });

        L.marker(pickup, { icon: pickupIcon }).addTo(map).bindPopup('Pickup');
        L.marker(dropoff, { icon: dropoffIcon }).addTo(map).bindPopup('Drop-off');
        const bounds = L.latLngBounds([pickup, dropoff]);
        map.fitBounds(bounds, { padding: [40, 40] });

        let driverMarker = null;
        const statusLabels = {
            requested: 'Requested', accepted: 'Driver Assigned', driver_enroute: 'Driver En Route',
            arrived: 'Driver Arrived', in_progress: 'In Progress', completed: 'Completed', cancelled: 'Cancelled',
        };
        const statusColors = {
            requested: 'badge-warning', accepted: 'badge-warning', driver_enroute: 'badge-warning',
            arrived: 'badge-warning', in_progress: 'badge-warning', completed: 'badge-success', cancelled: 'badge-danger',
        };

        async function poll() {
            try {
                const res = await fetch(`/rides/${rideId}/status`, { headers: { Accept: 'application/json' } });
                if (!res.ok) return;
                const ride = await res.json();

                const badge = document.getElementById('status-badge');
                badge.textContent = statusLabels[ride.status] ?? ride.status;
                badge.className = 'badge ' + (statusColors[ride.status] ?? 'badge-warning');

                document.getElementById('eta').textContent = ride.eta_minutes ? `${ride.eta_minutes} min` : '—';

                if (ride.driver) {
                    document.getElementById('driver-name').textContent = ride.driver.name;
                    document.getElementById('driver-vehicle').textContent = `${ride.driver.vehicle_make ?? ''} ${ride.driver.vehicle_model ?? ''} · ${ride.driver.vehicle_plate_no ?? ''}`;

                    if (ride.driver.current_lat && ride.driver.current_lng) {
                        const pos = [ride.driver.current_lat, ride.driver.current_lng];
                        if (!driverMarker) {
                            driverMarker = L.marker(pos, { icon: driverIcon }).addTo(map).bindPopup('Your driver');
                        } else {
                            driverMarker.setLatLng(pos);
                        }
                    }
                }

                if (['completed', 'cancelled'].includes(ride.status)) {
                    clearInterval(interval);
                }
            } catch (e) {
                // silently retry on next tick
            }
        }

        poll();
        const interval = setInterval(poll, 6000);
    </script>
</x-layout>
