<x-layout title="Find a doctor, fill a script, get a lift · Prompt Aid">
<section class="pa-hero">
  <div class="pa-container" style="display:grid;grid-template-columns:minmax(0,1.1fr) minmax(0,.9fr);gap:56px;align-items:center">
    <div style="padding:76px 0;animation:pa-rise .5s ease both">
      <div class="pa-row" style="margin-bottom:24px"><span class="pa-tick"></span><span class="pa-eyebrow">Care, medicine and transport · South Africa</span></div>
      <h1 style="font-size:clamp(36px,4.6vw,64px);margin-bottom:22px">Find a doctor, fill a script,<br />and get a lift there.</h1>
      <p style="font-size:17px;color:var(--pa-ink-soft);max-width:540px;margin-bottom:32px">One account for clinics, pharmacies, labs and specialists — plus a medical shuttle that fetches you from home and brings you back.</p>
      <div style="display:flex;gap:28px;flex-wrap:wrap">
        <div><div class="pa-num" style="font-size:30px">{{ number_format($stats['providers']) }}</div><div style="font-size:12px;color:var(--pa-muted);margin-top:2px">providers listed</div></div>
        <div style="width:1px;background:var(--pa-line)"></div>
        <div><div class="pa-num" style="font-size:30px">11 min</div><div style="font-size:12px;color:var(--pa-muted);margin-top:2px">median shuttle pickup</div></div>
        <div style="width:1px;background:var(--pa-line)"></div>
        <div><div class="pa-num" style="font-size:30px">{{ $stats['schemes'] }}</div><div style="font-size:12px;color:var(--pa-muted);margin-top:2px">schemes billed direct</div></div>
      </div>
    </div>
    <div style="padding:40px 0">
      <div class="pa-pop" data-pa-tabs>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;border-bottom:1px solid var(--pa-line)">
          <button class="pa-tab is-on" data-pa-target="care" style="border:0;border-radius:0;padding:15px 8px">Book care</button>
          <button class="pa-tab" data-pa-target="meds" style="border:0;border-left:1px solid var(--pa-line);border-radius:0;padding:15px 8px">Order meds</button>
          <button class="pa-tab" data-pa-target="ride" style="border:0;border-left:1px solid var(--pa-line);border-radius:0;padding:15px 8px">Shuttle</button>
        </div>
        <div style="padding:26px">
          <form method="GET" action="{{ route('doctors.index') }}" data-pa-panel="care">
            <div class="pa-formrow"><label class="pa-label">Symptom, speciality or name</label><input class="pa-field" name="search" placeholder="e.g. persistent cough, cardiologist" /></div>
            <div class="pa-formrow"><label class="pa-label">Where</label><input class="pa-field" placeholder="Suburb or postal code (coming soon)" disabled /></div>
            <div class="pa-formgrid" style="margin-bottom:22px">
              <div><label class="pa-label">When</label><input class="pa-field" value="Today" disabled /></div>
              <div><label class="pa-label">Paying with</label><input class="pa-field" value="Any scheme" disabled /></div>
            </div>
            <button type="submit" class="pa-btn pa-btn-block pa-btn-lg">Search {{ number_format($stats['providers']) }} providers</button>
          </form>
          <form method="GET" action="{{ route('shop.index') }}" data-pa-panel="meds" class="pa-hide">
            <div class="pa-formrow"><label class="pa-label">Medicine or product</label><input class="pa-field" name="search" placeholder="e.g. Panado, blood pressure monitor" /></div>
            <div class="pa-formrow"><label class="pa-label">Deliver to</label><input class="pa-field" placeholder="Street address (added at checkout)" disabled /></div>
            <div class="pa-formgrid" style="margin-bottom:22px">
              <div><label class="pa-label">When</label><input class="pa-field" value="Today" disabled /></div>
              <div><label class="pa-label">Paying with</label><input class="pa-field" value="Any scheme" disabled /></div>
            </div>
            <button type="submit" class="pa-btn pa-btn-block pa-btn-lg">Search the pharmacy</button>
          </form>
          <form method="GET" action="{{ route('shuttle') }}" data-pa-panel="ride" class="pa-hide">
            <div class="pa-formrow"><label class="pa-label">Pick up</label><input class="pa-field" placeholder="Home address (entered on the next step)" disabled /></div>
            <div class="pa-formrow"><label class="pa-label">Drop off</label><input class="pa-field" placeholder="Clinic, pharmacy or lab" disabled /></div>
            <div class="pa-formgrid" style="margin-bottom:22px">
              <div><label class="pa-label">When</label><input class="pa-field" value="Today" disabled /></div>
              <div><label class="pa-label">Paying with</label><input class="pa-field" value="Any scheme" disabled /></div>
            </div>
            <button type="submit" class="pa-btn pa-btn-block pa-btn-lg">Get a fare estimate</button>
          </form>
          <div class="pa-row" style="margin-top:16px;font-size:12px;color:var(--pa-muted)"><span style="width:6px;height:6px;background:var(--pa-go);display:block"></span>{{ $stats['openToday'] }} doctors have openings today</div>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="pa-dark">
  <div class="pa-container" style="padding-top:18px;padding-bottom:18px;display:flex;gap:40px;align-items:center;flex-wrap:wrap;font-size:13px">
    <span class="pa-row" style="font-weight:700"><span class="pa-tick-beacon" style="width:7px;height:7px;animation:pa-blip 1.6s ease-in-out infinite"></span>Live now</span>
    <span style="color:#A9A49A"><strong style="color:var(--pa-paper)">{{ $stats['tripsToday'] }}</strong> trips today</span>
    <span style="color:#A9A49A"><strong style="color:var(--pa-paper)">9 min</strong> median pickup</span>
    <span style="color:#A9A49A"><strong style="color:var(--pa-paper)">96%</strong> on time</span>
    <span style="color:#A9A49A"><strong style="color:var(--pa-paper)">{{ $stats['scriptsThisWeek'] }}</strong> scripts dispensed this week</span>
  </div>
