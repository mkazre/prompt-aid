<x-layout :title="'Dr '.$doctor->user->name.' · Prompt Aid'">
@php $clinic = $doctor->clinics->first(); @endphp
<div class="pa-pagehead"><div class="inner">
  <div class="pa-crumb"><a href="{{ url('/') }}">Home</a> <span style="color:#CFC8B8">/</span> <a href="{{ route('doctors.index') }}">Find care</a> <span style="color:#CFC8B8">/</span> Dr {{ $doctor->user->name }}</div>
  <div style="display:grid;grid-template-columns:160px minmax(0,1fr);gap:28px;padding-bottom:28px">
    <div class="pa-avatar" style="width:160px;height:160px;font-size:40px">{{ \Illuminate\Support\Str::of($doctor->user->name)->explode(' ')->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('') }}</div>
    <div>
      <div class="pa-row" style="flex-wrap:wrap;margin-bottom:12px">
        <span class="pa-badge {{ $doctor->isAvailableForBooking() ? 'is-go' : '' }}">{{ $doctor->isAvailableForBooking() ? 'Accepting patients' : 'Not accepting patients' }}</span>
      </div>
      <h1 style="margin-bottom:8px">Dr {{ $doctor->user->name }}</h1>
      <p style="font-size:17px;color:var(--pa-ink-soft);margin-bottom:18px">{{ $doctor->specialization }}@if($doctor->qualification) · {{ $doctor->qualification }}@endif@if($doctor->registration_no) · HPCSA {{ $doctor->registration_no }}@endif</p>
      <div style="display:flex;gap:32px;flex-wrap:wrap;padding-top:18px;border-top:1px solid var(--pa-line)">
        <div><div class="pa-label" style="margin-bottom:4px">Consult</div><div class="pa-num" style="font-size:22px">R {{ number_format($doctor->consultation_fee, 0) }}</div></div>
        @if ($doctor->rating_count)
          <div><div class="pa-label" style="margin-bottom:4px">Rating</div><div class="pa-num" style="font-size:22px">{{ number_format($doctor->rating_avg, 1) }} <span style="font-size:14px;color:var(--pa-muted);font-weight:400">/ {{ $doctor->rating_count }}</span></div></div>
        @endif
        @if ($doctor->experience_years)
          <div><div class="pa-label" style="margin-bottom:4px">Experience</div><div class="pa-num" style="font-size:22px">{{ $doctor->experience_years }} yrs</div></div>
        @endif
      </div>
    </div>
  </div>
</div></div>

