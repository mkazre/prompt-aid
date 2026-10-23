/* ======================= INCOMING VIDEO CONSULT =======================
 * Patients, every website page: when a doctor accepts an on-demand video
 * consult or presses Start on a booked one, the server "rings" the patient
 * (GET /account/consult-ring — cache-only until something is ringing).
 * Shows a full-screen "Dr X is ready — joining your video consult…" with a
 * ringtone + vibration, then opens the in-page call
 * (/account/appointments/{id}/call) after auto_join_seconds. "Join now" is
 * always visible (browsers can block the ringtone until the page has been
 * clicked); "Not now" declines and tells the doctor.
 *
 * window.PromptAidRing.show(ring) lets the See-a-doctor page open it
 * straight from its own status poll. Config: window.PA_RING.
 * ===================================================================== */
(function () {
  'use strict';
  var C = window.PA_RING;
  if (!C || !C.ringUrl) return;

  var el = null, audio = null, timer = null, countdown = null, current = null;
  var handled = {}; // appointment_id + started_at already joined / declined in this tab
  var pollTimer = null;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function initials(name) {
    return String(name || '').replace(/^Dr\.?\s*/, '').split(/\s+/).filter(Boolean).slice(0, 2).map(function (w) { return w.charAt(0).toUpperCase(); }).join('');
  }
  function key(r) { return r.appointment_id + '@' + r.started_at; }

  function post(url) {
    return fetch(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-CSRF-TOKEN': C.csrf || '', 'X-Requested-With': 'XMLHttpRequest' }
    }).catch(function () {});
  }

  function build() {
    if (el) return el;
    el = document.createElement('div');
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-modal', 'true');
    el.setAttribute('aria-label', 'Incoming video consult');
    el.style.cssText = 'position:fixed;inset:0;z-index:2147483000;background:rgba(16,16,18,.95);color:#fff;display:none;align-items:center;justify-content:center;padding:24px;text-align:center;font-family:inherit';
    el.innerHTML =
      '<div style="max-width:420px;width:100%">' +
        '<div data-r="photo" style="width:112px;height:112px;border-radius:50%;margin:0 auto 18px;background:#2a2a2e;display:flex;align-items:center;justify-content:center;font-size:40px;font-weight:900;overflow:hidden;box-shadow:0 0 0 10px rgba(30,142,62,.25),0 0 0 22px rgba(30,142,62,.12)"></div>' +
        '<div data-r="eyebrow" style="font-size:12px;letter-spacing:.14em;text-transform:uppercase;opacity:.7">Incoming video consult</div>' +
        '<h2 data-r="title" style="font-size:26px;margin:8px 0 6px;color:#fff"></h2>' +
        '<p data-r="line" style="margin:0 0 24px;opacity:.85"></p>' +
        '<div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap">' +
          '<a data-r="join" href="#" style="display:inline-flex;align-items:center;justify-content:center;min-height:52px;min-width:170px;padding:0 24px;border-radius:999px;background:#1E8E3E;color:#fff;font-weight:900;font-size:17px;text-decoration:none">Join now</a>' +
          '<button data-r="decline" type="button" style="min-height:52px;padding:0 22px;border-radius:999px;background:transparent;color:#fff;border:1px solid rgba(255,255,255,.45);font-weight:700;font-size:15px;cursor:pointer">Not now</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(el);
    el.querySelector('[data-r="join"]').addEventListener('click', function (e) { e.preventDefault(); join(); });
    el.querySelector('[data-r="decline"]').addEventListener('click', decline);
    return el;
  }

  function startSound() {
    try {
      if (!audio && C.soundUrl) { audio = new Audio(C.soundUrl); audio.loop = true; }
      if (audio) { var p = audio.play(); if (p && p.catch) p.catch(function () { /* autoplay blocked — the Join button is there */ }); }
    } catch (e) {}
    try { if (navigator.vibrate) navigator.vibrate([600, 400, 600, 400, 600]); } catch (e) {}
  }
  function stopSound() {
    try { if (audio) { audio.pause(); audio.currentTime = 0; } } catch (e) {}
    try { if (navigator.vibrate) navigator.vibrate(0); } catch (e) {}
  }

  function hide() {
    clearTimeout(timer); clearInterval(countdown); stopSound();
    if (el) el.style.display = 'none';
    current = null;
  }

  function join() {
    if (!current) return;
    var r = current;
    handled[key(r)] = 1;
    hide();
    // Consult hub (channel-aware: video or in-app voice); older servers: the call page.
    var target = r.hub_url || r.call_url;
    if (target) window.location.href = target; // opening the hub / call page marks the ring answered
  }

  function decline() {
    if (!current) return;
    var r = current;
    handled[key(r)] = 1;
    hide();
    post(C.declineUrl.replace('__ID__', encodeURIComponent(r.appointment_id)));
  }

  function show(r) {
    if (!r || !r.appointment_id || handled[key(r)]) return;
    // The consult hub page for this appointment answers its own ring inline.
    if (window.PA_HUB_APPT && Number(window.PA_HUB_APPT) === Number(r.appointment_id)) return;
    if (current && key(current) === key(r)) return;
    // Already on this call's page: nothing to ring.
    if (r.call_url && window.location.href.split('#')[0].split('?')[0] === r.call_url.split('?')[0]) return;
    hide();
    current = r;
    build();
    var photo = el.querySelector('[data-r="photo"]');
    photo.innerHTML = r.doctor_photo_url ? '<img src="' + esc(r.doctor_photo_url) + '" alt="" style="width:100%;height:100%;object-fit:cover">' : esc(initials(r.doctor_name));
    var voice = r.channel === 'voice';
    el.querySelector('[data-r="eyebrow"]').textContent = voice ? 'Incoming voice call (in-app)' : 'Incoming video consult';
    el.setAttribute('aria-label', voice ? 'Incoming voice call' : 'Incoming video consult');
    el.querySelector('[data-r="title"]').textContent = (r.doctor_name || 'Your doctor') + (voice ? ' is calling you' : ' is ready');
    var line = el.querySelector('[data-r="line"]');
    var secs = Math.max(0, Number(r.auto_join_seconds || 0));
    el.querySelector('[data-r="join"]').setAttribute('href', r.hub_url || r.call_url || '#');
    el.querySelector('[data-r="join"]').textContent = voice ? 'Answer' : 'Join now';
    el.style.display = 'flex';
    startSound();
    function tick() {
      var what = voice ? 'voice call' : 'video consult';
      line.textContent = secs > 0 ? 'Joining your ' + what + ' in ' + secs + 's…' : 'Joining your ' + what + '…';
    }
    tick();
    countdown = setInterval(function () { secs = Math.max(0, secs - 1); tick(); }, 1000);
    timer = setTimeout(join, Math.max(0, Number(r.auto_join_seconds || 0)) * 1000 + 300);
  }

  function poll() {
    clearTimeout(pollTimer);
    if (document.hidden) { pollTimer = setTimeout(poll, 15000); return; }
    fetch(C.ringUrl, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) {
        if (j && j.data) show(j.data);
        else if (current && j && !j.data) hide(); // stopped ringing (doctor left / timed out)
      })
      .catch(function () {})
      .then(function () { pollTimer = setTimeout(poll, current ? 4000 : 8000); });
  }

  document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
  window.PromptAidRing = { show: show, hide: hide, poll: poll };
  setTimeout(poll, 1500);
})();
/* ===================== END INCOMING VIDEO CONSULT ===================== */