</section>

<section class="pa-section">
  <div class="pa-spread" style="align-items:baseline;margin-bottom:28px">
    <div><div class="pa-eyebrow" style="margin-bottom:10px">What do you need</div><h2>Six services, one account</h2></div>
    <a class="pa-btn-ghost" href="{{ route('doctors.index') }}">Browse everything</a>
  </div>
  <div class="pa-grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr))">
    <a href="{{ route('doctors.index') }}" style="padding:26px 24px;color:inherit;text-decoration:none;display:block">
      <div class="pa-num" style="font-size:26px;color:var(--pa-signal);margin-bottom:14px">01</div>
      <div style="font-size:16px;font-weight:700;margin-bottom:6px">Doctors</div>
      <div style="font-size:13px;color:var(--pa-muted)">GPs and specialists, in person or by video</div></a>
    <a href="{{ route('clinics.index') }}" style="padding:26px 24px;color:inherit;text-decoration:none;display:block">
      <div class="pa-num" style="font-size:26px;color:var(--pa-signal);margin-bottom:14px">02</div>
      <div style="font-size:16px;font-weight:700;margin-bottom:6px">Clinics</div>
      <div style="font-size:13px;color:var(--pa-muted)">Day hospitals, family practices, walk-in rooms</div></a>
    <a href="{{ route('pharmacies.index') }}" style="padding:26px 24px;color:inherit;text-decoration:none;display:block">
      <div class="pa-num" style="font-size:26px;color:var(--pa-signal);margin-bottom:14px">03</div>
      <div style="font-size:16px;font-weight:700;margin-bottom:6px">Pharmacy</div>
      <div style="font-size:13px;color:var(--pa-muted)">Repeat scripts, OTC, devices, delivered</div></a>
    <a href="{{ route('labs.index') }}" style="padding:26px 24px;color:inherit;text-decoration:none;display:block">
      <div class="pa-num" style="font-size:26px;color:var(--pa-signal);margin-bottom:14px">04</div>
      <div style="font-size:16px;font-weight:700;margin-bottom:6px">Labs &amp; imaging</div>
      <div style="font-size:13px;color:var(--pa-muted)">Blood work, X-ray, ultrasound, CT</div></a>
    <a href="{{ route('specialists.index') }}" style="padding:26px 24px;color:inherit;text-decoration:none;display:block">
      <div class="pa-num" style="font-size:26px;color:var(--pa-signal);margin-bottom:14px">05</div>
      <div style="font-size:16px;font-weight:700;margin-bottom:6px">Physio &amp; specialists</div>
      <div style="font-size:13px;color:var(--pa-muted)">Rehab, optometry, dietetics, audiology</div></a>
    <a href="{{ route('shuttle') }}" style="padding:26px 24px;color:inherit;text-decoration:none;display:block">
      <div class="pa-num" style="font-size:26px;color:var(--pa-signal);margin-bottom:14px">06</div>
      <div style="font-size:16px;font-weight:700;margin-bottom:6px">Shuttle</div>
      <div style="font-size:13px;color:var(--pa-muted)">Wheelchair and stretcher vehicles, return legs</div></a>
  </div>
