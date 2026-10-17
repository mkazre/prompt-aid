{{-- Provider availability (NOW / LATER today / TOMORROW + max radius) in every panel's top bar.
     Saves to POST /dispatch/availability; refreshed from the alert poller's payload. --}}
@php
    $paAv = app(\App\Services\Dispatch\AvailabilityService::class)->payload(auth()->user());
    $paAvCfg = ['url' => route('dispatch.availability'), 'csrf' => csrf_token(), 'state' => $paAv];
    $paAvOn = (bool) $paAv['now'];
    $paAvBusy = $paAv['availability'] === 'busy';
    $paAvLater = $paAv['later'];
    $paAvTomorrow = $paAv['tomorrow'];
@endphp
<link rel="stylesheet" href="{{ asset('assets/css/pa-dispatch.css') }}?v=1">
<div class="pa-av" id="pa-av" x-ignore>
    <button type="button" class="pa-av-btn" id="pa-av-btn" aria-haspopup="true" aria-expanded="false" title="Your availability for new requests">
        <span class="pa-av-dot {{ $paAvBusy ? 'busy' : ($paAvOn ? 'on' : '') }}" id="pa-av-dot"></span>
        <span class="txt" id="pa-av-label">{{ $paAvBusy ? 'Busy' : ($paAvOn ? 'Available now' : 'Offline') }}</span>
    </button>
    <div class="pa-av-panel" id="pa-av-panel" hidden>
        <div class="pa-av-row">
            <label for="pa-av-now">Available NOW</label>
            <button type="button" class="pa-av-sw {{ $paAvOn ? 'on' : '' }}" id="pa-av-now" role="switch" aria-checked="{{ $paAvOn ? 'true' : 'false' }}"></button>
        </div>
        <p class="pa-av-help">You get a loud alert for every new request you qualify for while this is on and this page (or the app) is open.</p>
        <div class="pa-av-row"><label>LATER today</label><span><input type="time" id="pa-av-lf" value="{{ $paAvLater['from'] ?? '' }}"> – <input type="time" id="pa-av-lt" value="{{ $paAvLater['to'] ?? '' }}"></span></div>
        <div class="pa-av-row"><label>TOMORROW</label><span><input type="time" id="pa-av-tf" value="{{ $paAvTomorrow['from'] ?? '' }}"> – <input type="time" id="pa-av-tt" value="{{ $paAvTomorrow['to'] ?? '' }}"></span></div>
        <div class="pa-av-row"><label for="pa-av-r">Max distance (km)</label><input type="number" id="pa-av-r" min="0" max="500" step="1" placeholder="Any" value="{{ $paAv['max_radius_km'] !== null ? (int) $paAv['max_radius_km'] : '' }}"></div>
        <button type="button" class="pa-av-save" id="pa-av-save">Save times &amp; distance</button>
        <div class="pa-av-msg" id="pa-av-msg" aria-live="polite"></div>
    </div>
</div>
<script>window.PA_AVAIL = @json($paAvCfg);</script>
<script>
(function () {
    if (window.__paAv) return; window.__paAv = true;
    var C = window.PA_AVAIL, $ = function (id) { return document.getElementById(id); };
    function post(body) {
        return fetch(C.url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': C.csrf, 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify(body) })
            .then(function (r) { return r.json().then(function (j) { if (!r.ok) throw new Error(j.message || 'Could not save'); return j; }); });
    }
    function paint(s) {
        if (!s) return;
        var busy = s.availability === 'busy', on = !!s.now;
        $('pa-av-dot').className = 'pa-av-dot ' + (busy ? 'busy' : (on ? 'on' : ''));
        $('pa-av-label').textContent = busy ? 'Busy' : (on ? 'Available now' : 'Offline');
        $('pa-av-now').classList.toggle('on', on);
        $('pa-av-now').setAttribute('aria-checked', on ? 'true' : 'false');
    }
    function msg(t, err) { var m = $('pa-av-msg'); m.textContent = t; m.className = 'pa-av-msg' + (err ? ' err' : ''); }
    document.addEventListener('click', function (e) {
        var root = $('pa-av'); if (!root) return;
        if (e.target.closest && e.target.closest('#pa-av-btn')) { var p = $('pa-av-panel'); p.hidden = !p.hidden; $('pa-av-btn').setAttribute('aria-expanded', p.hidden ? 'false' : 'true'); return; }
        if (!root.contains(e.target)) $('pa-av-panel').hidden = true;
        if (e.target.id === 'pa-av-now') {
            var want = !e.target.classList.contains('on');
            post({ now: want }).then(function (s) { paint(s); msg(want ? 'You are available now.' : 'You are offline.'); if (window.PADispatch) window.PADispatch.poll(true); })
                .catch(function (err) { msg(err.message, true); });
        }
        if (e.target.id === 'pa-av-save') {
            var lf = $('pa-av-lf').value, lt = $('pa-av-lt').value, tf = $('pa-av-tf').value, tt = $('pa-av-tt').value, r = $('pa-av-r').value;
            post({ later: lf && lt ? { from: lf, to: lt } : null, tomorrow: tf && tt ? { from: tf, to: tt } : null, max_radius_km: r === '' ? null : Number(r) })
                .then(function (s) { paint(s); msg('Saved.'); }).catch(function (err) { msg(err.message, true); });
        }
    });
    window.addEventListener('pa-dispatch:availability', function (e) { paint(e.detail); });
    paint(C.state);
})();
</script>
