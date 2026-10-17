<x-layout title="Medical shuttle · Prompt Aid">
@push('meta')
<meta name="pa-asset-base" content="{{ asset('assets') }}">
@endpush
<section class="pa-dark">
  <div class="pa-container" style="padding-top:72px;padding-bottom:72px;display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:56px;align-items:center">
    <div>
      <div class="pa-row" style="margin-bottom:22px"><span class="pa-tick-beacon"></span><span class="pa-eyebrow" style="color:var(--pa-beacon)">Medical shuttle</span></div>
      <h1 style="font-size:54px;margin-bottom:20px">Door to doctor.<br />And back again.</h1>
      <p style="font-size:17px;color:#B8B3A8;max-width:480px;margin-bottom:32px">Vetted drivers, medical-grade vehicles, fares quoted before you confirm. Book a one-off trip, a return leg, or a standing weekly booking for dialysis and chemotherapy.</p>
      <div style="display:flex;gap:12px;flex-wrap:wrap"><a class="pa-btn-beacon pa-btn-lg" href="#request">Get a fare estimate</a><a class="pa-btn-lg" style="background:transparent;border:1px solid #4A4A4E;color:var(--pa-paper);padding:15px 26px;font-weight:700;display:inline-flex;align-items:center" href="{{ route('for-providers') }}">Drive with us</a></div>
    </div>
    <div id="request" style="background:var(--pa-paper);color:var(--pa-ink);padding:28px">
      <div class="pa-label" style="margin-bottom:18px">Request a trip</div>
      <form method="POST" action="{{ route('shuttle.quote') }}">
        @csrf
        <div class="pa-formrow">
          <label class="pa-label" for="shuttle-pickup">Pick up</label>
          <input class="pa-field" id="shuttle-pickup" name="pickup_address" required maxlength="255" value="{{ old('pickup_address', $pickup_address ?? '') }}" placeholder="Start typing your address" data-pa-address-autocomplete />
          <input type="hidden" name="pickup_lat" value="{{ old('pickup_lat', $pickup_lat ?? '') }}">
          <input type="hidden" name="pickup_lng" value="{{ old('pickup_lng', $pickup_lng ?? '') }}">
          <button type="button" class="pa-ac-locate" data-pa-geolocate="pickup_address">Use my current location</button>
          @error('pickup_address')<div style="color:var(--pa-signal);font-size:12px;margin-top:6px">{{ $message }}</div>@enderror
        </div>
        <div class="pa-formrow">
          <label class="pa-label" for="shuttle-dropoff">Drop off</label>
          <input class="pa-field" id="shuttle-dropoff" name="dropoff_address" required maxlength="255" value="{{ old('dropoff_address', $dropoff_address ?? '') }}" placeholder="Clinic, pharmacy or lab address" data-pa-address-autocomplete />
          <input type="hidden" name="dropoff_lat" value="{{ old('dropoff_lat', $dropoff_lat ?? '') }}">
          <input type="hidden" name="dropoff_lng" value="{{ old('dropoff_lng', $dropoff_lng ?? '') }}">
          @error('dropoff_address')<div style="color:var(--pa-signal);font-size:12px;margin-top:6px">{{ $message }}</div>@enderror
        </div>
        @if ($errors->any() && ! $errors->has('pickup_address') && ! $errors->has('dropoff_address'))
          <div style="color:var(--pa-signal);font-size:12px;margin-bottom:10px">{{ $errors->first() }}</div>
        @endif
        <button type="submit" class="pa-btn pa-btn-block" style="margin-bottom:18px">Get fare estimate</button>
      </form>

      @if (is_array($quotes) && count($quotes) === 0)
        <div class="pa-note" style="margin-bottom:18px">No shuttle vehicles are available to quote right now. Please try again shortly or contact us.</div>
      @endif

      @if ($quotes)
        @php
          $labels = ['sedan' => ['Standard', 'Sedan, 1–3 seats'], 'suv' => ['Comfort', 'SUV, extra legroom'], 'wheelchair_accessible' => ['Wheelchair', 'Ramp and restraints'], 'stretcher' => ['Stretcher', 'Non-emergency transfer'], 'van' => ['Van', 'Larger group transfer']];
          $firstQuote = $quotes[0];
          $surgeLabel = collect($quotes)->pluck('surge_label')->filter()->first();
          $quoteMapCfg = ['pickup' => [$pickup_lat, $pickup_lng], 'dropoff' => [$dropoff_lat, $dropoff_lng], 'points' => $route_points ?? []];
          $quoteExpires = $firstQuote['quote_expires_at'] ?? null;
        @endphp
        @if (! empty($quote_notice))
          <div class="pa-note-stop" style="margin-bottom:12px">{{ $quote_notice }}</div>
        @endif
        <div id="pa-quote-map" class="pa-sh-quote-map"></div>
        @isset($distance_km)
          <div style="font-size:12px;color:var(--pa-muted);margin-bottom:10px">{{ number_format($distance_km, 1) }} km by road · about {{ $duration_min ?? '—' }} min{{ ($route_source ?? '') === 'estimate' ? ' (estimated)' : '' }} · choose a vehicle</div>
        @endisset
        @if ($surgeLabel)
          <div style="margin-bottom:10px"><span class="pa-sh-surge">{{ $surgeLabel }}</span></div>
        @endif
        <div style="display:grid;gap:2px;margin-bottom:12px">
          @foreach ($quotes as $i => $q)
            @php $label = $labels[$q['vehicle_type']] ?? [ucfirst(str_replace('_', ' ', $q['vehicle_type'])), '']; @endphp
            <button type="button" class="pa-tab pa-spread" data-vehicle-type="{{ $q['vehicle_type'] }}" data-fare="{{ $q['fare'] }}" data-token="{{ $q['quote_token'] }}" data-breakdown="vt-bd-{{ $q['vehicle_type'] }}"
              style="border:1px solid {{ $i === 0 ? 'var(--pa-ink)' : 'var(--pa-line)' }};background:{{ $i === 0 ? 'var(--pa-beacon-wash)' : '#fff' }};padding:13px 14px;text-align:left;width:100%;color:var(--pa-ink)">
              <span><span style="display:block;font-size:14px;font-weight:700">{{ $label[0] }}</span><span style="display:block;font-size:12px;color:var(--pa-muted)">{{ $label[1] }}</span></span>
              <span style="text-align:right"><span class="pa-num" style="display:block;font-size:18px">R {{ number_format($q['fare'], 0) }}</span>@if ($q['is_surging'] ?? false)<span style="display:block;font-size:11px;color:#7A5C00;font-weight:700">×{{ number_format($q['multiplier'], 2) }}</span>@endif</span>
            </button>
          @endforeach
        </div>
        @foreach ($quotes as $i => $q)
          @php $bd = $q['breakdown'] ?? []; @endphp
          <details id="vt-bd-{{ $q['vehicle_type'] }}" class="pa-sh-card" style="padding:10px 14px;margin-bottom:12px{{ $i === 0 ? '' : ';display:none' }}">
            <summary style="cursor:pointer;font-size:13px;font-weight:700">Fare breakdown</summary>
            <div class="pa-sh-row"><span class="k">Base fare</span><span>R {{ number_format($bd['base_fare'] ?? 0, 2) }}</span></div>
            <div class="pa-sh-row"><span class="k">Distance ({{ number_format($bd['distance_km'] ?? 0, 1) }} km)</span><span>R {{ number_format($bd['distance_charge'] ?? 0, 2) }}</span></div>
            @if (($bd['time_charge'] ?? 0) > 0)<div class="pa-sh-row"><span class="k">Time ({{ $bd['duration_min'] ?? 0 }} min)</span><span>R {{ number_format($bd['time_charge'], 2) }}</span></div>@endif
            @if (($bd['dynamic_charge'] ?? 0) > 0)<div class="pa-sh-row"><span class="k">{{ ($bd['surge_multiplier'] ?? 1) > 1 ? 'High demand' : ($bd['time_label'] ?? 'Peak time') }} (×{{ number_format($bd['multiplier'] ?? 1, 2) }})</span><span>R {{ number_format($bd['dynamic_charge'], 2) }}</span></div>@endif
            @if (! empty($bd['minimum_applied']))<div class="pa-sh-row"><span class="k">Minimum fare</span><span>R {{ number_format($bd['minimum_fare'] ?? 0, 2) }}</span></div>@endif
            @if (($bd['booking_fee'] ?? 0) > 0)<div class="pa-sh-row"><span class="k">Booking fee</span><span>R {{ number_format($bd['booking_fee'], 2) }}</span></div>@endif
            <div class="pa-sh-row total"><span>Upfront price</span><span>R {{ number_format($q['fare'], 2) }}</span></div>
            <div class="pa-sh-sub">Price is locked when you confirm. It only changes for paid waiting time (after {{ \App\Support\ShuttleSettings::int('shuttle_free_wait_minutes') }} free min) or if the route driven is much longer than planned.</div>
          </details>
        @endforeach

        @auth
          <form method="POST" action="{{ route('rides.store') }}" id="confirm-ride-form">
            @csrf
            <input type="hidden" name="pickup_address" value="{{ $pickup_address }}">
            <input type="hidden" name="pickup_lat" value="{{ $pickup_lat }}">
            <input type="hidden" name="pickup_lng" value="{{ $pickup_lng }}">
            <input type="hidden" name="dropoff_address" value="{{ $dropoff_address }}">
            <input type="hidden" name="dropoff_lat" value="{{ $dropoff_lat }}">
            <input type="hidden" name="dropoff_lng" value="{{ $dropoff_lng }}">
            <input type="hidden" name="vehicle_type" id="confirm-vehicle-type" value="{{ $firstQuote['vehicle_type'] }}">
            <input type="hidden" name="quote_token" id="confirm-quote-token" value="{{ $firstQuote['quote_token'] }}">
            @php
              $shRadiusOptions = \App\Support\DispatchSettings::radiusOptions();
              $shRadius = auth()->user()?->patientProfile?->search_radius_km !== null ? (int) auth()->user()->patientProfile->search_radius_km : (int) \App\Support\DispatchSettings::defaultRadius();
            @endphp
            <div style="display:flex;gap:10px;align-items:center;margin:0 0 10px">
              <label class="pa-label" for="confirm-radius" style="margin:0">Find drivers within</label>
              <select class="pa-field" name="radius_km" id="confirm-radius" style="max-width:120px">
                @foreach ($shRadiusOptions as $km)
                  <option value="{{ $km }}" @selected($km === $shRadius)>{{ $km }} km</option>
                @endforeach
              </select>
            </div>
            <label class="pa-label" for="confirm-notes">Note for the driver (optional)</label>
            <input class="pa-field" type="text" name="pickup_notes" id="confirm-notes" maxlength="500" placeholder="e.g. Gate 2, I use a walker" style="margin-bottom:10px">
            <button type="submit" class="pa-btn pa-btn-block pa-btn-lg" id="confirm-ride-btn">Confirm · R {{ number_format($firstQuote['fare'], 0) }}</button>
            @if ($quoteExpires)
              <div class="pa-sh-sub" style="text-align:center;margin-top:6px" id="quote-expiry" data-expires="{{ $quoteExpires }}">Price held for {{ \App\Support\ShuttleSettings::int('shuttle_quote_ttl_minutes') }} min</div>
            @endif
          </form>
        @else
          <a href="{{ route('login') }}" class="pa-btn pa-btn-block pa-btn-lg">Log in to confirm this ride</a>
        @endauth
        <script>
          (function () {
            var options = document.querySelectorAll('[data-vehicle-type]');
            options.forEach(function (btn) {
              btn.addEventListener('click', function () {
                options.forEach(function (o) {
                  o.style.borderColor = o === btn ? 'var(--pa-ink)' : 'var(--pa-line)';
                  o.style.background = o === btn ? 'var(--pa-beacon-wash)' : '#fff';
                  var bd = document.getElementById(o.dataset.breakdown);
                  if (bd) bd.style.display = o === btn ? '' : 'none';
                });
                var vt = document.getElementById('confirm-vehicle-type');
                if (vt) {
                  vt.value = btn.dataset.vehicleType;
                  document.getElementById('confirm-quote-token').value = btn.dataset.token;
                  document.getElementById('confirm-ride-btn').textContent = 'Confirm · R ' + Math.round(btn.dataset.fare);
                }
              });
            });
            var exp = document.getElementById('quote-expiry');
            if (exp) {
              var until = Date.parse(exp.dataset.expires);
              var tick = function () {
                var left = Math.floor((until - Date.now()) / 1000);
                if (left <= 0) { exp.textContent = 'Price hold expired — confirming will show an updated fare.'; return; }
                exp.textContent = 'Price held for ' + Math.floor(left / 60) + ':' + ('0' + (left % 60)).slice(-2);
                setTimeout(tick, 1000);
              };
              tick();
            }
          })();
        </script>
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
        <script src="{{ asset('assets/js/shuttle-map.js') }}?v=2"></script>
        <script>
          (function () {
            var cfg = @json($quoteMapCfg);
            if (!window.PAShuttle || !window.L) return;
            var m = PAShuttle.map('pa-quote-map', { center: cfg.pickup, zoom: 12 });
            PAShuttle.pin(m, 'pickup', cfg.pickup, 'Pickup');
            PAShuttle.pin(m, 'dropoff', cfg.dropoff, 'Drop-off');
            var r = PAShuttle.route(m, { color: '#D0211C', weight: 5 });
            r.set(cfg.points.length > 1 ? cfg.points : [cfg.pickup, cfg.dropoff]);
            m.fitBounds(L.latLngBounds(cfg.points.length > 1 ? cfg.points : [cfg.pickup, cfg.dropoff]), { padding: [24, 24] });
          })();
        </script>
      @endif
    </div>
  </div>
