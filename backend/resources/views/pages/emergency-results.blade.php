@php $noTriage = true; @endphp
<x-layout title="Nearest help · Prompt Aid">
<div class="pa-verdict orange">
  <div class="pa-container" style="padding-top:34px;padding-bottom:34px">
    <div class="pa-spread">
      <div>
        <div class="lvl">Your triage result</div>
        <h2 style="font-size:40px">Orange — very urgent</h2>
        <p>Target time to be seen: <strong>within 10 minutes</strong>. Reference <strong>TRI-8K2QM</strong>. Burn to the left forearm, pain 6 of 10, adult, no allergies to dressings on file.</p>
      </div>
      <div style="text-align:right">
        <div class="pa-countdown">09:41</div>
        <div style="font-size:12px;font-weight:900;letter-spacing:.1em;text-transform:uppercase;opacity:.85;margin-top:4px">Target arrival</div>
      </div>
    </div>
  </div>
</div>

<div class="pa-container" style="padding-top:28px;padding-bottom:80px;display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:32px;align-items:start">
  <div>
    <div class="pa-locrow" style="margin-bottom:20px">
      <span class="pa-tick-beacon"></span>
      <span style="flex:1"><strong>14 Waterford Drive, Fourways</strong> · located to 12 m, 40 seconds ago</span>
      <a class="pa-btn-ghost pa-btn-sm" href="{{ route('emergency') }}">Change</a>
    </div>

    <div class="pa-spread" style="margin-bottom:16px">
      <div>
        <h2 style="font-size:26px">Nearest help that can treat this</h2>
        <p class="pa-muted" style="margin:4px 0 0;font-size:14px">Ranked by capability first, then distance. Two emergency departments carry a burns unit.</p>
      </div>
      <div class="pa-tabs"><button class="pa-tab is-on">List</button><button class="pa-tab">Map</button></div>
    </div>

    <div class="pa-grid" style="grid-template-columns:1fr;margin-bottom:24px"><div style="padding:22px 24px;display:grid;grid-template-columns:minmax(0,1fr) 220px;gap:20px;align-items:start;border-left:4px solid var(--pa-sats-red)">
  <div style="min-width:0">
    <div class="pa-row" style="flex-wrap:wrap;margin-bottom:6px">
      <span style="font-size:18px;font-weight:900;letter-spacing:-.012em">Sunninghill Emergency Department</span>
      <span class="pa-badge is-stop">Emergency</span>
      <span class="pa-badge is-ink">Best match for your triage</span>
    </div>
    <div style="font-size:13px;color:var(--pa-muted);margin-bottom:12px">6.2 km away · 24 hours · Scheme or R 2 450 cash</div>
    <div style="display:flex;gap:7px;flex-wrap:wrap;margin-bottom:12px"><span class="pa-chip">Trauma</span><span class="pa-chip">Burns unit</span><span class="pa-chip">Paediatric ED</span><span class="pa-chip">CT on site</span></div>
    <div style="font-size:13px;color:var(--pa-ink-soft)"><strong>Current wait:</strong> Red seen immediately · orange 14 min</div>
  </div>
  <div style="text-align:right">
    <div class="pa-num" style="font-size:26px">11 min</div>
    <div style="font-size:12px;color:var(--pa-muted);margin-bottom:12px">by shuttle</div>
    <a class="pa-btn pa-btn-block" href="{{ route('shuttle') }}">Alert them I am coming</a>
    <a class="pa-btn-ghost pa-btn-block pa-btn-sm" style="margin-top:6px" href="/shuttle?to=Sunninghill%20Emergency%20Department&priority=emergency"><span class="pa-tick-beacon" style="width:6px;height:6px"></span>Emergency shuttle</a>
  </div>
</div><div style="padding:22px 24px;display:grid;grid-template-columns:minmax(0,1fr) 220px;gap:20px;align-items:start">
  <div style="min-width:0">
    <div class="pa-row" style="flex-wrap:wrap;margin-bottom:6px">
      <span style="font-size:18px;font-weight:900;letter-spacing:-.012em">Fourways Life Emergency</span>
      <span class="pa-badge is-stop">Emergency</span>

    </div>
    <div style="font-size:13px;color:var(--pa-muted);margin-bottom:12px">3.1 km away · 24 hours · Scheme or R 2 780 cash</div>
    <div style="display:flex;gap:7px;flex-wrap:wrap;margin-bottom:12px"><span class="pa-chip">Trauma</span><span class="pa-chip">Cath lab</span><span class="pa-chip">Stroke unit</span></div>
    <div style="font-size:13px;color:var(--pa-ink-soft)"><strong>Current wait:</strong> Red immediately · orange 22 min</div>
  </div>
  <div style="text-align:right">
    <div class="pa-num" style="font-size:26px">7 min</div>
    <div style="font-size:12px;color:var(--pa-muted);margin-bottom:12px">by shuttle</div>
    <a class="pa-btn pa-btn-block" href="{{ route('shuttle') }}">Alert them I am coming</a>
    <a class="pa-btn-ghost pa-btn-block pa-btn-sm" style="margin-top:6px" href="/shuttle?to=Fourways%20Life%20Emergency&priority=emergency"><span class="pa-tick-beacon" style="width:6px;height:6px"></span>Emergency shuttle</a>
  </div>
