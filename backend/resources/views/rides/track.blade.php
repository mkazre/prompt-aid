<x-layout :title="'Your shuttle trip · Prompt Aid'">
@push('meta')
<meta name="pa-asset-base" content="{{ asset('assets') }}">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
@endpush
@php
  $tripCfg = [
      'statusUrl' => route('rides.status', $ride),
      'cancelPreviewUrl' => route('rides.cancel-preview', $ride),
      'cancelUrl' => route('rides.cancel', $ride),
      'retryUrl' => route('rides.retry', $ride),
      'sosUrl' => route('rides.sos', $ride),
      'rateUrl' => route('rides.rate', $ride),
      'initial' => $initial,
      'reasons' => array_values($cancelReasons),
  ];
  $contactRows = $emergencyContacts->map(fn ($c) => ['label' => $c->label, 'phone' => $c->phone, 'tel' => $c->tel_url, 'primary' => (bool) $c->is_primary])->values()->all();
  $isEnded = ! $ride->isActive();
  $supportPhone = \App\Models\Setting::get('support_phone', '0800776678');
@endphp

<div class="pa-container" style="padding-top:20px;padding-bottom:64px">
  <div class="pa-crumb" style="margin-bottom:12px"><a href="{{ url('/') }}">Home</a> <span style="color:#CFC8B8">/</span> <a href="{{ route('shuttle') }}">Shuttle</a> <span style="color:#CFC8B8">/</span> {{ $ride->ride_ref }}</div>

  @if (session('success'))<div class="pa-sh-flash ok">{{ session('success') }}</div>@endif
  @if (session('error'))<div class="pa-sh-flash err">{{ session('error') }}</div>@endif

  <div class="pa-sh">
    <div class="pa-sh-mapwrap"><div id="pa-sh-map" class="pa-sh-map" role="region" aria-label="Live trip map"></div></div>

    <div class="pa-sh-panel">
      <div class="pa-sh-test" id="sh-test" hidden>TEST simulation running — the car is simulated</div>

      {{-- Status --}}
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
          <div class="pa-sh-sub" id="sh-search-text">Contacting nearby drivers…</div>
          <div class="pa-sh-progress" style="margin-top:10px"><i id="sh-search-bar"></i></div>
        </div>
        <div id="sh-nodrivers" hidden style="margin-top:14px">
          <div class="pa-note" style="margin-bottom:12px">No driver accepted your request in time. Drivers may be busy nearby — you can search again, or cancel at no charge.</div>
          <div class="pa-drv-grid2">
            <button type="button" class="pa-btn" id="sh-retry">Search again</button>
            <button type="button" class="pa-btn-ghost" data-sh-cancel>Cancel request</button>
          </div>
        </div>
        <div id="sh-waiting" class="pa-note" hidden style="margin-top:12px"></div>
      </div>

      {{-- Driver --}}
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
        <div class="pa-drv-grid2" style="margin-top:14px">
          <a class="pa-btn-ghost" id="sh-d-call" href="#" hidden>Call driver</a>
          <a class="pa-btn-ghost" href="tel:{{ preg_replace('/[^0-9+]/', '', (string) $supportPhone) }}">Call support</a>
        </div>
      </div>

      {{-- Trip PIN --}}
      <div class="pa-sh-card" id="sh-pin-card" hidden>
        <div class="pa-spread">
          <div>
            <div class="pa-label" style="margin-bottom:4px">Trip PIN</div>
            <div class="pa-sh-sub" style="margin:0">Give this to your driver when you get in. The trip can't start without it.</div>
          </div>
          <div class="pa-sh-pin" id="sh-pin"></div>
        </div>
      </div>

      {{-- Actions --}}
      <div class="pa-sh-actions" id="sh-actions" @if ($isEnded) hidden @endif>
        <button type="button" class="pa-btn-ghost" id="sh-share">Share trip</button>
        <button type="button" class="pa-btn pa-sh-sos" id="sh-sos">SOS</button>
        <button type="button" class="pa-btn-ghost" data-sh-cancel id="sh-cancel-btn">Cancel trip</button>
      </div>

      @if ($ride->status === 'completed')
        @include('rides.partials.receipt', ['ride' => $ride])

        @if (! $ride->review)
          <div class="pa-sh-card" id="sh-rate-card">
            <div class="pa-label">Rate your driver</div>
            <div class="pa-sh-stars" id="sh-stars" role="radiogroup" aria-label="Rating">
              @for ($i = 1; $i <= 5; $i++)<button type="button" data-v="{{ $i }}" aria-label="{{ $i }} star{{ $i > 1 ? 's' : '' }}">★</button>@endfor
            </div>
            <textarea class="pa-field" id="sh-rate-comment" style="min-height:70px;margin-top:10px" maxlength="1000" placeholder="Anything we should know? (optional)"></textarea>
            <button type="button" class="pa-btn pa-btn-block" id="sh-rate-send" style="margin-top:10px" disabled>Submit rating</button>
          </div>
        @else
          <div class="pa-sh-card"><div class="pa-label">Your rating</div><div style="font-size:22px;color:var(--pa-beacon)">{{ str_repeat('★', (int) $ride->review->rating) }}<span style="color:#CFC8B8">{{ str_repeat('★', 5 - (int) $ride->review->rating) }}</span></div></div>
        @endif
      @endif

      @if ($ride->status === 'cancelled')
        <div class="pa-sh-card">
          <div class="pa-label">{{ $ride->cancelled_by === 'no_show' ? 'No-show' : 'Cancelled' }}</div>
          <div style="font-size:14px">{{ $ride->cancel_reason ?: 'This trip was cancelled.' }}</div>
          <div class="pa-sh-sub">{{ $ride->cancelled_at?->timezone(\App\Support\ShuttleSettings::str('shuttle_pricing_timezone') ?: 'Africa/Johannesburg')->format('D d M, H:i') }} · by {{ str_replace('_', ' ', (string) ($ride->cancelled_by ?? 'system')) }}</div>
          @if ($ride->cancellation_fee > 0)
            <div class="pa-sh-row total"><span>{{ $ride->cancelled_by === 'no_show' ? 'No-show fee' : 'Cancellation fee' }}</span><span>R {{ number_format((float) $ride->cancellation_fee, 2) }}</span></div>
            <div class="pa-sh-sub">Status: {{ ucfirst((string) $ride->cancellation_fee_status) }}</div>
          @else
            <div class="pa-sh-sub" style="margin-top:6px">No fee was charged.</div>
          @endif
        </div>
      @endif

      {{-- Route + fare --}}
      <div class="pa-sh-card">
        <div class="pa-sh-addr">
          <span class="dot"></span><div><span class="pa-muted" style="font-size:12px">Pickup</span><br>{{ $ride->pickup_address }}</div>
          <span class="dot to"></span><div><span class="pa-muted" style="font-size:12px">Drop-off</span><br>{{ $ride->dropoff_address }}</div>
        </div>
        <div class="pa-sh-row" style="margin-top:10px"><span class="k">Trip</span><span id="sh-trip">{{ $initial['trip']['distance_km'] !== null ? number_format($initial['trip']['distance_km'], 1).' km' : '' }}{{ $initial['trip']['duration_min'] ? ' · ~'.$initial['trip']['duration_min'].' min' : '' }}</span></div>
        @if ($ride->status !== 'completed')
          <div class="pa-sh-row"><span class="k">Upfront price</span><strong>R {{ number_format((float) ($ride->quoted_fare ?? $ride->fare_estimate), 2) }}</strong></div>
          @if ((float) $ride->surge_multiplier > 1)
            <div style="margin-top:6px"><span class="pa-sh-surge">Dynamic pricing ×{{ number_format((float) $ride->surge_multiplier, 2) }} applied at booking</span></div>
          @endif
        @endif
      </div>

      {{-- Timeline --}}
      <div class="pa-sh-card">
        <div class="pa-label">Trip timeline</div>
        <div class="pa-steps" id="sh-timeline"></div>
      </div>

      @if ($ride->status === 'completed' && ! $ride->is_return && ! $ride->returnLeg)
        <div class="pa-sh-card">
          <div style="font-size:15px;font-weight:700;margin-bottom:4px">Need a ride back?</div>
          <p class="pa-muted" style="font-size:13px;margin-bottom:14px">Request the return leg — same route, reversed.</p>
          <form method="POST" action="{{ route('rides.return', $ride) }}">
            @csrf
            <button type="submit" class="pa-btn pa-btn-block">Request return ride</button>
          </form>
        </div>
      @endif

      <a class="pa-btn-ghost pa-btn-block" href="{{ route('account.rides') }}">All my trips</a>
    </div>
  </div>
