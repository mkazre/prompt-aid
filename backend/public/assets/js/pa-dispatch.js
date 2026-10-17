/*
 * Prompt Aid — incoming request alert for providers (doctors, drivers,
 * pharmacies, partners) on every Filament panel page and the web driver
 * console.
 *
 *  - Polls GET /dispatch/inbox (3 s while an offer is open, 5 s idle,
 *    10 s when the tab is hidden). The poll is also the provider's
 *    "available now" heartbeat.
 *  - Shows ONE large modal at a time (soonest-expiring first, others queued):
 *    service icon, patient first name, request type, earning, distance/ETA,
 *    rider card, countdown ring, Accept (or swipe-right-to-accept for
 *    shuttle trips) and Decline (optional reason).
 *  - Loops /assets/sounds/request-alert.mp3 until accepted / declined /
 *    expired / taken (WebAudio chime fallback if the file can't play).
 *    Browsers block audio until the first click: a one-time
 *    "Enable sound alerts" button unlocks it.
 *  - Desktop notification + title flash when the tab is hidden.
 *  - "Accepted by another provider — no longer available" when someone
 *    else wins.
 *
 * Config: window.PA_DISPATCH = { inboxUrl, acceptUrl, declineUrl, csrf,
 *   soundUrl, context: 'panel'|'driver', mapEnabled }
 *   Optional hooks: window.PA_DISPATCH_HOOKS = { onAccepted(res, card), onChange(state) }
 */
