<x-filament-panels::page>
    @php
        $ride = $this->ride()->loadMissing(['patient.user', 'driver.user', 'statusEvents', 'offers.driver.user', 'charges', 'review']);
        $tz = \App\Support\ShuttleSettings::str('shuttle_pricing_timezone') ?: 'Africa/Johannesburg';
        $bd = is_array($ride->fare_breakdown) ? $ride->fare_breakdown : [];
        $final = $bd['final'] ?? null;
        $trail = $this->trail();
        $replayCfg = [
            'trail' => $trail,
            'planned' => is_array($ride->route_geometry) ? $ride->route_geometry : [],
            'pickup' => [(float) $ride->pickup_lat, (float) $ride->pickup_lng],
            'dropoff' => [(float) $ride->dropoff_lat, (float) $ride->dropoff_lng],
            'car' => asset('assets/img/map/car-icon.png'),
            'pickupIcon' => asset('assets/img/map/pickup.png'),
            'dropIcon' => asset('assets/img/map/destination.png'),
            'css' => 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
            'js' => 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
        ];
        $row = 'display:flex;justify-content:space-between;gap:12px;padding:5px 0;font-size:14px;border-bottom:1px solid rgba(127,127,127,.12)';
        $fmt = fn ($dt) => $dt ? $dt->copy()->timezone($tz)->format('d M H:i:s') : '—';
    @endphp

    <script>
        function paRideReplay(cfg) {
            return {
                map: null, car: null, idx: 0, playing: false, timer: null, label: '',
                boot() {
                    const go = () => this.start();
                    if (window.L) return go();
                    if (!document.querySelector('link[data-pa-leaflet]')) { const l = document.createElement('link'); l.rel = 'stylesheet'; l.href = cfg.css; l.dataset.paLeaflet = '1'; document.head.appendChild(l); }
                    const s = document.createElement('script'); s.src = cfg.js; s.onload = go; document.head.appendChild(s);
                },
                start() {
                    this.map = L.map(this.$refs.map).setView(cfg.pickup, 13);
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OpenStreetMap contributors', maxZoom: 19 }).addTo(this.map);
                    const pin = (u) => L.icon({ iconUrl: u, iconSize: [22, 30], iconAnchor: [11, 30] });
                    L.marker(cfg.pickup, { icon: pin(cfg.pickupIcon) }).bindPopup('Pickup').addTo(this.map);
                    L.marker(cfg.dropoff, { icon: pin(cfg.dropIcon) }).bindPopup('Drop-off').addTo(this.map);
                    const pts = [cfg.pickup, cfg.dropoff];
                    if (cfg.planned.length > 1) { L.polyline(cfg.planned, { color: '#6b7280', weight: 4, opacity: .5, dashArray: '6 8' }).addTo(this.map); }
                    const toPickup = cfg.trail.filter(p => p[3] !== 'to_dropoff').map(p => [p[0], p[1]]);
                    const toDrop = cfg.trail.filter(p => p[3] === 'to_dropoff').map(p => [p[0], p[1]]);
                    if (toPickup.length > 1) L.polyline(toPickup, { color: '#dc2626', weight: 4 }).addTo(this.map);
                    if (toDrop.length > 1) L.polyline(toDrop, { color: '#2563eb', weight: 4 }).addTo(this.map);
                    cfg.trail.forEach(p => pts.push([p[0], p[1]]));
                    this.map.fitBounds(L.latLngBounds(pts), { padding: [30, 30] });
                    if (cfg.trail.length) this.seek(0);
                },
                seek(i) {
                    if (!cfg.trail.length) return;
                    this.idx = Math.max(0, Math.min(cfg.trail.length - 1, Number(i)));
                    const p = cfg.trail[this.idx];
                    const html = '<img src="' + cfg.car + '" style="width:18px;height:36px;transform:rotate(' + (p[2] || 0) + 'deg)">';
                    if (!this.car) this.car = L.marker([p[0], p[1]], { icon: L.divIcon({ className: '', html: html, iconSize: [18, 36], iconAnchor: [9, 18] }), zIndexOffset: 1000 }).addTo(this.map);
                    else { this.car.setLatLng([p[0], p[1]]); this.car.setIcon(L.divIcon({ className: '', html: html, iconSize: [18, 36], iconAnchor: [9, 18] })); }
                    this.label = (p[4] ? new Date(p[4]).toLocaleTimeString() : '') + ' · ' + (p[3] === 'to_dropoff' ? 'with patient' : 'to pickup');
                },
                toggle() {
                    this.playing = !this.playing;
                    clearInterval(this.timer);
                    if (this.playing) this.timer = setInterval(() => { if (this.idx >= cfg.trail.length - 1) { this.playing = false; clearInterval(this.timer); return; } this.seek(this.idx + 1); }, 120);
                },
            };
        }
    </script>

    <div wire:poll.5s="refreshRide">
        @if ($ride->isSimulated())
            <div style="padding:10px 14px;border:1px dashed #be185d;background:rgba(190,24,93,.08);color:#be185d;font-weight:700;border-radius:8px;margin-bottom:12px">
                TEST simulation running on this ride ({{ $ride->simulation['speed_kmh'] ?? '?' }} km/h, leg: {{ $ride->simulation['leg'] ?? '?' }}{{ ! empty($ride->simulation['auto']) ? ', auto-progress' : '' }}).
            </div>
        @endif

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px">
            <x-filament::section heading="Trip">
                <div style="{{ $row }}"><span>Status</span><strong>{{ str($ride->status)->headline() }}{{ $ride->status === 'requested' && $ride->dispatch_state ? ' · '.str($ride->dispatch_state)->headline() : '' }}</strong></div>
                <div style="{{ $row }}"><span>Patient</span><span>{{ $ride->patient?->user?->name ?? '—' }} · {{ $ride->patient?->user?->phone }}</span></div>
                <div style="{{ $row }}"><span>Driver</span><span>{{ $ride->driver?->user?->name ?? 'Unassigned' }}{{ $ride->driver ? ' · '.$ride->driver->vehicle_plate_no : '' }}</span></div>
                <div style="{{ $row }}"><span>Vehicle type</span><span>{{ str_replace('_', ' ', $ride->vehicle_type) }}</span></div>
                <div style="{{ $row }}"><span>Pickup</span><span style="text-align:right">{{ $ride->pickup_address }}</span></div>
                <div style="{{ $row }}"><span>Drop-off</span><span style="text-align:right">{{ $ride->dropoff_address }}</span></div>
                <div style="{{ $row }}"><span>Planned route</span><span>{{ $ride->route_distance_km ?? $ride->distance_km }} km · {{ $ride->route_duration_min ?? '—' }} min</span></div>
                <div style="{{ $row }}"><span>Trip PIN</span><span>{{ $ride->trip_pin ?? '—' }}</span></div>
                <div style="{{ $row }}"><span>Scheduled for</span><span>{{ $fmt($ride->scheduled_for) }}</span></div>
                <div style="{{ $row }}"><span>Dispatch attempts</span><span>{{ $ride->dispatch_attempts }}</span></div>
                @if ($ride->share_token && $ride->isActive())
                    <div style="{{ $row }}"><span>Share link</span><a href="{{ route('trip.share', $ride->share_token) }}" target="_blank" style="text-decoration:underline">Open public view</a></div>
                @endif
            </x-filament::section>

            <x-filament::section heading="Fare">
                @if (isset($bd['base_fare']))
                    <div style="{{ $row }}"><span>Base fare</span><span>R {{ number_format($bd['base_fare'], 2) }}</span></div>
                    <div style="{{ $row }}"><span>Distance {{ number_format($bd['distance_km'] ?? 0, 2) }} km</span><span>R {{ number_format($bd['distance_charge'] ?? 0, 2) }}</span></div>
                    <div style="{{ $row }}"><span>Time {{ $bd['duration_min'] ?? 0 }} min</span><span>R {{ number_format($bd['time_charge'] ?? 0, 2) }}</span></div>
                    <div style="{{ $row }}"><span>Multiplier (time ×{{ $bd['time_multiplier'] ?? 1 }}{{ ! empty($bd['time_label']) ? ' '.$bd['time_label'] : '' }}, surge ×{{ $bd['surge_multiplier'] ?? 1 }}) → ×{{ $bd['multiplier'] ?? 1 }}</span><span>R {{ number_format($bd['dynamic_charge'] ?? 0, 2) }}</span></div>
                    @if (! empty($bd['demand']))
                        <div style="{{ $row }}"><span>Demand at booking</span><span>{{ $bd['demand']['requests'] ?? 0 }} requests / {{ $bd['demand']['drivers'] ?? 0 }} drivers (ratio {{ $bd['demand']['ratio'] ?? 0 }})</span></div>
                    @endif
                    <div style="{{ $row }}"><span>Minimum fare{{ ! empty($bd['minimum_applied']) ? ' (applied)' : '' }}</span><span>R {{ number_format($bd['minimum_fare'] ?? 0, 2) }}</span></div>
                    <div style="{{ $row }}"><span>Booking fee</span><span>R {{ number_format($bd['booking_fee'] ?? 0, 2) }}</span></div>
                    <div style="{{ $row }}"><span>Route source</span><span>{{ $bd['route_source'] ?? '—' }}</span></div>
                @else
                    <div style="font-size:13px;opacity:.7">No breakdown stored (ride booked before dynamic pricing).</div>
                @endif
                <div style="{{ $row }}"><strong>Upfront (locked) fare</strong><strong>R {{ number_format((float) ($ride->quoted_fare ?? $ride->fare_estimate), 2) }}</strong></div>
                <div style="{{ $row }}"><span>Quote token / expiry</span><span>{{ $ride->quote_token ? substr($ride->quote_token, 0, 10).'…' : 'priced at booking' }} · {{ $fmt($ride->quote_expires_at) }}</span></div>
                @if ($final)
                    <div style="{{ $row }}"><span>Planned vs driven</span><span>{{ $final['planned_km'] }} km vs {{ $final['actual_km'] ?? 'n/a' }} km{{ $final['route_changed'] ? ' (re-priced: R '.number_format((float) $final['repriced_total'], 2).')' : '' }}</span></div>
                    <div style="{{ $row }}"><span>Waiting charged</span><span>{{ $final['waiting_minutes_charged'] }} min · R {{ number_format($final['waiting_charge'], 2) }}</span></div>
                    <div style="{{ $row }}"><strong>Final fare</strong><strong>R {{ number_format((float) $final['total'], 2) }}</strong></div>
                @endif
            </x-filament::section>
        </div>

        @if ($ride->status === 'cancelled')
            <x-filament::section heading="Cancellation" style="margin-top:16px">
                <div style="{{ $row }}"><span>Cancelled by</span><span>{{ str_replace('_', ' ', (string) $ride->cancelled_by) }}</span></div>
                <div style="{{ $row }}"><span>At</span><span>{{ $fmt($ride->cancelled_at) }}</span></div>
                <div style="{{ $row }}"><span>Reason</span><span style="text-align:right">{{ $ride->cancel_reason ?: '—' }}</span></div>
                <div style="{{ $row }}"><span>Fee</span><span>R {{ number_format((float) $ride->cancellation_fee, 2) }} · {{ $ride->cancellation_fee_status ?? 'none' }}</span></div>
            </x-filament::section>
        @endif

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;margin-top:16px">
            <x-filament::section heading="Charges">
                @forelse ($ride->charges as $c)
                    <div style="{{ $row }}"><span>{{ $c->typeLabel() }} · {{ $c->gateway_ref ?? 'no ref' }}{{ $c->note ? ' · '.\Illuminate\Support\Str::limit($c->note, 60) : '' }}</span><span>R {{ number_format((float) $c->amount, 2) }} · <strong>{{ $c->status }}</strong></span></div>
                @empty
                    <div style="font-size:13px;opacity:.7">No charges.</div>
                @endforelse
            </x-filament::section>

            <x-filament::section heading="Driver offers">
                @forelse ($ride->offers->sortBy('id') as $o)
                    <div style="{{ $row }}"><span>#{{ $o->attempt_no }} {{ $o->driver?->user?->name ?? '#'.$o->driver_profile_id }} · {{ $o->eta_minutes !== null ? $o->eta_minutes.' min away' : '' }}</span><span>{{ $o->status }} · {{ $fmt($o->offered_at) }}</span></div>
                @empty
                    <div style="font-size:13px;opacity:.7">No offers yet.</div>
                @endforelse
            </x-filament::section>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;margin-top:16px">
            <x-filament::section heading="Timeline">
                @foreach ($ride->statusEvents->sortBy('id') as $e)
                    <div style="{{ $row }}"><span>{{ str(explode(' | ', $e->status)[0])->headline() }}@if (str_contains($e->status, ' | ')) <span style="opacity:.7">— {{ \Illuminate\Support\Str::after($e->status, ' | ') }}</span>@endif</span><span>{{ $fmt($e->created_at) }}</span></div>
                @endforeach
                @if ($ride->review)
                    <div style="{{ $row }}"><span>Patient rated driver</span><span>{{ $ride->review->rating }}/5 {{ $ride->review->comment }}</span></div>
                @endif
                @if ($ride->patient_rating)
                    <div style="{{ $row }}"><span>Driver rated patient</span><span>{{ $ride->patient_rating }}/5 {{ $ride->patient_rating_comment }}</span></div>
                @endif
            </x-filament::section>

            <x-filament::section heading="Route replay ({{ count($trail) }} GPS points)">
                <div wire:ignore x-data="paRideReplay(@js($replayCfg))" x-init="boot()">
                    <div x-ref="map" style="height:360px;border-radius:8px;overflow:hidden;border:1px solid rgba(127,127,127,.25)"></div>
                    @if (count($trail) > 1)
                        <div style="display:flex;gap:10px;align-items:center;margin-top:10px">
                            <x-filament::button size="sm" color="gray" x-on:click="toggle()"><span x-text="playing ? 'Pause' : 'Play'">Play</span></x-filament::button>
                            <input type="range" min="0" max="{{ count($trail) - 1 }}" x-bind:value="idx" x-on:input="seek($event.target.value)" style="flex:1">
                            <span style="font-size:12px;opacity:.75;min-width:150px" x-text="label"></span>
                        </div>
                    @else
                        <div style="font-size:13px;opacity:.7;margin-top:8px">GPS breadcrumbs appear here once a driver is assigned and sharing location. Red = to pickup, blue = with patient, grey dashed = planned route.</div>
                    @endif
                </div>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