</div><div style="padding:22px 24px;display:grid;grid-template-columns:minmax(0,1fr) 220px;gap:20px;align-items:start">
  <div style="min-width:0">
    <div class="pa-row" style="flex-wrap:wrap;margin-bottom:6px">
      <span style="font-size:18px;font-weight:900;letter-spacing:-.012em">Sunninghill Family Clinic</span>
      <span class="pa-badge is-wait">Clinic</span>

    </div>
    <div style="font-size:13px;color:var(--pa-muted);margin-bottom:12px">8.1 km away · Until 19:00 · From R 390</div>
    <div style="display:flex;gap:7px;flex-wrap:wrap;margin-bottom:12px"><span class="pa-chip">Walk-in</span><span class="pa-chip">X-ray</span><span class="pa-chip">Wound care</span><span class="pa-chip">Dispensing</span></div>
    <div style="font-size:13px;color:var(--pa-ink-soft)"><strong>Current wait:</strong> About 25 min</div>
  </div>
  <div style="text-align:right">
    <div class="pa-num" style="font-size:26px">15 min</div>
    <div style="font-size:12px;color:var(--pa-muted);margin-bottom:12px">by shuttle</div>
    <a class="pa-btn pa-btn-block" href="{{ route('shuttle') }}">Book this</a>
    <a class="pa-btn-ghost pa-btn-block pa-btn-sm" style="margin-top:6px" href="/shuttle?to=Sunninghill%20Family%20Clinic&priority=emergency"><span class="pa-tick-beacon" style="width:6px;height:6px"></span>Emergency shuttle</a>
  </div>
</div><div style="padding:22px 24px;display:grid;grid-template-columns:minmax(0,1fr) 220px;gap:20px;align-items:start">
  <div style="min-width:0">
    <div class="pa-row" style="flex-wrap:wrap;margin-bottom:6px">
      <span style="font-size:18px;font-weight:900;letter-spacing:-.012em">Dr Naledi Mokoena</span>
      <span class="pa-badge is-go">Doctor</span>

    </div>
    <div style="font-size:13px;color:var(--pa-muted);margin-bottom:12px">3.4 km away · Until 16:30 · R 520</div>
    <div style="display:flex;gap:7px;flex-wrap:wrap;margin-bottom:12px"><span class="pa-chip">Same-day slot 11:20</span><span class="pa-chip">Chronic care</span><span class="pa-chip">Video option</span></div>
    <div style="font-size:13px;color:var(--pa-ink-soft)"><strong>Current wait:</strong> Booked slot</div>
  </div>
  <div style="text-align:right">
    <div class="pa-num" style="font-size:26px">8 min</div>
    <div style="font-size:12px;color:var(--pa-muted);margin-bottom:12px">by shuttle</div>
    <a class="pa-btn pa-btn-block" href="{{ route('shuttle') }}">Book this</a>
    <a class="pa-btn-ghost pa-btn-block pa-btn-sm" style="margin-top:6px" href="/shuttle?to=Dr%20Naledi%20Mokoena&priority=emergency"><span class="pa-tick-beacon" style="width:6px;height:6px"></span>Emergency shuttle</a>
  </div>