(function () {
  'use strict';
  var C = window.PA_DISPATCH;
  if (!C || window.__paDispatch) return;
  window.__paDispatch = true;

  function $(id) { return document.getElementById(id); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function money(v) { return v == null ? '' : 'R ' + Number(v).toFixed(Number(v) % 1 ? 2 : 0); }
  function hooks() { return window.PA_DISPATCH_HOOKS || {}; }

  var ICONS = {
    car: '<path d="M5 17h14M6 17l1.5-6h9L18 17"/><circle cx="7.5" cy="17.5" r="1.5"/><circle cx="16.5" cy="17.5" r="1.5"/><path d="M4 13h16"/>',
    stethoscope: '<path d="M6 3v6a4 4 0 0 0 8 0V3"/><path d="M10 13v3a5 5 0 0 0 10 0v-2"/><circle cx="20" cy="12" r="2"/>',
    calendar: '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/>',
    bag: '<path d="M6 7h12l-1 13H7L6 7z"/><path d="M9 7a3 3 0 0 1 6 0"/>',
    file: '<path d="M14 3H6v18h12V7z"/><path d="M14 3v4h4M9 13h6M9 17h6"/>',
    beaker: '<path d="M9 3h6M10 3v6L4 20h16l-6-11V3"/><path d="M7 15h10"/>',
    bell: '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>'
  };
  function icon(name) { return '<svg viewBox="0 0 24 24">' + (ICONS[name] || ICONS.bell) + '</svg>'; }

  // ------------------------------------------------------------ sound
  var audio = null, audioOk = null, ctx = null, beepTimer = null, unlocked = false, playing = false;
  try {
    audio = new Audio(C.soundUrl || '/assets/sounds/request-alert.mp3');
    audio.loop = true; audio.preload = 'auto'; audio.volume = 1;
    audio.addEventListener('error', function () { audioOk = false; });
    audio.addEventListener('canplaythrough', function () { audioOk = true; });
  } catch (e) { audio = null; audioOk = false; }

  var soundBtn = document.createElement('button');
  soundBtn.type = 'button'; soundBtn.className = 'pa-dx-sound'; soundBtn.hidden = true;
  soundBtn.textContent = '🔔 Enable sound alerts for new requests';
  document.addEventListener('DOMContentLoaded', function () { document.body.appendChild(soundBtn); });
  if (document.body) document.body.appendChild(soundBtn);

  function unlock() {
    if (unlocked) return;
    try {
      ctx = ctx || new (window.AudioContext || window.webkitAudioContext)();
      if (ctx.state === 'suspended') ctx.resume();
    } catch (e) {}
    if (audio) {
      // Prime the element inside a user gesture so later play() calls are allowed.
      var v = audio.volume; audio.volume = 0;
      var p = audio.play();
      if (p && p.then) p.then(function () { if (!playing) { audio.pause(); audio.currentTime = 0; } audio.volume = v; unlocked = true; soundBtn.hidden = true; })
        .catch(function () { audio.volume = v; });
      else { audio.volume = v; unlocked = true; }
    } else unlocked = true;
    try { if ('Notification' in window && Notification.permission === 'default') Notification.requestPermission(); } catch (e) {}
  }
  ['click', 'keydown', 'touchstart'].forEach(function (ev) { document.addEventListener(ev, unlock, { passive: true }); });
  soundBtn.addEventListener('click', function () { unlock(); soundBtn.hidden = true; if (playing) startSound(true); });

  function chimeOnce() {
    try {
      ctx = ctx || new (window.AudioContext || window.webkitAudioContext)();
      if (ctx.state !== 'running') return;
      [[659.25, 0], [830.61, 0.14], [987.77, 0.28], [1318.5, 0.42]].forEach(function (n) {
        var o = ctx.createOscillator(), g = ctx.createGain(), t = ctx.currentTime + n[1];
        o.type = 'sine'; o.frequency.setValueAtTime(n[0], t);
        g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(0.45, t + 0.01); g.gain.exponentialRampToValueAtTime(0.0001, t + 0.7);
        o.connect(g).connect(ctx.destination); o.start(t); o.stop(t + 0.75);
      });
    } catch (e) {}
  }
  function startSound(force) {
    if (C.sound === false) return;
    if (playing && !force) return;
    playing = true;
    if (audio && audioOk !== false) {
      audio.currentTime = 0;
      var p = audio.play();
      if (p && p.catch) p.catch(function () { soundBtn.hidden = false; fallbackLoop(); });
      return;
    }
    fallbackLoop();
  }
  function fallbackLoop() {
    clearInterval(beepTimer);
    chimeOnce();
    beepTimer = setInterval(chimeOnce, 2200);
    if (!ctx || ctx.state !== 'running') soundBtn.hidden = false;
  }
  function stopSound() {
    playing = false;
    clearInterval(beepTimer); beepTimer = null;
    try { if (audio) { audio.pause(); audio.currentTime = 0; } } catch (e) {}
  }

  // ------------------------------------------------------------ title flash + desktop notification
  var baseTitle = document.title, flashTimer = null;
  function flash(on, text) {
    clearInterval(flashTimer); document.title = baseTitle;
    if (!on) return;
    var alt = false;
    flashTimer = setInterval(function () { alt = !alt; document.title = alt ? '🔴 ' + text : baseTitle; }, 900);
  }
  var notified = {};
  function desktop(card) {
    if (notified[card.key]) return; notified[card.key] = 1;
    try {
      if (!('Notification' in window) || Notification.permission !== 'granted' || !document.hidden) return;
      var n = new Notification(card.service_label + ': ' + card.title, { body: (card.patient_first_name ? card.patient_first_name + ' · ' : '') + (card.subtitle || ''), tag: 'pa-dx-' + card.key, requireInteraction: true });
      n.onclick = function () { window.focus(); n.close(); };
    } catch (e) {}
  }

  // ------------------------------------------------------------ DOM
  var overlay = document.createElement('div');
  overlay.className = 'pa-dx-overlay'; overlay.hidden = true;
  overlay.setAttribute('role', 'alertdialog'); overlay.setAttribute('aria-modal', 'true'); overlay.setAttribute('aria-live', 'assertive');
  overlay.innerHTML = '<div class="pa-dx-card" id="pa-dx-card"></div>';
  function mount() { if (!overlay.parentNode) document.body.appendChild(overlay); }
  if (document.body) mount(); else document.addEventListener('DOMContentLoaded', mount);

  var current = null, offers = [], recent = {}, dismissed = {}, ringTimer = null, busy = false, stateTimer = null, map = null;

  function ringHtml() {
    return '<div class="pa-dx-ring" aria-hidden="true"><svg viewBox="0 0 56 56"><circle class="bg" cx="28" cy="28" r="24"/><circle class="fg" id="pa-dx-fg" cx="28" cy="28" r="24" stroke-dasharray="150.8" stroke-dashoffset="0"/></svg><span id="pa-dx-secs"></span></div>';
  }

  function riderHtml(r) {
    if (!r) return '';
    var v = r.verified || {};
    var chips = [['phone', 'Phone'], ['id', 'ID'], ['medical_aid', 'Medical aid']].map(function (k) {
      return '<span class="pa-dx-chip ' + (v[k[0]] ? 'ok' : 'no') + '">' + (v[k[0]] ? '✓ ' : '') + k[1] + (v[k[0]] ? ' verified' : ' not verified') + '</span>';
    }).join('');
    var av = r.photo_url ? '<img src="' + esc(r.photo_url) + '" alt="">' : esc(r.initials || '?');
    var needs = (r.needs || []).length ? '<div class="pa-dx-need">♿ ' + r.needs.map(esc).join(' · ') + '</div>' : '';
    return '<div class="pa-dx-rider"><div class="pa-dx-avatar">' + av + '</div><div style="min-width:0"><b>' + esc(r.full_name || r.first_name) + '</b> · ' + esc(r.rating_label) +
      '<div class="r-sub">' + (r.trips_completed || 0) + ' trip' + (r.trips_completed === 1 ? '' : 's') + (r.member_since ? ' · member since ' + esc(r.member_since) : '') + '</div><div>' + chips + '</div></div></div>' + needs +
      (r.pickup_notes ? '<div class="pa-dx-need" style="background:#F7F5F0;border-color:#ECE7DC">📝 ' + esc(r.pickup_notes) + '</div>' : '');
  }

  function render(card) {
    var el = $('pa-dx-card');
    var badges = (card.badges || []).map(function (b) {
      var cls = /emergency|urgent/i.test(b) ? ' red' : (/peak|wheelchair|stretcher|scheduled|return|recurring/i.test(b) ? ' hot' : '');
      return '<span class="pa-dx-badge' + cls + '">' + esc(b) + '</span>';
    }).join('');
    var meta = [];
    if (card.distance_km != null) meta.push(Number(card.distance_km).toFixed(1) + ' km away');
    if (card.eta_min != null) meta.push(card.eta_min + ' min to pickup');
    if (card.trip_km) meta.push('Trip ' + Number(card.trip_km).toFixed(1) + ' km' + (card.trip_min ? ' · ~' + card.trip_min + ' min' : ''));
    if (card.when) meta.push(new Date(card.when).toLocaleString([], { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }));
    var route = card.pickup ? '<div class="pa-dx-route"><span class="dot"></span><div>' + esc(card.pickup.address) + '</div>' +
      (card.distance_km != null ? '<div class="sub">' + Number(card.distance_km).toFixed(1) + ' km' + (card.eta_min != null ? ' · ' + card.eta_min + ' min' : '') + ' from you</div>' : '') +
      '<span class="dot to"></span><div>' + esc(card.dropoff.address) + '</div></div>' : '';
    var lines = (card.lines || []).length ? '<ul class="pa-dx-lines">' + card.lines.map(function (l) { return '<li>' + esc(l) + '</li>'; }).join('') + '</ul>' : '';
    var queued = offers.length > 1 ? '<div class="pa-dx-queue">+' + (offers.length - 1) + ' more waiting</div>' : '';
    var accept = card.swipe
      ? '<div class="pa-dx-swipe" id="pa-dx-swipe" role="slider" aria-label="Swipe right to accept" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" tabindex="0"><div class="fill"></div><div class="label">Swipe right to accept ›››</div><div class="thumb"><svg viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg></div></div>'
      : '<button type="button" class="pa-dx-btn pa-dx-accept" id="pa-dx-accept">Accept</button>';

    el.innerHTML =
      '<div class="pa-dx-head"><div class="pa-dx-icon">' + icon(card.icon) + '</div><div style="min-width:0"><div class="pa-dx-kicker">' + esc(card.service_label) + '</div>' +
      '<div class="pa-dx-title">' + esc(card.title) + '</div>' + queued + '</div>' + ringHtml() + '</div>' +
      '<div class="pa-dx-body">' + (badges ? '<div class="pa-dx-badges">' + badges + '</div>' : '') +
      '<div class="pa-dx-money"><div class="pa-dx-earn">' + (card.earning != null ? '<small>' + (card.service === 'shuttle' ? 'Trip earning' : 'Fee') + '</small>' + money(card.earning) : '<small>Patient</small>' + esc(card.patient_first_name || '')) + '</div>' +
      '<div class="pa-dx-meta">' + (card.patient_first_name && card.earning != null && !card.rider ? '<b>' + esc(card.patient_first_name) + '</b><br>' : '') + esc(meta.join(' · ')) + '</div></div>' +
      (card.service !== 'shuttle' && card.subtitle ? '<div style="font-weight:800;font-size:15px;margin-bottom:8px">' + esc(card.subtitle) + '</div>' : '') +
      route + riderHtml(card.rider) + lines +
      (C.mapEnabled && card.pickup && window.L ? '<div class="pa-dx-map" id="pa-dx-map"></div>' : '') +
      '</div><div class="pa-dx-actions">' + accept +
      '<button type="button" class="pa-dx-btn pa-dx-decline" id="pa-dx-decline">Decline</button>' +
      '<div class="pa-dx-reasons" id="pa-dx-reasons">' + ['Too far', 'Busy right now', 'Not my service', 'Other'].map(function (r) { return '<button type="button" data-r="' + esc(r) + '">' + esc(r) + '</button>'; }).join('') +
      '<button type="button" data-r="">Skip</button></div></div>';

    if (card.swipe) bindSwipe($('pa-dx-swipe'), function () { doAccept(card); });
    else $('pa-dx-accept').addEventListener('click', function () { doAccept(card); });
    $('pa-dx-decline').addEventListener('click', function () {
      var rs = $('pa-dx-reasons');
      if (!rs.classList.contains('on')) { rs.classList.add('on'); this.textContent = 'Decline — choose a reason (optional)'; return; }
      doDecline(card, '');
    });
    $('pa-dx-reasons').querySelectorAll('button').forEach(function (b) { b.addEventListener('click', function () { doDecline(card, b.getAttribute('data-r')); }); });
    drawMap(card);
    startRing(card);
  }

  function drawMap(card) {
    if (map) { try { map.remove(); } catch (e) {} map = null; }
    var box = $('pa-dx-map');
    if (!box || !window.L) return;
    try {
      map = L.map(box, { zoomControl: false, attributionControl: false, dragging: false, scrollWheelZoom: false });
      L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', { maxZoom: 19 }).addTo(map);
      var a = [card.pickup.lat, card.pickup.lng], b = [card.dropoff.lat, card.dropoff.lng];
      L.circleMarker(a, { radius: 8, color: '#fff', weight: 3, fillColor: '#1E8E3E', fillOpacity: 1 }).addTo(map);
      L.circleMarker(b, { radius: 8, color: '#fff', weight: 3, fillColor: '#D0211C', fillOpacity: 1 }).addTo(map);
      L.polyline([a, b], { color: '#101012', weight: 3, opacity: 0.5, dashArray: '6 6' }).addTo(map);
      map.fitBounds([a, b], { padding: [24, 24], maxZoom: 15 });
    } catch (e) {}
  }

  function startRing(card) {
    clearInterval(ringTimer);
    var deadline = Date.now() + Math.max(0, card.seconds_left) * 1000;
    var total = Math.max(1, card.timeout_s || card.seconds_left || 30);
    var tick = function () {
      var left = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
      var s = $('pa-dx-secs'), fg = $('pa-dx-fg');
      if (s) s.textContent = left > 99 ? Math.ceil(left / 60) + 'm' : left;
      if (fg) fg.setAttribute('stroke-dashoffset', String(150.8 * (1 - Math.min(1, left / total))));
      if (left <= 0) { clearInterval(ringTimer); showState(card, 'expired'); }
    };
    tick(); ringTimer = setInterval(tick, 250);
  }

  function bindSwipe(track, done) {
    if (!track) return;
    var thumb = track.querySelector('.thumb'), fill = track.querySelector('.fill');
    var startX = 0, x = 0, max = 0, dragging = false, finished = false;
    function setX(v) { x = Math.max(0, Math.min(max, v)); thumb.style.left = (4 + x) + 'px'; fill.style.width = (x + 32) + 'px'; track.setAttribute('aria-valuenow', String(Math.round(x / Math.max(1, max) * 100))); }
    function down(e) { if (finished) return; dragging = true; max = track.clientWidth - 64; startX = (e.touches ? e.touches[0].clientX : e.clientX) - x; thumb.style.transition = 'none'; unlock(); e.preventDefault(); }
    function move(e) { if (!dragging) return; setX((e.touches ? e.touches[0].clientX : e.clientX) - startX); }
    function up() {
      if (!dragging) return; dragging = false;
      if (x >= max * 0.85) { finished = true; setX(max); track.classList.add('done'); try { if (navigator.vibrate) navigator.vibrate(60); } catch (e) {} done(); }
      else { thumb.style.transition = 'left .2s'; setX(0); }
    }
    thumb.addEventListener('mousedown', down); thumb.addEventListener('touchstart', down, { passive: false });
    window.addEventListener('mousemove', move); window.addEventListener('touchmove', move, { passive: true });
    window.addEventListener('mouseup', up); window.addEventListener('touchend', up);
    // Keyboard: hold → (Right arrow) 3 times to accept — accessible but accident-safe.
    var presses = 0;
    track.addEventListener('keydown', function (e) {
      if (e.key !== 'ArrowRight' || finished) return;
      max = track.clientWidth - 64; presses++; setX(max * presses / 3);
      if (presses >= 3) { finished = true; track.classList.add('done'); done(); }
    });
  }

  function showState(card, state, msg) {
    clearInterval(ringTimer); stopSound(); flash(false);
    var titles = {
      taken: ['Accepted by another provider', 'This request is no longer available.'],
      expired: ['Request expired', 'You did not respond in time.'],
      cancelled: ['Request cancelled', 'The patient or our team cancelled this request.'],
      accepted: ['Accepted ✓', msg || 'The patient has been notified.'],
      error: ['Could not accept', msg || 'Please try again.']
    };
    var t = titles[state] || titles.expired;
    var colour = state === 'accepted' ? '#1E8E3E' : (state === 'taken' ? '#E4701E' : '#6B675F');
    $('pa-dx-card').innerHTML = '<div class="pa-dx-state"><div class="pa-dx-icon" style="margin:0 auto;animation:none;background:' + colour + '">' + icon(card.icon) + '</div><h3>' + esc(t[0]) + '</h3><p>' + esc(t[1]) + '</p></div>';
    dismissed[card.key] = 1;
    current = null;
    clearTimeout(stateTimer);
    stateTimer = setTimeout(function () { overlay.hidden = true; next(); }, state === 'accepted' ? 1400 : 2600);
  }

  function post(url, body) {
    return fetch(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': C.csrf || '', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify(body || {})
    }).then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { j.__status = r.status; return j; }); });
  }

  function doAccept(card) {
    if (busy) return; busy = true; stopSound();
    post(C.acceptUrl.replace('__KEY__', encodeURIComponent(card.key))).then(function (res) {
      busy = false;
      if (res.__status >= 200 && res.__status < 300) {
        showState(card, 'accepted', res.message);
        var h = hooks();
        if (h.onAccepted) { try { h.onAccepted(res, card); } catch (e) {} }
        else if (res.next && res.next.url && C.context === 'panel') setTimeout(function () { window.location.href = res.next.url; }, 1200);
      } else if (res.__status === 409) {
        showState(card, /another/i.test(res.message || '') ? 'taken' : 'expired');
      } else {
        showState(card, 'error', res.message);
      }
      poll(true);
    }).catch(function () { busy = false; showState(card, 'error', 'Network problem — check your connection.'); });
  }

  function doDecline(card, reason) {
    if (busy) return; busy = true; stopSound();
    post(C.declineUrl.replace('__KEY__', encodeURIComponent(card.key)), { reason: reason || null }).then(function () {
      busy = false; dismissed[card.key] = 1; current = null; overlay.hidden = true; clearInterval(ringTimer); flash(false); next(); poll(true);
    }).catch(function () { busy = false; });
  }

  function next() {
    var pending = offers.filter(function (o) { return !dismissed[o.key] && o.state === 'pending' && o.seconds_left > 0; });
    if (!pending.length) { stopSound(); flash(false); if (!current) overlay.hidden = true; return; }
    if (current && pending.some(function (o) { return o.key === current.key; })) return;
    current = pending[0];
    mount(); overlay.hidden = false;
    render(current);
    startSound();
    desktop(current);
    if (document.hidden) flash(true, 'New ' + current.service_label);
    if (hooks().onChange) { try { hooks().onChange({ open: true, card: current }); } catch (e) {} }
  }

  // ------------------------------------------------------------ poll
  var pollTimer = null, lastPollMs = 5000, coords = null;
  if (C.shareLocation && navigator.geolocation) {
    try { navigator.geolocation.getCurrentPosition(function (p) { coords = p.coords; }, function () {}, { maximumAge: 300000, timeout: 15000 }); } catch (e) {}
  }
  function poll(soon) {
    clearTimeout(pollTimer);
    var url = C.inboxUrl + (coords ? (C.inboxUrl.indexOf('?') === -1 ? '?' : '&') + 'lat=' + coords.latitude + '&lng=' + coords.longitude : '');
    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (!data) return;
        if (data.sound === false) C.sound = false;
        lastPollMs = data.poll_ms || 5000;
        offers = data.offers || [];
        (data.recent || []).forEach(function (r) { recent[r.key] = r; });
        // The open popup's offer disappeared: someone else took it / it was withdrawn.
        if (current && !offers.some(function (o) { return o.key === current.key; }) && !busy) {
          var r = recent[current.key];
          showState(current, r ? r.state : 'expired');
        } else {
          next();
        }
        window.dispatchEvent(new CustomEvent('pa-dispatch:availability', { detail: data.availability }));
      })
      .catch(function () {})
      .then(function () { pollTimer = setTimeout(poll, document.hidden ? Math.max(10000, lastPollMs) : lastPollMs); });
  }
  document.addEventListener('visibilitychange', function () { if (!document.hidden) { flash(false); poll(true); } });
  window.PADispatch = { poll: poll, unlock: unlock };
  poll(true);
})();
