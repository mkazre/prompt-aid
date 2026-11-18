{{-- "A practice / patient is calling you" on any page (patients on the website, practice users in /staff).
     Polls GET /practice-calls/ring (cache-only until a call rings) and opens the call page, where both sides accept.
     Skipped on the call page itself (it answers its own ring). --}}
@php
  $pcRingCfg = ['ringUrl' => route('practice-call.ring'), 'soundUrl' => asset('assets/sounds/request-alert.mp3')];
@endphp
<script>
(function () {
  var C = @json($pcRingCfg);
  if (document.getElementById('pa-pcall')) return;
  var el = null, audio = null, current = null, handled = {};
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function sound(on) { try { if (on) { if (!audio) { audio = new Audio(C.soundUrl); audio.loop = true; } var p = audio.play(); if (p && p.catch) p.catch(function () {}); } else if (audio) { audio.pause(); audio.currentTime = 0; } } catch (e) {} }
  function hide() { if (el) el.style.display = 'none'; sound(false); current = null; }
  function show(r) {
    var key = r.call_id + '@' + r.started_at;
    if (handled[key] || (current && current.call_id === r.call_id)) return;
    current = r;
    if (!el) {
      el = document.createElement('div');
      el.style.cssText = 'position:fixed;inset:0;z-index:2147483000;background:rgba(16,16,18,.95);color:#fff;display:none;align-items:center;justify-content:center;padding:24px;text-align:center;font-family:inherit';
      document.body.appendChild(el);
    }
    el.innerHTML = '<div style="max-width:420px"><div style="font-size:12px;letter-spacing:.14em;text-transform:uppercase;opacity:.7">Incoming in-app voice call</div>' +
      '<h2 style="font-size:26px;margin:10px 0 6px;color:#fff">' + esc(r.caller_name) + ' is calling you</h2>' +
      '<p style="opacity:.85;margin:0 0 8px">' + esc(r.caller_sub || '') + '</p>' +
      (r.notice ? '<p style="font-size:13px;color:#FDBA74;margin:0 0 16px">● ' + esc(r.notice) + '</p>' : '') +
      '<a href="' + esc(r.url) + '" style="display:inline-flex;align-items:center;justify-content:center;min-height:52px;min-width:170px;padding:0 24px;border-radius:999px;background:#1E8E3E;color:#fff;font-weight:900;text-decoration:none">Open the call</a> ' +
      '<button type="button" data-x style="min-height:52px;padding:0 20px;border-radius:999px;background:transparent;color:#fff;border:1px solid rgba(255,255,255,.45);font-weight:700;cursor:pointer;margin-left:8px">Later</button></div>';
    el.querySelector('[data-x]').addEventListener('click', function () { handled[key] = 1; hide(); });
    el.style.display = 'flex';
    sound(true);
  }
  function poll() {
    if (document.hidden) { setTimeout(poll, 15000); return; }
    fetch(C.ringUrl, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) { if (j && j.data) show(j.data); else if (current) hide(); })
      .catch(function () {})
      .then(function () { setTimeout(poll, current ? 4000 : 7000); });
  }
  setTimeout(poll, 2000);
})();
</script>