</div>

{{-- Cancel modal --}}
<div class="pa-sh-modal" id="sh-cancel-modal" hidden role="dialog" aria-modal="true" aria-labelledby="sh-cancel-title">
  <div class="box">
    <h3 id="sh-cancel-title" style="margin-bottom:8px">Cancel this trip?</h3>
    <div id="sh-cancel-fee" class="pa-note" style="margin-bottom:14px">Checking the cancellation fee…</div>
    <div class="pa-label">Reason</div>
    <div id="sh-cancel-reasons"></div>
    <div class="pa-drv-grid2" style="margin-top:12px">
      <button type="button" class="pa-btn-ghost" data-sh-close>Keep my trip</button>
      <button type="button" class="pa-btn" id="sh-cancel-confirm" disabled>Cancel trip</button>
    </div>
  </div>
</div>

{{-- SOS modal --}}
<div class="pa-sh-modal" id="sh-sos-modal" hidden role="dialog" aria-modal="true" aria-labelledby="sh-sos-title">
  <div class="box">
    <h3 id="sh-sos-title" style="margin-bottom:8px;color:var(--pa-signal)">Emergency help</h3>
    <p style="font-size:14px">If you are in danger, call an emergency number now.</p>
    <div style="display:grid;gap:8px;margin-bottom:14px">
      @forelse ($contactRows as $c)
        <a class="{{ $c['primary'] ? 'pa-btn pa-sh-sos' : 'pa-btn-ghost' }} pa-btn-block" href="{{ $c['tel'] }}">{{ $c['label'] }} · {{ $c['phone'] }}</a>
      @empty
        <a class="pa-btn pa-sh-sos pa-btn-block" href="tel:112">Emergency · 112</a>
      @endforelse
    </div>
    <button type="button" class="pa-btn-quiet pa-btn-block" id="sh-sos-alert">Alert the Prompt Aid support team</button>
    <div class="pa-sh-sub" id="sh-sos-result" style="margin-top:8px">Sends your trip, driver and current location to our support team.</div>
    <button type="button" class="pa-btn-ghost pa-btn-block" data-sh-close style="margin-top:12px">Close</button>
  </div>
</div>

<div class="pa-sh-toast" id="sh-toast" hidden></div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script src="{{ asset('assets/js/shuttle-map.js') }}?v=2"></script>
<script>window.PA_TRIP = @json($tripCfg);</script>
<script src="{{ asset('assets/js/shuttle-track.js') }}?v=2"></script>
</x-layout>
