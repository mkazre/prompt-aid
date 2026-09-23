<x-layout title="Medical shuttle · Prompt Aid">
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
        <div class="pa-formrow"><label class="pa-label">Pick up</label><input class="pa-field" name="pickup_address" required value="{{ old('pickup_address', $pickup_address ?? '') }}" placeholder="Home address" /></div>
        <div class="pa-formrow"><label class="pa-label">Drop off</label><input class="pa-field" name="dropoff_address" required value="{{ old('dropoff_address', $dropoff_address ?? '') }}" placeholder="Clinic, pharmacy or lab" /></div>
        @error('pickup_address')<div style="color:var(--pa-signal);font-size:12px;margin-bottom:10px">{{ $message }}</div>@enderror
        <button type="submit" class="pa-btn pa-btn-block" style="margin-bottom:18px">Get fare estimate</button>
      </form>

      @if ($quotes)
        <div style="display:grid;gap:2px;margin-bottom:18px" data-pa-tabs>
          @foreach ($quotes as $i => $q)
            @php
              $labels = ['sedan' => ['Standard', 'Sedan, 1–3 seats'], 'suv' => ['Comfort', 'SUV, extra legroom'], 'wheelchair_accessible' => ['Wheelchair', 'Ramp and restraints'], 'stretcher' => ['Stretcher', 'Non-emergency transfer'], 'van' => ['Van', 'Larger group transfer']];
              $label = $labels[$q['vehicle_type']] ?? [ucfirst($q['vehicle_type']), ''];
            @endphp
            <button type="button" class="pa-tab pa-spread {{ $i === 0 ? 'is-on' : '' }}" data-pa-target="vt-{{ $q['vehicle_type'] }}" data-vehicle-type="{{ $q['vehicle_type'] }}" data-fare="{{ $q['fare'] }}"
              style="border:1px solid {{ $i === 0 ? 'var(--pa-ink)' : 'var(--pa-line)' }};background:{{ $i === 0 ? 'var(--pa-beacon-wash)' : '#fff' }};padding:13px 14px;text-align:left;width:100%">
              <span><span style="display:block;font-size:14px;font-weight:700">{{ $label[0] }}</span><span style="display:block;font-size:12px;color:var(--pa-muted)">{{ $label[1] }}</span></span>
              <span style="text-align:right"><span class="pa-num" style="display:block;font-size:18px">R {{ number_format($q['fare'], 0) }}</span><span style="display:block;font-size:11px;color:var(--pa-muted)">{{ $q['eta_minutes'] }} min away</span></span>
            </button>
          @endforeach
        </div>

        @auth
          <form method="POST" action="{{ route('rides.store') }}" id="confirm-ride-form">
            @csrf
            <input type="hidden" name="pickup_address" value="{{ $pickup_address }}">
            <input type="hidden" name="pickup_lat" value="{{ $pickup_lat }}">
            <input type="hidden" name="pickup_lng" value="{{ $pickup_lng }}">
            <input type="hidden" name="dropoff_address" value="{{ $dropoff_address }}">
            <input type="hidden" name="dropoff_lat" value="{{ $dropoff_lat }}">
            <input type="hidden" name="dropoff_lng" value="{{ $dropoff_lng }}">
            <input type="hidden" name="vehicle_type" id="confirm-vehicle-type" value="{{ $quotes[0]['vehicle_type'] }}">
            <button type="submit" class="pa-btn pa-btn-block pa-btn-lg" id="confirm-ride-btn">Confirm · R {{ number_format($quotes[0]['fare'], 0) }}</button>
          </form>
          <script>
            (function () {
              var form = document.getElementById('confirm-ride-form');
              if (!form) return;
              document.querySelectorAll('[data-vehicle-type]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                  document.getElementById('confirm-vehicle-type').value = btn.dataset.vehicleType;
                  document.getElementById('confirm-ride-btn').textContent = 'Confirm · R ' + Math.round(btn.dataset.fare);
                });
              });
            })();
          </script>
        @else
          <a href="{{ route('login') }}" class="pa-btn pa-btn-block pa-btn-lg">Log in to confirm this ride</a>
        @endauth
      @endif
    </div>
  </div>
</section>
<section class="pa-section pa-section-last">
  <div class="pa-grid" style="grid-template-columns:repeat(auto-fit,minmax(230px,1fr));margin-bottom:40px">
    <div style="padding:28px 24px"><div class="pa-num" style="font-size:40px;margin-bottom:12px">9 min</div><div style="font-size:15px;font-weight:700;margin-bottom:6px">Median pickup</div><div style="font-size:13px;color:var(--pa-muted)">Across Johannesburg, Cape Town and Durban metros.</div></div><div style="padding:28px 24px"><div class="pa-num" style="font-size:40px;margin-bottom:12px">5</div><div style="font-size:15px;font-weight:700;margin-bottom:6px">Vehicle classes</div><div style="font-size:13px;color:var(--pa-muted)">Standard, comfort, van, wheelchair-accessible and stretcher.</div></div><div style="padding:28px 24px"><div class="pa-num" style="font-size:40px;margin-bottom:12px">100%</div><div style="font-size:15px;font-weight:700;margin-bottom:6px">Drivers vetted</div><div style="font-size:13px;color:var(--pa-muted)">PrDP, criminal check, first-aid certificate, annual re-screen.</div></div><div style="padding:28px 24px"><div class="pa-num" style="font-size:40px;margin-bottom:12px">R 0</div><div style="font-size:15px;font-weight:700;margin-bottom:6px">Cancellation, 10 min</div><div style="font-size:13px;color:var(--pa-muted)">Cancel free up to ten minutes after booking.</div></div>
  </div>
  <div class="pa-card">
    <div class="pa-card-head"><h3>Recurring bookings</h3>@auth<a class="pa-btn-ghost pa-btn-sm" href="{{ route('dashboard') }}">Manage my trips</a>@endauth</div>
    <div class="pa-card-body" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:24px">
      <div><div style="font-size:15px;font-weight:700;margin-bottom:6px">Dialysis</div><div style="font-size:13px;color:var(--pa-ink-soft)">Three times a week, same driver where possible, standing return leg.</div></div><div><div style="font-size:15px;font-weight:700;margin-bottom:6px">Chemotherapy</div><div style="font-size:13px;color:var(--pa-ink-soft)">Cycle-aware scheduling with a wait-and-return option for short infusions.</div></div><div><div style="font-size:15px;font-weight:700;margin-bottom:6px">Physiotherapy blocks</div><div style="font-size:13px;color:var(--pa-ink-soft)">One booking covers all six sessions in a treatment block.</div></div><div><div style="font-size:15px;font-weight:700;margin-bottom:6px">Antenatal visits</div><div style="font-size:13px;color:var(--pa-ink-soft)">Scheduled against your clinic card, adjusted as visits get closer together.</div></div>
    </div>
  </div>
</section>
</x-layout>
