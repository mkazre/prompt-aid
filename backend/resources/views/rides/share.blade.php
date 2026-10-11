<x-layout :title="'Live shuttle trip · Prompt Aid'">
@push('meta')
<meta name="robots" content="noindex, nofollow">
<meta name="pa-asset-base" content="{{ asset('assets') }}">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
@endpush
@php
  $shareCfg = $initial ? ['statusUrl' => route('trip.status', $token), 'initial' => $initial, 'readOnly' => true] : null;
@endphp

<div class="pa-container" style="padding-top:20px;padding-bottom:64px">
  @if (! $initial)
    <div class="pa-sh-card" style="max-width:560px;margin:40px auto;text-align:center">
      <h2 style="margin-bottom:10px">This trip link is no longer active</h2>
      <p class="pa-muted">Shared trip links stop working as soon as the trip ends or is cancelled.</p>
      <a class="pa-btn" href="{{ route('shuttle') }}">About Prompt Aid shuttles</a>
    </div>
  @else
    <div class="pa-eyebrow" style="margin-bottom:10px">Shared trip · {{ $initial['patient_first_name'] }}'s shuttle</div>
    <div class="pa-sh">
      <div class="pa-sh-mapwrap"><div id="pa-sh-map" class="pa-sh-map" role="region" aria-label="Live trip map"></div></div>
      <div class="pa-sh-panel">
        <div class="pa-sh-test" id="sh-test" hidden>TEST simulation running — the car is simulated</div>
        <div class="pa-sh-card">
          <div class="pa-sh-status">
            <div>
              <h2 id="sh-status">{{ $initial['status_label'] }}</h2>
              <div class="pa-sh-sub" id="sh-sub"></div>
            </div>
            <div style="text-align:right" id="sh-eta-wrap" hidden>
              <div class="pa-sh-eta"><span id="sh-eta">—</span><small>min</small></div>
              <div class="pa-sh-sub" id="sh-eta-clock"></div>
            </div>
          </div>
          <div id="sh-searching" hidden style="margin-top:14px;text-align:center">
            <div class="pa-sh-searching"><span></span></div>
            <div class="pa-sh-sub" id="sh-search-text">Finding a driver…</div>
            <div class="pa-sh-progress" style="margin-top:10px"><i id="sh-search-bar"></i></div>
          </div>
          <div id="sh-waiting" class="pa-note" hidden style="margin-top:12px"></div>
        </div>
        <div class="pa-sh-card" id="sh-driver-card" hidden>
          <div class="pa-sh-driver">
            <div class="ph" id="sh-d-photo"></div>
            <div style="flex:1;min-width:0">
              <div class="nm" id="sh-d-name"></div>
              <div class="vh" id="sh-d-vehicle"></div>
              <div class="pa-sh-rating" id="sh-d-rating"></div>
            </div>
            <div style="text-align:right"><span class="pa-sh-plate" id="sh-d-plate"></span></div>
          </div>
        </div>
        <div class="pa-sh-sub">This read-only link was shared by the passenger and stops working when the trip ends.</div>
      </div>
    </div>
  @endif
</div>

@if ($shareCfg)
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script src="{{ asset('assets/js/shuttle-map.js') }}?v=2"></script>
<script>window.PA_TRIP = @json($shareCfg);</script>
<script src="{{ asset('assets/js/shuttle-track.js') }}?v=2"></script>
@endif
</x-layout>
