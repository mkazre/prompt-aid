{{-- Practice call UI (patient page + staff page). Inputs: $pcCfg (array, precomputed), $pcRole ('patient'|'practice'). Logic: assets/js/pa-practice-call.js --}}
@php
  $pcCfgJson = json_encode($pcCfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
  $pcIsPractice = $pcRole === 'practice';
@endphp
<link rel="stylesheet" href="{{ asset('assets/css/pa-practice-call.css') }}?v=1">
<div id="pa-pcall" data-role="{{ $pcRole }}">
  <section class="pa-pc-card">
    <span id="pa-pc-status" class="pa-pc-status">Loading…</span>
    <h2>{{ $pcIsPractice ? 'Urgent patient call' : 'Call with your practice' }}</h2>
    <div id="pa-pc-who" class="pa-pc-who"></div>
    <div id="pa-pc-notice" class="pa-pc-notice" hidden><b>●&nbsp;REC</b><span></span></div>
    <div id="pa-pc-stage" class="pa-pc-stage"><div id="pa-pc-stage-msg">The call starts here once both of you have answered.</div></div>
    <div id="pa-pc-actions" class="pa-pc-actions"></div>
    @if ($pcIsPractice)<div id="pa-pc-rec" class="pa-pc-actions"></div>@endif
  </section>
  <section class="pa-pc-card">
    <div id="pa-pc-booking"></div>
    <div id="pa-pc-shuttle"></div>
  </section>
  <div id="pa-pc-prompt" class="pa-pc-prompt" hidden role="dialog" aria-modal="true" aria-label="Incoming call">
    <div style="max-width:420px;width:100%">
      <div class="halo" aria-hidden="true">📞</div>
      <div style="font-size:12px;letter-spacing:.14em;text-transform:uppercase;opacity:.7">Incoming in-app voice call · <span data-r="secs"></span></div>
      <h2 data-r="title"></h2>
      <p data-r="line" style="opacity:.85;margin:0"></p>
      <p class="nt" data-r="notice"></p>
      <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap">
        <button type="button" class="pa-pc-btn green" data-a="accept" style="min-width:160px;min-height:52px">Accept</button>
        <button type="button" class="pa-pc-btn ghost" data-a="decline" style="min-height:52px">Decline</button>
      </div>
    </div>
  </div>
  <div id="pa-pc-toast" class="pa-pc-toast" hidden role="status"></div>
</div>
<script>window.PA_PCALL = {!! $pcCfgJson !!};</script>
<script src="{{ asset('assets/js/pa-practice-call.js') }}?v=1" defer></script>