<div class="pa-container" style="padding-top:32px;padding-bottom:80px;display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:32px;align-items:start">
  <div>
    @if ($doctor->bio)
      <div class="pa-card pa-card-pad" style="margin-bottom:24px">
        <h3 style="margin-bottom:12px">About</h3>
        <p style="font-size:15px;line-height:1.65;color:var(--pa-ink-soft);margin:0">{{ $doctor->bio }}</p>
      </div>
    @endif

    @if ($doctor->services->isNotEmpty())
      <div class="pa-card" style="margin-bottom:24px">
        <div class="pa-card-head"><h3>Services &amp; fees</h3></div>
        @foreach ($doctor->services as $service)
          <div class="pa-spread" style="padding:16px 26px;border-bottom:1px solid var(--pa-line-soft)">
            <div><div style="font-size:15px;font-weight:700">{{ $service->name }}</div>@if($service->duration_minutes)<div style="font-size:13px;color:var(--pa-muted);margin-top:2px">{{ $service->duration_minutes }} minutes</div>@endif</div>
            <div class="pa-num" style="font-size:18px">R {{ number_format($service->price, 0) }}</div>
          </div>
        @endforeach
      </div>
    @endif

    @if ($doctor->reviews->isNotEmpty())
      <div class="pa-card" style="margin-bottom:24px">
        <div class="pa-card-head"><h3>Reviews</h3><span style="font-size:13px;color:var(--pa-muted)">Verified visits only</span></div>
        @foreach ($doctor->reviews as $review)
          <div style="padding:20px 26px;border-bottom:1px solid var(--pa-line-soft)">
            <div class="pa-spread" style="margin-bottom:8px"><div style="font-size:14px;font-weight:700">{{ $review->patient->user->name }}</div><div style="font-size:13px;color:var(--pa-signal);font-weight:900">{{ number_format($review->rating, 1) }}</div></div>
            @if ($review->comment)<div style="font-size:14px;color:var(--pa-ink-soft)">{{ $review->comment }}</div>@endif
            <div style="font-size:12px;color:var(--pa-muted);margin-top:8px">{{ $review->created_at->format('j F Y') }}</div>
          </div>
        @endforeach
      </div>
    @endif

    @if ($clinic)
      <div class="pa-card pa-card-pad">
        <h3 style="margin-bottom:14px">Where to find us</h3>
        <div style="font-size:15px;line-height:1.6;color:var(--pa-ink-soft)">{{ $clinic->name }}<br />{{ $clinic->address }}@if($clinic->city), {{ $clinic->city }}@endif
          <div style="margin-top:14px"><a class="pa-btn-ghost pa-btn-sm" href="{{ route('shuttle') }}?to={{ urlencode('Dr '.$doctor->user->name) }}"><span class="pa-tick-beacon" style="width:6px;height:6px"></span>Book a shuttle here</a></div>
        </div>
      </div>
    @endif
  </div>

  <div style="position:sticky;top:96px">
    <div class="pa-pop">
      <div style="padding:20px 22px;border-bottom:1px solid var(--pa-line)"><div class="pa-label" style="margin:0">Book an appointment</div></div>
      <div style="padding:22px">
        @auth
          @if ($doctor->isAvailableForBooking() && $doctor->clinics->isNotEmpty())
            @if ($errors->any())
              <div class="pa-note" style="margin-bottom:14px;border-color:var(--pa-signal);color:var(--pa-signal)">{{ $errors->first() }}</div>
            @endif
            <form method="POST" action="{{ route('appointments.store') }}" id="booking-form">
              @csrf
              <input type="hidden" name="doctor_profile_id" value="{{ $doctor->id }}">
              <div class="pa-formrow"><label class="pa-label">Clinic</label>
                <select class="pa-field" name="clinic_id" id="clinic_id">
                  @foreach ($doctor->clinics as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                </select>
              </div>
              <div class="pa-formrow"><label class="pa-label">Date</label>
                <input class="pa-field" type="date" name="date" id="date" min="{{ now()->toDateString() }}" value="{{ now()->addDay()->toDateString() }}">
              </div>
              <div class="pa-tabs" style="display:grid;grid-template-columns:1fr 1fr;margin-bottom:14px">
                <label class="pa-tab is-on" style="text-align:center;cursor:pointer"><input type="radio" name="visit_type" value="clinic" checked style="margin-right:6px">In person</label>
                <label class="pa-tab" style="text-align:center;cursor:pointer"><input type="radio" name="visit_type" value="telemed" style="margin-right:6px">Video</label>
              </div>
              <div class="pa-label" style="margin-bottom:10px">Available times</div>
              <div id="slot-grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin-bottom:20px">
                <span style="font-size:12px;color:var(--pa-muted)">Loading…</span>
              </div>
              <input type="hidden" name="start_time" id="start_time">
              <a class="pa-check" href="{{ route('shuttle') }}?to={{ urlencode('Dr '.$doctor->user->name) }}" style="background:var(--pa-beacon-wash);border:1px solid var(--pa-beacon-line);padding:14px;margin-bottom:18px;color:inherit;text-decoration:none;display:flex;gap:10px">
                <span class="box"></span>
                <span style="font-size:13px;line-height:1.45;color:var(--pa-ink-soft)"><strong style="color:var(--pa-ink)">Add a shuttle</strong><br />Fetch from home, return after — get a fare quote.</span>
              </a>
              <button type="submit" class="pa-btn pa-btn-block pa-btn-lg" id="confirm-booking-btn" disabled>Select a time</button>
              <div style="font-size:12px;color:var(--pa-muted);margin-top:12px;text-align:center">Free cancellation up to 4 hours before</div>
            </form>
            <script>
              (function () {
                const clinicSelect = document.getElementById('clinic_id');
                const dateInput = document.getElementById('date');
                const grid = document.getElementById('slot-grid');
                const startTime = document.getElementById('start_time');
                const btn = document.getElementById('confirm-booking-btn');

                async function loadSlots() {
                  grid.innerHTML = '<span style="font-size:12px;color:var(--pa-muted)">Loading…</span>';
                  btn.disabled = true; btn.textContent = 'Select a time';
                  startTime.value = '';
                  const res = await fetch(`{{ route('doctors.slots', $doctor) }}?clinic_id=${clinicSelect.value}&date=${dateInput.value}`);
                  const data = await res.json();
                  grid.innerHTML = '';
                  if (!data.slots.length) {
                    grid.innerHTML = '<span style="font-size:12px;color:var(--pa-muted)">No slots available this day</span>';
                    return;
                  }
                  data.slots.forEach(s => {
                    const b = document.createElement('button');
                    b.type = 'button';
                    b.textContent = s;
                    b.style.cssText = 'background:#fff;color:var(--pa-ink);border:1px solid var(--pa-line);padding:10px 4px;font-size:13px;font-weight:700;text-align:center;cursor:pointer';
                    b.addEventListener('click', () => {
                      grid.querySelectorAll('button').forEach(x => { x.style.background = '#fff'; x.style.color = 'var(--pa-ink)'; x.style.borderColor = 'var(--pa-line)'; });
                      b.style.background = 'var(--pa-ink)'; b.style.color = '#fff'; b.style.borderColor = 'var(--pa-ink)';
                      startTime.value = s;
                      btn.disabled = false; btn.textContent = 'Confirm booking';
                    });
                    grid.appendChild(b);
                  });
                }
                clinicSelect.addEventListener('change', loadSlots);
                dateInput.addEventListener('change', loadSlots);
                loadSlots();
              })();
            </script>
          @elseif (! $doctor->isAvailableForBooking())
            <p class="pa-muted" style="font-size:14px">Dr {{ $doctor->user->name }} is not accepting new bookings right now.</p>
          @else
            <p class="pa-muted" style="font-size:14px">This doctor has no clinic linked for bookings yet.</p>
          @endif
        @else
          <p class="pa-muted" style="font-size:14px;margin-bottom:14px">Log in to book an appointment.</p>
          <a class="pa-btn pa-btn-block pa-btn-lg" href="{{ route('login') }}">Log in to book</a>
        @endauth
      </div>
    </div>
  </div>
</div>
</x-layout>
