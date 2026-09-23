<x-layout :title="'Tracking your shuttle · Prompt Aid'">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />

<div class="pa-container" style="padding-top:28px;padding-bottom:72px">
  <div class="pa-crumb"><a href="{{ url('/') }}">Home</a> <span style="color:#CFC8B8">/</span> <a href="{{ route('shuttle') }}">Shuttle</a> <span style="color:#CFC8B8">/</span> {{ $ride->ride_ref }}</div>
  <div style="display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:28px;align-items:start">
    <div class="pa-card" style="!p-0 overflow-hidden">
      <div id="map" style="height:420px;width:100%"></div>
    </div>
    <div>
      <div class="pa-card" style="margin-bottom:20px">
        <div class="pa-card-head"><h3>{{ $ride->ride_ref }}</h3><span class="pa-badge is-wait" id="status-badge">{{ str($ride->status)->headline() }}</span></div>
        <div class="pa-card-body">
          <div class="pa-row" style="gap:14px;margin-bottom:18px">
            <div class="pa-avatar round" style="width:52px;height:52px">{{ $ride->driver ? \Illuminate\Support\Str::of($ride->driver->user->name)->explode(' ')->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('') : '—' }}</div>
            <div style="flex:1">
              <div style="font-size:16px;font-weight:700" id="driver-name">{{ $ride->driver?->user->name ?? 'Matching you with a driver…' }}</div>
              <div style="font-size:13px;color:var(--pa-muted)" id="driver-vehicle">{{ $ride->driver ? "{$ride->driver->vehicle_make} {$ride->driver->vehicle_model} · {$ride->driver->vehicle_plate_no}" : '' }}</div>
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:18px">
            <a class="pa-btn-ghost" href="tel:{{ \App\Models\Setting::get('support_phone', '0800776678') }}">Call support</a>
            <a class="pa-btn-ghost" href="{{ route('contact') }}">Message</a>
          </div>
          <div class="pa-spread" style="font-size:14px;margin-bottom:7px"><span class="pa-muted">ETA</span><strong id="eta">{{ $ride->eta_minutes ? "{$ride->eta_minutes} min" : '—' }}</strong></div>
          <div class="pa-spread" style="font-size:14px"><span class="pa-muted">Fare</span><strong>R {{ number_format($ride->fare_final ?? $ride->fare_estimate, 2) }}</strong></div>
        </div>
      </div>
      <div class="pa-card pa-card-pad" style="margin-bottom:20px">
        <div class="pa-label" style="margin-bottom:12px">Route</div>
        <div style="font-size:14px;margin-bottom:10px"><span class="pa-muted">Pickup</span><br />{{ $ride->pickup_address }}</div>
        <div style="font-size:14px"><span class="pa-muted">Drop-off</span><br />{{ $ride->dropoff_address }}</div>
      </div>

      @if ($ride->status === 'completed' && ! $ride->is_return && ! $ride->returnLeg)
        <div class="pa-card pa-card-pad" style="margin-bottom:20px">
          <div style="font-size:15px;font-weight:700;margin-bottom:4px">Need a ride back?</div>
          <p class="pa-muted" style="font-size:13px;margin-bottom:14px">Request the return leg — same route, reversed.</p>
          <form method="POST" action="{{ route('rides.return', $ride) }}">
            @csrf
            <button type="submit" class="pa-btn pa-btn-block">Request return ride</button>
          </form>
        </div>
      @endif

      <a class="pa-btn-ghost pa-btn-block" href="{{ route('dashboard') }}">All my trips</a>
    </div>
  </div>
</div>

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
        requested: 'is-wait', accepted: 'is-wait', driver_enroute: 'is-wait',
        arrived: 'is-wait', in_progress: 'is-wait', completed: 'is-go', cancelled: 'is-stop',
    };

    async function poll() {
        try {
            const res = await fetch(`/rides/${rideId}/status`, { headers: { Accept: 'application/json' } });
            if (!res.ok) return;
            const ride = await res.json();

            const badge = document.getElementById('status-badge');
            badge.textContent = statusLabels[ride.status] ?? ride.status;
            badge.className = 'pa-badge ' + (statusColors[ride.status] ?? 'is-wait');

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
