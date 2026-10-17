<x-filament-panels::page>
    @php
        $leafletCss = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
        $leafletJs = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
        $carIcon = asset('assets/img/map/car-icon.png');
        $pickupIcon = asset('assets/img/map/pickup.png');
        $dropIcon = asset('assets/img/map/destination.png');
    @endphp

    <script>
        function paLiveDispatch(cssUrl, jsUrl, carIcon, pickupIcon, dropIcon) {
            return {
                map: null, layers: null, fitted: false, updated: '—', counts: '', timer: null,
                boot() {
                    const ready = () => this.start();
                    if (window.L) return ready();
                    if (!document.querySelector('link[data-pa-leaflet]')) {
                        const l = document.createElement('link'); l.rel = 'stylesheet'; l.href = cssUrl; l.dataset.paLeaflet = '1'; document.head.appendChild(l);
                    }
                    const s = document.createElement('script'); s.src = jsUrl; s.onload = ready; document.head.appendChild(s);
                },
                start() {
                    this.map = L.map(this.$refs.map).setView([-26.2041, 28.0473], 11);
                    (window.PaMapTiles ? window.PaMapTiles(L) : L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OpenStreetMap contributors', maxZoom: 19 })).addTo(this.map);
                    this.layers = L.layerGroup().addTo(this.map);
                    this.map.on('dragstart', () => { this.fitted = true; });
                    this.refresh();
                    this.timer = setInterval(() => this.refresh(), 5000);
                },
                esc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); },
                async refresh() {
                    if (!this.$refs.map || !document.body.contains(this.$refs.map)) { clearInterval(this.timer); return; }
                    let data;
                    try { data = await this.$wire.mapData(); } catch (e) { return; }
                    this.layers.clearLayers();
                    const pts = [];
                    const pin = (url) => L.icon({ iconUrl: url, iconSize: [22, 30], iconAnchor: [11, 30] });
                    data.rides.forEach(r => {
                        const color = r.status === 'in_progress' ? '#2563eb' : (r.status === 'requested' ? '#6b7280' : '#dc2626');
                        if (r.trip && r.trip.length > 1) L.polyline(r.trip, { color: r.status === 'in_progress' ? '#2563eb' : '#6b7280', weight: 3, opacity: .55, dashArray: r.status === 'requested' ? '6 8' : null }).addTo(this.layers);
                        if (r.leg && r.leg.length > 1) L.polyline(r.leg, { color: color, weight: 5, opacity: .9 }).addTo(this.layers);
                        const popup = '<strong>' + this.esc(r.ref) + '</strong>' + (r.simulated ? ' <span style="color:#be185d">TEST</span>' : '') + '<br>' + this.esc(r.status.replace(/_/g, ' ')) + (r.dispatch && r.status === 'requested' ? ' · ' + this.esc(r.dispatch.replace(/_/g, ' ')) : '') +
                            '<br>Patient: ' + this.esc(r.patient) + '<br>Driver: ' + this.esc(r.driver || 'unassigned') + (r.eta != null ? '<br>ETA ' + r.eta + ' min' : '') + '<br><a href="' + r.url + '">Open ride</a>';
                        L.marker(r.pickup, { icon: pin(pickupIcon) }).bindPopup(popup).addTo(this.layers);
                        L.marker(r.dropoff, { icon: pin(dropIcon) }).bindPopup(popup).addTo(this.layers);
                        pts.push(r.pickup, r.dropoff);
                    });
                    data.drivers.forEach(d => {
                        const ring = d.availability === 'busy' ? '#d97706' : '#16a34a';
                        const icon = L.divIcon({ className: '', iconSize: [26, 46], iconAnchor: [13, 23],
                            html: '<div style="position:relative;width:26px;height:46px"><span style="position:absolute;left:5px;top:15px;width:16px;height:16px;border-radius:99px;box-shadow:0 0 0 3px ' + ring + ';"></span><img src="' + carIcon + '" style="position:absolute;left:4px;top:1px;width:18px;height:44px;transform:rotate(' + (d.heading || 0) + 'deg)"></div>' });
                        L.marker(d.pos, { icon: icon, zIndexOffset: 1000 }).bindPopup('<strong>' + this.esc(d.name) + '</strong><br>' + this.esc(d.availability) + ' · ' + this.esc((d.vehicle || '').replace(/_/g, ' ')) + (d.age != null ? '<br>GPS ' + d.age + 's ago' : '')).addTo(this.layers);
                        pts.push(d.pos);
                    });
                    this.updated = data.at;
                    this.counts = data.rides.length + ' rides · ' + data.drivers.length + ' drivers online';
                    if (!this.fitted && pts.length) { this.map.fitBounds(L.latLngBounds(pts), { padding: [30, 30], maxZoom: 14 }); this.fitted = true; }
                },
            };
        }
    </script>

    <x-filament::section>
        <div wire:ignore
             x-data="paLiveDispatch(@js($leafletCss), @js($leafletJs), @js($carIcon), @js($pickupIcon), @js($dropIcon))"
             x-init="boot()">
            <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:center;font-size:12px;margin-bottom:10px">
                <span><span style="display:inline-block;width:10px;height:10px;border-radius:99px;background:#16a34a"></span> Driver available</span>
                <span><span style="display:inline-block;width:10px;height:10px;border-radius:99px;background:#d97706"></span> Driver on trip</span>
                <span><span style="display:inline-block;width:14px;height:3px;background:#dc2626;vertical-align:middle"></span> Driver → pickup</span>
                <span><span style="display:inline-block;width:14px;height:3px;background:#2563eb;vertical-align:middle"></span> Trip in progress</span>
                <span><span style="display:inline-block;width:14px;height:3px;background:#6b7280;vertical-align:middle"></span> Waiting for driver</span>
                <span style="margin-left:auto;opacity:.7">Updated <span x-text="updated">—</span> · <span x-text="counts"></span></span>
            </div>
            <div x-ref="map" style="height:520px;width:100%;border-radius:10px;overflow:hidden;border:1px solid rgba(127,127,127,.25)"></div>
        </div>
    </x-filament::section>

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
                            <td class="p-2 font-semibold"><a href="{{ \App\Filament\Resources\Rides\RideResource::getUrl('view', ['record' => $ride]) }}" style="text-decoration:underline">{{ $ride->ride_ref }}</a>@if ($ride->isSimulated()) <x-filament::badge color="danger">TEST</x-filament::badge>@endif</td>
                            <td class="p-2">{{ $ride->patient?->user?->name ?? '—' }}</td>
                            <td class="p-2">{{ $ride->driver?->user?->name ?? 'Unassigned' }}</td>
                            <td class="p-2">
                                @php
                                    $badgeColor = match ($ride->status) {
                                        'requested' => $ride->dispatch_state === 'no_drivers' ? 'danger' : 'gray',
                                        'accepted', 'driver_enroute', 'arrived' => 'warning',
                                        'in_progress' => 'info',
                                        default => 'gray',
                                    };
                                    $badgeLabel = $ride->status === 'requested' && $ride->dispatch_state ? str($ride->dispatch_state)->headline() : str($ride->status)->headline();
                                @endphp
                                <x-filament::badge :color="$badgeColor">{{ $badgeLabel }}</x-filament::badge>
                            </td>
                            <td class="p-2">{{ $ride->eta_minutes !== null && $ride->status !== 'requested' ? "{$ride->eta_minutes} min" : '—' }}</td>
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
