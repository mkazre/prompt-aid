{{--
  Renders a resolved patient request scenario (ScenarioEngine::resolve) as
  cards, in the admin-defined order. Inputs: $scenario (array), optional
  $skipConsult (bool: hide the doctor-request card, e.g. on the see-a-doctor page),
  $autoDial (bool: honour call_emergency auto-dial on this page).
  The consult steps (payment, channel choice) run inside /account/see-a-doctor;
  here the request is one button.
--}}
@php
  $paSteps = $scenario['steps'] ?? [];
  $paSkipConsult = $skipConsult ?? false;
  $paAutoDial = $autoDial ?? false;
  $paStyles = [
      'danger' => 'pa-note-stop',
      'warning' => 'pa-note',
      'success' => 'pa-note',
      'info' => 'pa-note',
  ];
  $paHasConsult = collect($paSteps)->contains(fn ($s) => $s['type'] === 'broadcast_to_doctors');
@endphp
<div class="pa-scenario" data-pa-scenario="{{ $scenario['level'] ?? '' }}" style="display:grid;gap:14px">
@foreach ($paSteps as $st)
  @php($set = $st['settings'] ?? [])
  @switch($st['type'])
    @case('show_message')
      <div class="{{ $paStyles[$set['style'] ?? 'info'] ?? 'pa-note' }}" @if(($set['style'] ?? '') === 'success') style="border-color:var(--pa-go)" @endif>
        @if (($set['title'] ?? '') !== '')<strong>{{ $set['title'] }}</strong>@endif
        @if (($set['body'] ?? '') !== '') <span>{{ $set['body'] }}</span>@endif
      </div>
      @break
    @case('call_emergency')
      @if (! empty($st['tel']))
        <a class="pa-emergency-cta" href="{{ $st['tel'] }}" data-pa-autodial="{{ $paAutoDial && ($set['auto_dial'] ?? false) ? (int) ($set['countdown_seconds'] ?? 0) : '' }}">{{ $set['label'] ?? 'Call an ambulance now' }} · {{ $st['phone'] }}</a>
        <div class="pa-muted pa-hide" data-pa-autodial-line style="font-size:13px;margin-top:-6px">Dialling in <strong data-pa-autodial-secs></strong>s… <button type="button" class="pa-btn-ghost pa-btn-sm" data-pa-autodial-cancel>Cancel</button></div>
        @if (! empty($st['contacts']) && count($st['contacts']) > 1)
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            @foreach ($st['contacts'] as $c)
              @continue($c['primary'])
              <a class="pa-btn-ghost pa-btn-sm" href="{{ $c['tel'] }}">{{ $c['label'] }} · {{ $c['phone'] }}</a>
            @endforeach
          </div>
        @endif
      @endif
      @break
    @case('offer_ambulance_or_shuttle')
      <div class="pa-card pa-card-pad">
        @if (($set['note'] ?? '') !== '')<p style="margin:0 0 10px;font-weight:700">{{ $set['note'] }}</p>@endif
        <div style="display:flex;gap:10px;flex-wrap:wrap">
          @if (! empty($st['ambulance']))<a class="pa-btn" href="{{ $st['ambulance']['tel'] }}">Call an ambulance · {{ $st['ambulance']['phone'] }}</a>@endif
          @if (! empty($st['shuttle_url']))<a class="pa-btn-ghost" href="{{ $st['shuttle_url'] }}">Book a Prompt Aid shuttle</a>@endif
        </div>
      </div>
      @break
    @case('broadcast_to_doctors')
      @if (! $paSkipConsult)
        <div class="pa-card" style="border:2px solid var(--pa-sats-orange)">
          <div class="pa-card-head"><h3>{{ ($set['optional'] ?? false) ? 'Also need a doctor?' : 'Speak to a doctor now' }}</h3></div>
          <div class="pa-card-body">
            <p style="margin:0 0 14px;font-size:15px;color:var(--pa-ink-soft)">Your request goes to every available doctor at once — the first to accept takes your consult.@if (($scenario['plan']['channel_timing'] ?? '') === 'after') You choose video, voice or WhatsApp once a doctor has accepted.@endif</p>
            <a class="pa-btn pa-btn-lg" href="{{ $st['url'] }}">{{ $set['button_label'] ?: 'Request a doctor now' }}</a>
          </div>
        </div>
      @endif
      @break
    @case('book_appointment')
    @case('find_care')
    @case('pharmacy')
    @case('health_article')
      @if (! empty($st['url']))
        <a class="pa-btn-ghost pa-btn-lg" style="text-align:center" href="{{ $st['url'] }}">{{ $set['label'] ?? $st['label'] }}</a>
      @endif
      @break
    @case('self_care_advice')
      <div class="pa-card">
        <div class="pa-card-head"><h3>{{ $set['title'] ?? 'What to do right now' }}</h3></div>
        <div class="pa-card-body"><ol style="margin:0;padding-left:20px;font-size:15px;line-height:1.8;color:var(--pa-ink-soft)">
          @foreach ($st['lines'] ?? [] as $line)<li>{{ $line }}</li>@endforeach
        </ol></div>
      </div>
      @break
    @case('end')
      <p class="pa-muted" style="margin:4px 0 0">{{ $set['message'] ?? '' }}</p>
      @break
  @endswitch
@endforeach
</div>
@once
<script>
(function () {
  document.querySelectorAll('[data-pa-autodial]').forEach(function (a) {
    var v = a.getAttribute('data-pa-autodial');
    if (v === '') return;
    var secs = parseInt(v, 10) || 0, line = a.parentNode.querySelector('[data-pa-autodial-line]'), out = line && line.querySelector('[data-pa-autodial-secs]'), t = null;
    function dial() { window.location.href = a.getAttribute('href'); }
    if (secs <= 0) { dial(); return; }
    if (line) line.classList.remove('pa-hide');
    if (out) out.textContent = secs;
    t = setInterval(function () { secs -= 1; if (out) out.textContent = secs; if (secs <= 0) { clearInterval(t); if (line) line.classList.add('pa-hide'); dial(); } }, 1000);
    var c = line && line.querySelector('[data-pa-autodial-cancel]');
    if (c) c.addEventListener('click', function () { clearInterval(t); line.classList.add('pa-hide'); });
  });
})();
</script>
@endonce
