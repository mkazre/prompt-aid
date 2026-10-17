<x-layout :title="'Driver console · Prompt Aid'">
@push('meta')
<meta name="pa-asset-base" content="{{ asset('assets') }}">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
@endpush
@php
  $drvCfg = [
      'stateUrl' => route('driver.state'),
      'onlineUrl' => route('driver.online'),
      'locationUrl' => route('driver.location'),
      'offerAcceptUrl' => url('/driver/offers/__ID__/accept'),
      'offerDeclineUrl' => url('/driver/offers/__ID__/decline'),
      'claimUrl' => url('/driver/rides/__ID__/claim'),
      'statusUrl' => url('/driver/rides/__ID__/status'),
      'cancelUrl' => url('/driver/rides/__ID__/cancel'),
      'noShowUrl' => url('/driver/rides/__ID__/no-show'),
      'rateUrl' => url('/driver/rides/__ID__/rate'),
      'reasons' => array_values($cancelReasons),
      'pinRequired' => $pinRequired,
      'center' => [$driver->current_lat !== null ? (float) $driver->current_lat : -26.2041, $driver->current_lng !== null ? (float) $driver->current_lng : 28.0473],
  ];
@endphp

<div class="pa-drv">
  <div class="pa-drv-top">
    <div style="min-width:0">
      <div style="font-size:18px;font-weight:900">{{ $driver->user?->name }}</div>
      <div class="pa-sh-sub" style="margin:0">{{ trim($driver->vehicle_color.' '.$driver->vehicle_make.' '.$driver->vehicle_model) }} · {{ $driver->vehicle_plate_no }} · {{ str_replace('_', ' ', (string) $driver->vehicle_type) }}</div>
    </div>
    <button type="button" class="pa-drv-switch" id="drv-online" aria-pressed="false"><span class="knob"></span><span id="drv-online-label">Offline</span></button>
  </div>

  {{-- NOW is the switch above; LATER / TOMORROW windows and your max pickup distance here. --}}
  <div style="display:flex;justify-content:flex-end;margin:-4px 0 8px">@include('dispatch.availability-toggle')</div>

  <div id="drv-alert" class="pa-note-stop" hidden style="margin-bottom:10px"></div>
  <div id="drv-geo" class="pa-sh-sub" style="margin:0 0 8px">Location sharing is off.</div>
  <div class="pa-sh-test" id="drv-test" hidden style="margin-bottom:8px">TEST simulation is driving this trip — your phone's GPS is ignored</div>

  <div class="pa-drv-map" id="drv-map" role="region" aria-label="Driver map"></div>

  <div class="pa-drv-stats">
    <div><div class="k">Fares today</div><div class="v" id="drv-earn">R 0</div></div>
    <div><div class="k">Trips today</div><div class="v" id="drv-trips">0</div></div>
    <div><div class="k">Rating</div><div class="v" id="drv-rating">—</div></div>
  </div>

  {{-- Incoming offer: shown by the global alert modal (dispatch/provider-alerts, swipe right to accept).
       This legacy card is kept hidden as a fallback only. --}}
  <div class="pa-drv-offer" id="drv-offer" hidden data-legacy="1">
    <div class="pa-spread" style="margin-bottom:8px">
      <div class="pa-label" style="margin:0">New trip request</div>
      <strong id="drv-offer-secs" style="font-size:20px">0s</strong>
    </div>
    <div class="pa-sh-progress" style="margin-bottom:12px"><i id="drv-offer-bar" style="transition:width .25s linear"></i></div>
    <div class="pa-spread" style="margin-bottom:10px">
      <div><div class="pa-sh-eta" id="drv-offer-fare">R 0</div><div class="pa-sh-sub" id="drv-offer-meta"></div></div>
      <span class="pa-badge is-ink" id="drv-offer-type"></span>
    </div>
    <div class="pa-sh-addr" style="margin-bottom:12px">
      <span class="dot"></span><div id="drv-offer-pickup"></div>
      <span class="dot to"></span><div id="drv-offer-dropoff"></div>
    </div>
    <div class="pa-drv-grid2">
      <button type="button" class="pa-btn-ghost pa-drv-big" id="drv-offer-decline">Decline</button>
      <button type="button" class="pa-btn pa-drv-big" id="drv-offer-accept">Accept</button>
    </div>
  </div>

  {{-- Active trip --}}
  <div class="pa-sh-card" id="drv-ride" hidden style="margin:12px 0">
    <div class="pa-spread" style="margin-bottom:6px">
      <h3 id="drv-ride-status" style="font-size:20px">—</h3>
      <span class="pa-badge" id="drv-ride-ref"></span>
    </div>
    <div class="pa-sh-sub" id="drv-ride-eta" style="margin-bottom:10px"></div>
    <div class="pa-spread" style="margin-bottom:10px">
      <div><div class="pa-label" style="margin:0">Patient</div><div style="font-weight:900;font-size:16px" id="drv-ride-patient"></div></div>
      <a class="pa-btn-ghost pa-btn-sm" id="drv-ride-call" href="#" hidden>Call patient</a>
    </div>
    {{-- Rider profile (Bolt/Uber style): photo, rating, verified badges, needs, notes, booked-by, emergency contact. --}}
    <div id="drv-rider" style="margin-bottom:10px"></div>
    <div class="pa-sh-addr" style="margin-bottom:12px">
      <span class="dot"></span><div id="drv-ride-pickup"></div>
      <span class="dot to"></span><div id="drv-ride-dropoff"></div>
    </div>
    <div class="pa-drv-grid2" style="margin-bottom:10px">
      <a class="pa-btn-ghost" id="drv-nav-google" href="#" target="_blank" rel="noopener">Navigate (Google)</a>
      <a class="pa-btn-ghost" id="drv-nav-waze" href="#" target="_blank" rel="noopener">Navigate (Waze)</a>
    </div>
    <div id="drv-wait" class="pa-note" hidden style="margin-bottom:10px"></div>
    <div id="drv-pin-wrap" hidden style="margin-bottom:10px">
      <label class="pa-label" for="drv-pin">Patient's 4-digit trip PIN</label>
      <input class="pa-field pa-drv-pin" id="drv-pin" inputmode="numeric" pattern="[0-9]*" maxlength="4" autocomplete="off">
    </div>
    <button type="button" class="pa-btn pa-drv-big" id="drv-ride-action" style="margin-bottom:8px"></button>
    {{-- Trip audio recording (safety): mounted per ride by shuttle-driver.js. --}}
    <div id="drv-recorder-slot" style="margin:10px 0"></div>
    <div class="pa-drv-grid2">
      <button type="button" class="pa-btn-ghost" id="drv-noshow" hidden>No-show</button>
      <button type="button" class="pa-btn-ghost" id="drv-cancel">Cancel trip</button>
    </div>
  </div>

  {{-- Last trip --}}
  <div class="pa-sh-card" id="drv-last" hidden style="margin:12px 0">
    <div class="pa-label">Last trip</div>
    <div id="drv-last-summary" style="font-size:14px;margin-bottom:8px"></div>
    <div id="drv-last-rate">
      <div class="pa-sh-sub" style="margin-bottom:6px">Rate the patient</div>
      <div class="pa-sh-stars" id="drv-stars">
        @for ($i = 1; $i <= 5; $i++)<button type="button" data-v="{{ $i }}" aria-label="{{ $i }} star{{ $i > 1 ? 's' : '' }}">★</button>@endfor
      </div>
    </div>
  </div>

  <div id="drv-idle" class="pa-note" style="margin:12px 0">Go online to start receiving trip requests.</div>

  {{-- Open queue --}}
  <div id="drv-queue-wrap" hidden>
    <div class="pa-label" style="margin-top:14px">Open requests</div>
    <div id="drv-queue"></div>
  </div>
</div>

<div class="pa-sh-modal" id="drv-cancel-modal" hidden role="dialog" aria-modal="true" aria-labelledby="drv-cancel-title">
  <div class="box">
    <h3 id="drv-cancel-title" style="margin-bottom:8px">Cancel this trip?</h3>
    <p class="pa-sh-sub">The patient is not charged and the trip is passed to the next driver. Driver cancellations lower your reliability score.</p>
    <div id="drv-cancel-reasons"></div>
    <div class="pa-drv-grid2" style="margin-top:12px">
      <button type="button" class="pa-btn-ghost" data-sh-close>Keep trip</button>
      <button type="button" class="pa-btn" id="drv-cancel-confirm" disabled>Cancel trip</button>
    </div>
  </div>
</div>

<div class="pa-sh-toast" id="sh-toast" hidden></div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script src="{{ asset('assets/js/shuttle-map.js') }}?v=2"></script>
<script>window.PA_DRIVER = @json($drvCfg);</script>
<script src="{{ asset('assets/js/shuttle-driver.js') }}?v=3"></script>
<script src="{{ asset('assets/js/trip-recorder.js') }}" defer></script>
@include('dispatch.provider-alerts', ['context' => 'driver'])
</x-layout>
