<x-layout :title="'Dr '.$doctor->user->name.' · Prompt Aid'">
@php $clinic = $doctor->clinics->first(); @endphp
<div class="pa-pagehead"><div class="inner">
  <div class="pa-crumb"><a href="{{ url('/') }}">Home</a> <span style="color:#CFC8B8">/</span> <a href="{{ route('doctors.index') }}">Find care</a> <span style="color:#CFC8B8">/</span> Dr {{ $doctor->user->name }}</div>
  <div style="display:grid;grid-template-columns:160px minmax(0,1fr);gap:28px;padding-bottom:28px">
    <div class="pa-avatar" style="width:160px;height:160px;font-size:40px">{{ \Illuminate\Support\Str::of($doctor->user->name)->explode(' ')->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('') }}</div>
    <div>
      <div class="pa-row" style="flex-wrap:wrap;margin-bottom:12px">
        {{-- Live status — mirrors what the doctor has set on their dashboard. --}}
        <span class="pa-status is-{{ $doctor->liveStatus() }}">{{ $doctor->liveStatusLabel() }}</span>
        <span style="font-size:13px;color:var(--pa-ink-soft)">{{ $doctor->liveStatusDescription() }}</span>
        @if ($doctor->liveStatus() === 'green')<a href="{{ route('instant-consult') }}" style="font-size:13px;font-weight:700">See a doctor now →</a>@endif
      </div>
      <h1 style="margin-bottom:8px">Dr {{ $doctor->user->name }}</h1>
      <p style="font-size:17px;color:var(--pa-ink-soft);margin-bottom:18px">{{ $doctor->specialization }}@if($doctor->qualification) · {{ $doctor->qualification }}@endif
        @if($doctor->registration_no) · HPCSA {{ $doctor->registration_no }}@endif</p>
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

    @php $hours = $doctor->operatingHours(); @endphp
    <div class="pa-card" style="margin-bottom:24px">
      <div class="pa-card-head"><h3>Operating hours</h3></div>
      @if ($hours)
        @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $day)
          <div class="pa-spread" style="padding:12px 26px;border-bottom:1px solid var(--pa-line-soft);font-size:14px{{ $day === now()->format('D') ? ';font-weight:900' : '' }}">
            <span>{{ $day }}@if($day === now()->format('D')) · today @endif</span>
            <span class="pa-num" style="{{ isset($hours[$day]) ? '' : 'color:var(--pa-muted)' }}">{{ $hours[$day] ?? 'Closed' }}</span>
          </div>
        @endforeach
      @else
        <div style="padding:16px 26px;font-size:14px;color:var(--pa-muted)">Operating hours have not been published yet. Contact the practice to book.</div>
      @endif
    </div>

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

    <div class="pa-card" style="margin-bottom:24px">
      <div class="pa-card-head"><h3>Contact details</h3></div>
      <div style="padding:16px 26px;font-size:14px;line-height:1.7;color:var(--pa-ink-soft)">
        @forelse ($doctor->clinics as $c)
          <div style="{{ $loop->last ? '' : 'margin-bottom:14px;padding-bottom:14px;border-bottom:1px solid var(--pa-line-soft)' }}">
            <div style="font-weight:700;color:var(--pa-ink)">{{ $c->name }}</div>
            <div>{{ $c->address }}@if($c->city), {{ $c->city }}@endif</div>
            @if ($c->phone)<div>Phone: <a href="tel:{{ preg_replace('/[^0-9+]/', '', $c->phone) }}">{{ $c->phone }}</a></div>@endif
            @if ($c->email)<div>Email: <a href="mailto:{{ $c->email }}">{{ $c->email }}</a></div>@endif
          </div>
        @empty
          <div>Book through PromptAid: <a href="tel:{{ preg_replace('/[^0-9+]/', '', \App\Models\Setting::get('support_phone', '0800 776 678')) }}">{{ \App\Models\Setting::get('support_phone', '0800 776 678') }}</a> · <a href="mailto:{{ \App\Models\Setting::get('support_email', 'help@promptaid.health') }}">{{ \App\Models\Setting::get('support_email', 'help@promptaid.health') }}</a></div>
        @endforelse
      </div>
    </div>

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
          <div style="margin-top:14px"><a class="pa-btn-ghost pa-btn-sm" href="{{ $clinic->shuttleUrl() }}"><span class="pa-tick-beacon" style="width:6px;height:6px"></span>Book a shuttle here</a></div>
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
              @if ($doctor->services->isNotEmpty())
                <div class="pa-formrow"><label class="pa-label" for="service_id">Service</label>
                  <select class="pa-field" name="service_id" id="service_id">
                    <option value="">Consultation · R {{ number_format($doctor->consultation_fee, 0) }}</option>
                    @foreach ($doctor->services as $sv)<option value="{{ $sv->id }}">{{ $sv->name }} · R {{ number_format($sv->price, 0) }}</option>@endforeach
                  </select>
                </div>
              @endif
              <div class="pa-tabs" style="display:grid;grid-template-columns:1fr 1fr;margin-bottom:14px">
                <label class="pa-tab is-on" style="text-align:center;cursor:pointer"><input type="radio" name="visit_type" value="clinic" checked style="margin-right:6px">In person</label>
                @if ($doctor->offers_video)<label class="pa-tab" style="text-align:center;cursor:pointer"><input type="radio" name="visit_type" value="telemed" style="margin-right:6px">Video</label>@endif
              </div>
              {{-- Red = not available today, so the calendar starts tomorrow. --}}
              <input type="hidden" name="date" id="date" value="{{ old('date', ($doctor->liveStatus() === 'red' ? now()->addDay() : now())->toDateString()) }}">
              <input type="hidden" name="start_time" id="start_time">
              {{-- Month + "Today" + day strip + session-grouped slots (public/assets/js/promptaid.js, data-pa-slot-picker). --}}
              <div data-pa-slot-picker
                   data-slots-url="{{ route('doctors.slots', $doctor) }}"
                   data-clinic-select="#clinic_id"
                   data-date-input="#date"
                   data-time-input="#start_time"
                   data-submit="#confirm-booking-btn"
                   style="margin-bottom:16px">
                <span style="font-size:12px;color:var(--pa-muted)">Loading…</span>
              </div>
              <div class="pa-formrow"><label class="pa-label" for="reason">Notes for the doctor (optional)</label>
                <textarea class="pa-field" name="reason" id="reason" rows="2" maxlength="1000" placeholder="Briefly describe your symptoms">{{ old('reason') }}</textarea>
              </div>
              <a class="pa-check" href="{{ $clinic->shuttleUrl() }}" style="background:var(--pa-beacon-wash);border:1px solid var(--pa-beacon-line);padding:14px;margin-bottom:18px;color:inherit;text-decoration:none;display:flex;gap:10px">
                <span class="box"></span>
                <span style="font-size:13px;line-height:1.45;color:var(--pa-ink-soft)"><strong style="color:var(--pa-ink)">Add a shuttle</strong><br />Fetch from home, return after — get a fare quote.</span>
              </a>
              <button type="submit" class="pa-btn pa-btn-block pa-btn-lg" id="confirm-booking-btn" disabled>Select a time</button>
              <div style="font-size:12px;color:var(--pa-muted);margin-top:12px;text-align:center">Free cancellation up to 4 hours before</div>
            </form>
            <script>
              // Highlight the chosen visit type tab (slots are handled by data-pa-slot-picker).
              document.querySelectorAll('#booking-form input[name="visit_type"]').forEach(function (r) {
                r.addEventListener('change', function () {
                  document.querySelectorAll('#booking-form input[name="visit_type"]').forEach(function (x) { x.closest('label').classList.toggle('is-on', x.checked); });
                });
              });
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
