@php
  $noTriage = true;
  $patient = auth()->user()?->patientProfile ?? null;
  $scheme = $patient?->schemeMemberships?->first()?->scheme?->name;
@endphp
<x-layout title="Nearest help · Prompt Aid">
<div class="pa-pagehead"><div class="inner">
  <div class="pa-crumb"><a href="{{ url('/') }}">Home</a> <span style="color:#CFC8B8">/</span> <a href="{{ route('emergency') }}">Emergency triage</a> <span style="color:#CFC8B8">/</span> Nearest help</div>
  <div class="pa-row" style="margin-bottom:12px"><span class="pa-blip"></span><span class="pa-eyebrow">South African Triage Scale · result</span></div>
  <h1>
    @if($level === 'red') Red — get emergency care immediately
    @elseif($level === 'orange') Orange — very urgent, within 10 minutes
    @elseif($level === 'yellow') Yellow — urgent, within an hour
    @else Green — routine, can be booked normally
    @endif
  </h1>
  @if($submission)
    <p class="pa-muted" style="font-size:15px;margin:8px 0 0">Reference <strong>{{ $submission->reference }}</strong> — show this at reception.</p>
  @endif
</div></div>

<div class="pa-container" style="padding-top:32px;padding-bottom:80px;display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:32px;align-items:start">
  <div>

  @if($level === 'red')
    <a class="pa-emergency-cta" style="margin-bottom:24px" href="tel:10177">This is an emergency — call 10177 now</a>
  @endif

  @if(! $hasRealLocation)
    <div class="pa-note-stop" style="margin-bottom:20px">We could not get your location, so the list below is sorted from central Johannesburg rather than from where you actually are. <a href="{{ route('emergency') }}">Go back and allow location access</a> or enter an address for a more accurate list.</div>
  @endif

  <div class="pa-card-head" style="padding:0;margin-bottom:14px"><h3>Nearest matching providers</h3></div>

  @forelse($results as $r)
    <div class="pa-card" style="margin-bottom:14px;padding:18px 20px">
      <div class="pa-spread" style="align-items:flex-start">
        <div>
          <span class="pa-badge is-wait" style="margin-bottom:6px;display:inline-block">{{ $r['type'] }}</span>
          <h3 style="font-size:17px;margin:2px 0 4px">{{ $r['name'] }}</h3>
          @if($r['address'])<p class="pa-muted" style="font-size:13px;margin:0 0 8px">{{ $r['address'] }}</p>@endif
          <div class="pa-row" style="font-size:13px;color:var(--pa-ink-soft);gap:14px">
            <span>{{ number_format($r['distance_km'], 1) }} km away</span>
            <span>~{{ $r['eta_minutes'] }} min drive</span>
            @if($r['shuttle_fare'])<span>Shuttle from R{{ number_format($r['shuttle_fare'], 0) }}</span>@endif
          </div>
        </div>
        <div style="text-align:right;flex-shrink:0">
          <a class="pa-btn pa-btn-sm" href="{{ $r['url'] }}" style="margin-bottom:8px;display:inline-block">View &amp; book</a><br />
          <a class="pa-btn-ghost pa-btn-sm" href="{{ route('shuttle') }}">Request a shuttle</a>
        </div>
      </div>
    </div>
  @empty
    <div class="pa-card pa-card-pad">
      <p class="pa-muted">No matching providers are registered on Prompt Aid near you yet. Please call 10177/112, or your nearest emergency department directly.</p>
    </div>
  @endforelse

  @if($submission)
  <div class="pa-card pa-card-pad" style="margin-top:24px">
    <div class="pa-card-head" style="padding:0 0 12px"><h3>What we'll send ahead</h3></div>
    @if($patient)
      <p style="font-size:13px;color:var(--pa-ink-soft);margin-bottom:10px">When you book with a provider above, this is attached automatically so you do not have to repeat it.</p>
      <div style="display:flex;gap:6px;flex-wrap:wrap">
        @forelse(($patient->allergies ?? []) as $a)<span class="pa-badge is-stop">{{ $a }}</span>@empty<span class="pa-badge">No allergies on file</span>@endforelse
        @foreach(($patient->chronic_conditions ?? []) as $c)<span class="pa-badge is-wait">{{ $c }}</span>@endforeach
        @if($scheme)<span class="pa-badge">{{ $scheme }}</span>@endif
      </div>
    @else
      <p style="font-size:13px;color:var(--pa-ink-soft)">You're not signed in, so we have no medical record to send ahead. <a href="{{ route('login') }}">Sign in</a> so allergies, chronic medicine and your scheme travel with you automatically next time.</p>
    @endif
  </div>

  <div class="pa-card pa-card-pad" style="margin-top:16px" id="pa-alert-card">
    <div class="pa-card-head" style="padding:0 0 12px"><h3>Tell someone</h3></div>
    <p style="font-size:13px;color:var(--pa-ink-soft);margin-bottom:12px">We'll send a text to someone you trust to let them know you've started an emergency triage.</p>
    @if($submission->contact_alerted_at)
      <p style="font-size:13px;font-weight:700;color:var(--pa-signal)">{{ $submission->emergency_contact_name }} was alerted.</p>
    @else
      <form id="pa-alert-form" style="display:grid;grid-template-columns:1fr 1fr auto;gap:10px;align-items:end">
        <div><label class="pa-label">Their name</label><input class="pa-field" name="contact_name" required /></div>
        <div><label class="pa-label">Their phone</label><input class="pa-field" name="contact_phone" type="tel" required placeholder="082 000 0000" /></div>
        <button class="pa-btn" type="submit">Alert them</button>
      </form>
      <p id="pa-alert-status" style="font-size:12px;margin-top:8px"></p>
    @endif
  </div>
  @endif

  </div>

  <aside style="position:sticky;top:96px">
    <a class="pa-emergency-cta" style="margin-bottom:16px" href="tel:10177">Ambulance · 10177</a>
    <div class="pa-card pa-card-pad" style="margin-bottom:16px">
      <div class="pa-label" style="margin-bottom:10px">Why these results</div>
      <p style="font-size:13px;color:var(--pa-ink-soft)">We only list providers registered on Prompt Aid, ranked by distance from you. We are not a hospital directory and do not claim to list every emergency department in your area — for a full list of hospitals, call 10177 or 112.</p>
    </div>
    <div class="pa-note-stop">This is a pre-triage aid, not a diagnosis. A practitioner performs the formal SATS assessment on arrival and may reach a different colour.</div>
  </aside>
</div>

@if($submission)
<script>
(function () {
  var form = document.getElementById('pa-alert-form');
  if (!form) return;
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var status = document.getElementById('pa-alert-status');
    var token = document.querySelector('meta[name="csrf-token"]');
    var btn = form.querySelector('button');
    btn.disabled = true;
    status.textContent = 'Sending…';
    fetch('{{ route('emergency.alert-contact') }}', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token.content, Accept: 'application/json' },
      body: JSON.stringify({
        ref: '{{ $submission->reference }}',
        contact_name: form.contact_name.value,
        contact_phone: form.contact_phone.value,
      }),
    }).then(function (r) { return r.ok ? r.json() : Promise.reject(); })
      .then(function () {
        status.style.color = 'var(--pa-go)';
        status.textContent = form.contact_name.value + ' has been alerted.';
        form.style.display = 'none';
      })
      .catch(function () {
        btn.disabled = false;
        status.style.color = 'var(--pa-signal)';
        status.textContent = 'Could not send the alert — please try again or call them directly.';
      });
  });
})();
</script>
@endif
</x-layout>