</div><div style="padding:22px 24px;display:grid;grid-template-columns:minmax(0,1fr) 220px;gap:20px;align-items:start">
  <div style="min-width:0">
    <div class="pa-row" style="flex-wrap:wrap;margin-bottom:6px">
      <span style="font-size:18px;font-weight:900;letter-spacing:-.012em">Rosebank Pharmacy</span>
      <span class="pa-badge is-go">Pharmacy</span>

    </div>
    <div style="font-size:13px;color:var(--pa-muted);margin-bottom:12px">3.5 km away · Until 20:00 · From R 45</div>
    <div style="display:flex;gap:7px;flex-wrap:wrap;margin-bottom:12px"><span class="pa-chip">Pharmacist advice</span><span class="pa-chip">Burn dressings</span><span class="pa-chip">Delivery 45 min</span></div>
    <div style="font-size:13px;color:var(--pa-ink-soft)"><strong>Current wait:</strong> No wait</div>
  </div>
  <div style="text-align:right">
    <div class="pa-num" style="font-size:26px">8 min</div>
    <div style="font-size:12px;color:var(--pa-muted);margin-bottom:12px">by shuttle</div>
    <a class="pa-btn pa-btn-block" href="{{ route('shuttle') }}">Order now</a>
    <a class="pa-btn-ghost pa-btn-block pa-btn-sm" style="margin-top:6px" href="/shuttle?to=Rosebank%20Pharmacy&priority=emergency"><span class="pa-tick-beacon" style="width:6px;height:6px"></span>Emergency shuttle</a>
  </div>
</div><div style="padding:22px 24px;display:grid;grid-template-columns:minmax(0,1fr) 220px;gap:20px;align-items:start">
  <div style="min-width:0">
    <div class="pa-row" style="flex-wrap:wrap;margin-bottom:6px">
      <span style="font-size:18px;font-weight:900;letter-spacing:-.012em">Lancet Diagnostics Rosebank</span>
      <span class="pa-badge is-go">Lab</span>

    </div>
    <div style="font-size:13px;color:var(--pa-muted);margin-bottom:12px">3.6 km away · Until 17:00 · From R 180</div>
    <div style="display:flex;gap:7px;flex-wrap:wrap;margin-bottom:12px"><span class="pa-chip">Walk-in bloods</span><span class="pa-chip">X-ray</span><span class="pa-chip">48hr results</span></div>
    <div style="font-size:13px;color:var(--pa-ink-soft)"><strong>Current wait:</strong> About 12 min</div>
  </div>
  <div style="text-align:right">
    <div class="pa-num" style="font-size:26px">9 min</div>
    <div style="font-size:12px;color:var(--pa-muted);margin-bottom:12px">by shuttle</div>
    <a class="pa-btn pa-btn-block" href="{{ route('shuttle') }}">Book this</a>
    <a class="pa-btn-ghost pa-btn-block pa-btn-sm" style="margin-top:6px" href="/shuttle?to=Lancet%20Diagnostics%20Rosebank&priority=emergency"><span class="pa-tick-beacon" style="width:6px;height:6px"></span>Emergency shuttle</a>
  </div>