</section>

<section class="pa-section">
  <div class="pa-dark" style="padding:56px 56px 0;display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:56px;align-items:end">
    <div style="padding-bottom:56px">
      <div class="pa-row" style="margin-bottom:20px"><span class="pa-tick-beacon"></span><span class="pa-eyebrow" style="color:var(--pa-beacon)">The shuttle</span></div>
      <h2 style="font-size:44px;margin-bottom:18px">Missing the appointment<br />is the appointment problem.</h2>
      <p style="font-size:16px;color:#B8B3A8;max-width:460px;margin-bottom:30px">Request a vehicle from home, watch it arrive, and book the return leg at the same time. Wheelchair and stretcher vehicles on the same map.</p>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1px;background:#2A2A2E;border:1px solid #2A2A2E;margin-bottom:30px">
        <div style="background:var(--pa-ink);padding:18px 20px"><div style="font-size:14px;font-weight:700;margin-bottom:5px">Standard</div><div style="font-size:12px;color:#8A857C;margin-bottom:10px">Sedan, 1–3 seats</div><div class="pa-num" style="font-size:20px;color:var(--pa-beacon)">R 18 / km</div></div>
        <div style="background:var(--pa-ink);padding:18px 20px"><div style="font-size:14px;font-weight:700;margin-bottom:5px">Comfort</div><div style="font-size:12px;color:#8A857C;margin-bottom:10px">SUV, extra legroom</div><div class="pa-num" style="font-size:20px;color:var(--pa-beacon)">R 24 / km</div></div>
        <div style="background:var(--pa-ink);padding:18px 20px"><div style="font-size:14px;font-weight:700;margin-bottom:5px">Wheelchair</div><div style="font-size:12px;color:#8A857C;margin-bottom:10px">Ramp and restraints</div><div class="pa-num" style="font-size:20px;color:var(--pa-beacon)">R 32 / km</div></div>
        <div style="background:var(--pa-ink);padding:18px 20px"><div style="font-size:14px;font-weight:700;margin-bottom:5px">Stretcher</div><div style="font-size:12px;color:#8A857C;margin-bottom:10px">Non-emergency transfer</div><div class="pa-num" style="font-size:20px;color:var(--pa-beacon)">R 46 / km</div></div>
      </div>
      <a class="pa-btn-beacon pa-btn-lg" href="{{ route('shuttle') }}">Request a shuttle</a>
    </div>
    <div class="pa-map" style="height:420px;border-left:1px solid #2A2A2E;border-top:1px solid #2A2A2E">
      <div style="position:absolute;left:20px;bottom:20px;right:20px;background:var(--pa-paper);color:var(--pa-ink);padding:16px 18px;display:flex;justify-content:space-between;align-items:center;gap:16px">
        <div><div class="pa-eyebrow">Fare estimate</div><div style="font-size:13px;color:var(--pa-ink-soft);margin-top:3px">Get a real quote in under a minute</div></div>
        <a class="pa-btn" href="{{ route('shuttle') }}">Get quote</a>
      </div>
    </div>
  </div>
</section>

