<x-layout title="Your request · Prompt Aid">
@php
  $rsCfg = [
      'url' => route('dispatch.request.show', $job),
      'cancelUrl' => route('dispatch.request.cancel', $job),
      'retryUrl' => route('dispatch.request.retry', $job),
      'csrf' => csrf_token(),
      'data' => $payload,
  ];
@endphp
<div class="pa-account">
<x-account-nav active="doctor-now" />
<div>
  @if (session('success'))<div class="pa-note" style="margin-bottom:16px">{{ session('success') }}</div>@endif
  <section class="pa-card">
    <div class="pa-card-head"><h3 id="rs-title">Your request</h3><span class="pa-badge" id="rs-badge"></span></div>
    <div class="pa-card-body" style="text-align:center;padding:28px 18px">
      <div style="font-size:22px;font-weight:900" id="rs-label" aria-live="polite"></div>
      <p class="pa-muted" id="rs-sub" style="margin:8px 0 0"></p>
      <div id="rs-retry" class="pa-hide" style="margin-top:18px">
        <label class="pa-label" for="rs-radius">Try again within</label>
        <select class="pa-field" id="rs-radius" style="max-width:140px;margin:0 auto 10px"></select>
        <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
          <button type="button" class="pa-btn" id="rs-retry-btn">Search again</button>
          <a class="pa-btn-ghost" href="{{ route('doctors.index') }}">Book a time slot</a>
        </div>
      </div>
      <div style="margin-top:18px"><button type="button" class="pa-btn-ghost pa-hide" id="rs-cancel">Cancel request</button>
        <a class="pa-btn pa-hide" id="rs-appts" href="{{ route('account.appointments') }}">View my appointment</a></div>
    </div>
  </section>
</div>
</div>
<script>window.PA_RS = @json($rsCfg);</script>
<script>
(function () {
  var C = window.PA_RS, $ = function (id) { return document.getElementById(id); }, t = null;
  function hide(el, h) { el.classList.toggle('pa-hide', h); }
  function req(method, url, body) {
    return fetch(url, { method: method, credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': C.csrf }, body: body ? JSON.stringify(body) : undefined })
      .then(function (r) { return r.json(); });
  }
  function render(d) {
    $('rs-label').textContent = d.status_label;
    $('rs-badge').textContent = d.status.replace('_', ' ');
    $('rs-sub').textContent = d.window_from ? 'For ' + new Date(d.window_from).toLocaleString([], { weekday: 'short', hour: '2-digit', minute: '2-digit' }) + (d.window_to ? ' – ' + new Date(d.window_to).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '') + (d.capability ? ' · ' + d.capability : '') : '';
    hide($('rs-cancel'), !d.can_cancel);
    hide($('rs-retry'), !d.can_retry);
    hide($('rs-appts'), d.status !== 'accepted');
    var sel = $('rs-radius');
    if (!sel.options.length) (d.radius_options || []).forEach(function (km) { var o = document.createElement('option'); o.value = km; o.textContent = km + ' km'; if (d.radius_km && km >= d.radius_km && !sel.value) o.selected = true; sel.appendChild(o); });
    clearTimeout(t);
    if (d.poll_ms) t = setTimeout(poll, d.poll_ms);
  }
  function poll() { req('GET', C.url).then(function (j) { render(j.data); }).catch(function () { t = setTimeout(poll, 8000); }); }
  $('rs-cancel').addEventListener('click', function () { if (confirm('Cancel this request?')) req('POST', C.cancelUrl, {}).then(function (j) { render(j.data); }); });
  $('rs-retry-btn').addEventListener('click', function () { req('POST', C.retryUrl, { radius_km: Number($('rs-radius').value) || null }).then(function (j) { if (j.data) render(j.data); else alert(j.message || 'Could not retry'); }); });
  render(C.data);
})();
</script>
</x-layout>