</div></div>

    <div class="pa-card" style="margin-bottom:24px">
      <div class="pa-card-head"><h3>How you get there</h3><span class="pa-badge is-wait">Emergency priority</span></div>
      <div class="pa-spread" style="padding:16px 22px;border-bottom:1px solid var(--pa-line-soft)">
          <div style="min-width:0"><div style="font-size:15px;font-weight:700">Emergency shuttle</div><div style="font-size:13px;color:var(--pa-muted);margin-top:2px">Jumps the normal ride queue. Wheelchair vehicle if needed.</div></div>
          <div class="pa-row" style="gap:16px"><span style="font-size:13px;color:var(--pa-ink-soft);text-align:right">7 min to you · 11 min to hospital</span>
          <span class="pa-num" style="font-size:16px;min-width:60px;text-align:right">R 214</span>
          <a class="pa-btn pa-btn-sm" href="/shuttle?priority=emergency">Choose</a></div></div><div class="pa-spread" style="padding:16px 22px;border-bottom:1px solid var(--pa-line-soft)">
          <div style="min-width:0"><div style="font-size:15px;font-weight:700">Drive yourself</div><div style="font-size:13px;color:var(--pa-muted);margin-top:2px">Not advised for orange — pain and shock affect driving.</div></div>
          <div class="pa-row" style="gap:16px"><span style="font-size:13px;color:var(--pa-ink-soft);text-align:right">About 14 min in current traffic</span>
          <span class="pa-num" style="font-size:16px;min-width:60px;text-align:right">—</span>
          <a class="pa-btn-ghost pa-btn-sm" href="/shuttle?priority=emergency">Choose</a></div></div><div class="pa-spread" style="padding:16px 22px;border-bottom:1px solid var(--pa-line-soft)">
          <div style="min-width:0"><div style="font-size:15px;font-weight:700">Ambulance, 10177</div><div style="font-size:13px;color:var(--pa-muted);margin-top:2px">For red cases, or if the patient cannot be moved safely.</div></div>
          <div class="pa-row" style="gap:16px"><span style="font-size:13px;color:var(--pa-ink-soft);text-align:right">Dispatch decides</span>
          <span class="pa-num" style="font-size:16px;min-width:60px;text-align:right">State service</span>
          <a class="pa-btn-ghost pa-btn-sm" href="tel:10177">Call</a></div></div>
    </div>

    <div class="pa-card">
      <div class="pa-card-head"><h3>What we are sending ahead</h3><span class="pa-badge is-go">Consent given</span></div>
      <div class="pa-card-body">
        <p style="font-size:15px;color:var(--pa-ink-soft)">The receiving facility gets this the moment you tap book, so you are not explaining it at a counter in pain.</p>
        <div class="pa-grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr))">
          <div style="padding:14px 16px"><div class="pa-label" style="margin-bottom:5px">Triage colour</div><div style="font-size:14px;font-weight:700">Orange · TRI-8K2QM</div></div><div style="padding:14px 16px"><div class="pa-label" style="margin-bottom:5px">Presenting problem</div><div style="font-size:14px;font-weight:700">Burn, left forearm, 20 min ago</div></div><div style="padding:14px 16px"><div class="pa-label" style="margin-bottom:5px">Pain score</div><div style="font-size:14px;font-weight:700">6 of 10</div></div><div style="padding:14px 16px"><div class="pa-label" style="margin-bottom:5px">Allergies</div><div style="font-size:14px;font-weight:700">Penicillin</div></div><div style="padding:14px 16px"><div class="pa-label" style="margin-bottom:5px">Chronic medicine</div><div style="font-size:14px;font-weight:700">Amlodipine, HCTZ, metformin</div></div><div style="padding:14px 16px"><div class="pa-label" style="margin-bottom:5px">Scheme</div><div style="font-size:14px;font-weight:700">Discovery Classic Saver 8842119</div></div><div style="padding:14px 16px"><div class="pa-label" style="margin-bottom:5px">Photo attached</div><div style="font-size:14px;font-weight:700">Yes, 1 image</div></div><div style="padding:14px 16px"><div class="pa-label" style="margin-bottom:5px">Next of kin</div><div style="font-size:14px;font-weight:700">Nomsa Mahlangu, notified</div></div>
        </div>
      </div>
    </div>
  </div>

  <aside style="position:sticky;top:96px">
    <a class="pa-emergency-cta" style="margin-bottom:16px" href="tel:10177">Getting worse? Call 10177</a>
    <div class="pa-card" style="margin-bottom:16px">
      <div class="pa-card-head"><h3>Re-check in 10 minutes</h3></div>
      <div class="pa-card-body">
        <p style="font-size:14px;color:var(--pa-ink-soft)">Triage is a snapshot. If anything changes while you wait, run it again — it takes a minute and we will move you up.</p>
        <a class="pa-btn-quiet pa-btn-block pa-btn-sm" href="{{ route('emergency') }}">Re-triage now</a>
      </div>
    </div>
    <div class="pa-card" style="margin-bottom:16px">
      <div class="pa-card-head"><h3>While you wait</h3></div>
      <div class="pa-card-body">
        <div class="pa-label" style="margin-bottom:8px">Burns · first aid</div>
        <ol style="margin:0;padding-left:18px;font-size:13px;line-height:1.7;color:var(--pa-ink-soft)">
          <li>Cool running water for 20 minutes.</li>
          <li>Remove rings and watches before swelling starts.</li>
          <li>Cover loosely with cling film or a clean cloth.</li>
          <li>No ice, no butter, no toothpaste.</li>
          <li>Do not burst blisters.</li>
        </ol>
      </div>
    </div>
    <div class="pa-card" style="margin-bottom:16px">
      <div class="pa-card-head"><h3>Tell someone</h3></div>
      <div class="pa-card-body">
        <div class="pa-spread" style="font-size:14px;margin-bottom:10px"><span>Nomsa Mahlangu</span><span class="pa-badge is-go">Notified</span></div>
        <p style="font-size:13px;color:var(--pa-muted)">She has a live tracking link and the destination.</p>
        <a class="pa-btn-ghost pa-btn-block pa-btn-sm" href="{{ route('dashboard') }}">Add another contact</a>
      </div>
    </div>
    <div class="pa-note">
      <strong>No signal?</strong> Dial <strong>*134*776#</strong> to request an emergency shuttle by USSD. The six nearest facilities are cached on your phone and work offline.
    </div>
  </aside>
</div>
</x-layout>