@if ($featuredDoctors->count() || $featuredPartners->count())
<section class="pa-section">
  <div class="pa-spread" style="align-items:baseline;margin-bottom:28px"><h2>Available today</h2><a class="pa-btn-ghost" href="{{ route('doctors.index') }}">All providers</a></div>
  <div class="pa-grid" style="grid-template-columns:repeat(auto-fit,minmax(280px,1fr))">
    @foreach ($featuredDoctors as $doctor)
      <a href="{{ route('doctors.show', $doctor) }}" style="padding:24px;color:inherit;text-decoration:none;display:block">
        <div style="display:flex;gap:14px;margin-bottom:16px"><div class="pa-avatar" style="width:52px;height:52px;font-size:18px">{{ \Illuminate\Support\Str::of($doctor->user->name)->explode(' ')->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('') }}</div>
        <div><div style="font-size:16px;font-weight:700;line-height:1.25">Dr {{ $doctor->user->name }}</div><div style="font-size:13px;color:var(--pa-muted);margin-top:3px">{{ $doctor->specialization }}</div></div></div>
        <div style="font-size:13px;color:var(--pa-ink-soft);margin-bottom:14px">{{ $doctor->clinics->first()?->name ?? 'Video consultation available' }}</div>
        <div class="pa-spread" style="border-top:1px solid var(--pa-line);padding-top:14px"><span class="pa-badge is-go">Book online</span><span class="pa-num" style="font-size:19px">R {{ number_format($doctor->consultation_fee, 0) }}</span></div></a>
    @endforeach
    @foreach ($featuredPartners as $partner)
      <a href="{{ route('specialists.show', $partner) }}" style="padding:24px;color:inherit;text-decoration:none;display:block">
        <div style="display:flex;gap:14px;margin-bottom:16px"><div class="pa-avatar" style="width:52px;height:52px;font-size:18px">{{ mb_substr($partner->company_name, 0, 2) }}</div>
        <div><div style="font-size:16px;font-weight:700;line-height:1.25">{{ $partner->company_name }}</div><div style="font-size:13px;color:var(--pa-muted);margin-top:3px">{{ ucfirst($partner->category) }}</div></div></div>
        <div style="font-size:13px;color:var(--pa-ink-soft);margin-bottom:14px">{{ $partner->service_type }}</div>
        <div class="pa-spread" style="border-top:1px solid var(--pa-line);padding-top:14px"><span class="pa-badge is-go">{{ $partner->accepts_walk_ins ? 'Walk in' : 'By appointment' }}</span><span class="pa-num" style="font-size:19px">View</span></div></a>
    @endforeach
  </div>
</section>
@endif

<section class="pa-section">
  <div class="pa-rule-heavy" style="margin-bottom:36px"></div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:40px">
    <div><div style="font-size:13px;font-weight:900;color:var(--pa-signal);margin-bottom:12px">STEP 01</div><div style="font-size:19px;font-weight:700;margin-bottom:8px">Search and compare</div><div style="font-size:14px;color:var(--pa-ink-soft)">Filter by speciality, suburb, fee, scheme and who has an opening today.</div></div>
    <div><div style="font-size:13px;font-weight:900;color:var(--pa-signal);margin-bottom:12px">STEP 02</div><div style="font-size:19px;font-weight:700;margin-bottom:8px">Book and add a lift</div><div style="font-size:14px;color:var(--pa-ink-soft)">Pick a slot. Tick the shuttle box and the return leg is scheduled with it.</div></div>
    <div><div style="font-size:13px;font-weight:900;color:var(--pa-signal);margin-bottom:12px">STEP 03</div><div style="font-size:19px;font-weight:700;margin-bottom:8px">Attend or dial in</div><div style="font-size:14px;color:var(--pa-ink-soft)">The driver tracks to your door, or a Google Meet link opens in the app.</div></div>
    <div><div style="font-size:13px;font-weight:900;color:var(--pa-signal);margin-bottom:12px">STEP 04</div><div style="font-size:19px;font-weight:700;margin-bottom:8px">Scripts and results follow</div><div style="font-size:14px;color:var(--pa-ink-soft)">Prescriptions go to your pharmacy, results to your record and your doctor.</div></div>
  </div>
</section>

@if ($schemeNames->count())
<section class="pa-section">
  <div class="pa-card pa-spread" style="padding:28px 32px">
    <div class="pa-eyebrow">Billed directly to</div>
    <div style="display:flex;gap:28px;flex-wrap:wrap;font-size:17px;font-weight:700;color:var(--pa-ink-soft)">
      @foreach ($schemeNames as $name)<span>{{ $name }}</span>@endforeach
    </div>
  </div>
</section>
@endif

<section class="pa-section pa-section-last">
  <div style="background:var(--pa-signal);color:#fff;padding:52px 48px;display:flex;align-items:center;justify-content:space-between;gap:36px;flex-wrap:wrap">
    <div><h2 style="font-size:38px;color:#fff;margin-bottom:10px">Your next appointment,<br />lift included.</h2>
    <p style="font-size:16px;color:#FBD5D3;margin:0">Free to join. No booking fee. Pay the provider directly.</p></div>
    <div style="display:flex;gap:12px;flex-wrap:wrap">
      <a class="pa-btn-lg" style="background:#fff;color:var(--pa-ink);border:1px solid #fff;font-weight:700;padding:16px 26px;display:inline-flex;align-items:center" href="{{ route('register') }}">Create an account</a>
      <a class="pa-btn-lg" style="background:transparent;color:#fff;border:1px solid #fff;font-weight:700;padding:16px 26px;display:inline-flex;align-items:center" href="{{ route('how-it-works') }}">How the shuttle works</a>
    </div>
  </div>
</section>
</x-layout>