</section>
<section class="pa-section pa-section-last">
  <div class="pa-grid" style="grid-template-columns:repeat(auto-fit,minmax(230px,1fr));margin-bottom:40px">
    <div style="padding:28px 24px"><div class="pa-num" style="font-size:40px;margin-bottom:12px">9 min</div><div style="font-size:15px;font-weight:700;margin-bottom:6px">Median pickup</div><div style="font-size:13px;color:var(--pa-muted)">Across Johannesburg, Cape Town and Durban metros.</div></div><div style="padding:28px 24px"><div class="pa-num" style="font-size:40px;margin-bottom:12px">5</div><div style="font-size:15px;font-weight:700;margin-bottom:6px">Vehicle classes</div><div style="font-size:13px;color:var(--pa-muted)">Standard, comfort, van, wheelchair-accessible and stretcher.</div></div><div style="padding:28px 24px"><div class="pa-num" style="font-size:40px;margin-bottom:12px">100%</div><div style="font-size:15px;font-weight:700;margin-bottom:6px">Drivers vetted</div><div style="font-size:13px;color:var(--pa-muted)">PrDP, criminal check, first-aid certificate, annual re-screen.</div></div><div style="padding:28px 24px"><div class="pa-num" style="font-size:40px;margin-bottom:12px">R 0</div><div style="font-size:15px;font-weight:700;margin-bottom:6px">Free cancellation</div><div style="font-size:13px;color:var(--pa-muted)">Free within {{ \App\Support\ShuttleSettings::int('shuttle_free_cancel_minutes') }} min of booking, or while your driver is more than {{ \App\Support\ShuttleSettings::int('shuttle_cancel_fee_eta_minutes') }} min away.</div></div>
  </div>
  <div class="pa-card">
    <div class="pa-card-head"><h3>Recurring bookings</h3>@auth<a class="pa-btn-ghost pa-btn-sm" href="{{ route('dashboard') }}">Manage my trips</a>@endauth</div>
    <div class="pa-card-body" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:24px">
      <div><div style="font-size:15px;font-weight:700;margin-bottom:6px">Dialysis</div><div style="font-size:13px;color:var(--pa-ink-soft)">Three times a week, same driver where possible, standing return leg.</div></div><div><div style="font-size:15px;font-weight:700;margin-bottom:6px">Chemotherapy</div><div style="font-size:13px;color:var(--pa-ink-soft)">Cycle-aware scheduling with a wait-and-return option for short infusions.</div></div><div><div style="font-size:15px;font-weight:700;margin-bottom:6px">Physiotherapy blocks</div><div style="font-size:13px;color:var(--pa-ink-soft)">One booking covers all six sessions in a treatment block.</div></div><div><div style="font-size:15px;font-weight:700;margin-bottom:6px">Antenatal visits</div><div style="font-size:13px;color:var(--pa-ink-soft)">Scheduled against your clinic card, adjusted as visits get closer together.</div></div>
    </div>
  </div>
</section>
</x-layout>
