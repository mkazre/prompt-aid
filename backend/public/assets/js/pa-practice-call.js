/* ======================= PRACTICE CALL (orange) =======================
 * One file for both sides of the in-app call between a practice and an
 * urgent (orange) patient:
 *   - patient website page  /account/practice-calls/{id}       (role patient)
 *   - staff panel page      /staff/practice-calls/{id}         (role practice)
 * Polls GET {base} (state), shows the accept / decline prompt with ringtone
 * on BOTH sides, the recording notice, then the Jitsi audio call (built with
 * pa-video-call.js). Practice side: booking capture (GP, date, free slots,
 * in-person / video), shuttle offer, recording (JaaS file recording or the
 * in-browser fallback), end / ring again. Patient side: booking card, share
 * location, choose a shuttle and pay.
 * Config: window.PA_PCALL = {base, stateUrl, csrf, role, soundUrl, videoJs, videoCss}.
 * ===================================================================== */
(function () {
  'use strict';
  var C = window.PA_PCALL;
  var root = document.getElementById('pa-pcall');
  if (!C || !root) return;

  var S = null, audio = null, pollTimer = null, stageBuilt = false, busy = false, inCall = false;
  var slotsCache = null, rec = null, recStarted = false, jaasRecStarted = false;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function $(id) { return document.getElementById(id); }
  function money(n) { return 'R ' + Number(n || 0).toFixed(2); }
  function fmtDur(s) { s = Math.max(0, s | 0); var m = Math.floor(s / 60), x = s % 60; return (m < 10 ? '0' : '') + m + ':' + (x < 10 ? '0' : '') + x; }

  function req(method, path, body, isForm) {
    var opts = { method: method, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': C.csrf || '' } };
    if (body && !isForm) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    if (body && isForm) opts.body = body;
    return fetch(C.base + path, opts).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) { j.__status = r.status; return j; });
    });
  }

  function toast(msg, bad) {
    var t = $('pa-pc-toast');
    if (!t) return;
    t.textContent = msg; t.className = 'pa-pc-toast' + (bad ? ' bad' : ''); t.hidden = false;
    clearTimeout(t._t); t._t = setTimeout(function () { t.hidden = true; }, 5000);
  }

  function startSound() {
    try { if (!audio && C.soundUrl) { audio = new Audio(C.soundUrl); audio.loop = true; } if (audio) { var p = audio.play(); if (p && p.catch) p.catch(function () {}); } } catch (e) {}
    try { if (navigator.vibrate) navigator.vibrate([600, 400, 600, 400, 600]); } catch (e) {}
  }
  function stopSound() { try { if (audio) { audio.pause(); audio.currentTime = 0; } } catch (e) {} try { if (navigator.vibrate) navigator.vibrate(0); } catch (e) {} }

  // ------------------------------------------------------------ poll
  function poll() {
    clearTimeout(pollTimer);
    fetch(C.stateUrl + (C.role === 'practice' ? '?mode=staff' : ''), { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json().then(function (j) { j.__status = r.status; return j; }); })
      .then(function (j) {
        if (j.__status === 404) { root.innerHTML = '<div class="pa-pc-card"><h2>Call not found</h2></div>'; return; }
        if (j.data) render(j.data);
      })
      .catch(function () {})
      .then(function () { pollTimer = setTimeout(poll, S && S.status === 'ringing' ? 2000 : 4000); });
  }

  // ------------------------------------------------------------ render
  function render(d) {
    var prev = S; S = d;
    if (d.newer_call_id && !d.connected && d.status !== 'ringing') {
      var url = location.pathname.replace(/\/(\d+)(\/?)$/, '/' + d.newer_call_id + '$2');
      if (url !== location.pathname) { location.href = url; return; }
    }
    // Ringing prompt
    if (d.needs_my_accept) { showPrompt(d); } else { hidePrompt(); }
    $('pa-pc-status').textContent = d.status_text;
    $('pa-pc-status').className = 'pa-pc-status s-' + d.status;
    var notice = $('pa-pc-notice');
    if (d.recorded && d.notice) { notice.hidden = false; notice.querySelector('span').textContent = d.notice; } else notice.hidden = true;

    // Who
    var who = C.role === 'practice'
      ? '<b>' + esc(d.patient.name) + '</b>' + (d.patient.phone ? ' · ' + esc(d.patient.phone) : '') +
        (d.patient.brief && d.patient.brief.length ? '<ul class="pa-pc-brief">' + d.patient.brief.map(function (l) { return '<li>' + esc(l) + '</li>'; }).join('') + '</ul>' : '')
      : '<b>' + esc(d.practice.name) + '</b>' + (d.practice.address ? '<div class="sub">' + esc(d.practice.address) + '</div>' : '') + (d.doctor ? '<div class="sub">For ' + esc(d.doctor.name) + '</div>' : '');
    $('pa-pc-who').innerHTML = who;

    // Call stage
    if (d.join && d.join.config && !stageBuilt) buildStage(d.join.config);
    if (d.join && d.join.error) $('pa-pc-stage-msg').textContent = d.join.error;
    var ended = ['ended', 'declined', 'missed', 'cancelled'].indexOf(d.status) !== -1;
    $('pa-pc-actions').innerHTML =
      (d.connected || d.status === 'ringing' ? '<button type="button" class="pa-pc-btn red" data-a="end">Hang up</button>' : '') +
      (C.role === 'practice' && d.can_ring_again ? '<button type="button" class="pa-pc-btn green" data-a="again">Call the patient again</button>' : '') +
      (C.role === 'practice' && (d.connected) && !d.booking ? '<label class="pa-pc-chk"><input type="checkbox" id="pa-pc-close"> Close the request without a booking when I hang up</label>' : '') +
      (C.role === 'patient' && !ended ? '<button type="button" class="pa-pc-btn ghost" data-a="loc">Share my location (for a shuttle)</button>' : '');
    if (ended && stageBuilt) { var st = $('pa-pc-stage'); if (st) st.classList.add('is-ended'); }

    renderBooking(d, prev);
    renderShuttle(d);
    if (C.role === 'practice') maybeRecord(d);
  }

  function showPrompt(d) {
    var p = $('pa-pc-prompt');
    if (!p.hidden && p.dataset.id === String(d.id)) { p.querySelector('[data-r=secs]').textContent = d.ring_seconds_left + 's'; return; }
    p.dataset.id = String(d.id);
    p.querySelector('[data-r=title]').textContent = C.role === 'patient' ? (d.practice.name + ' is calling you') : ('Call with ' + d.patient.name);
    p.querySelector('[data-r=line]').textContent = C.role === 'patient' ? 'They will book your visit with you on this in-app call (uses data, not airtime).' : 'Answer to talk to the patient and book the visit.';
    p.querySelector('[data-r=notice]').textContent = d.recorded && d.notice ? '● ' + d.notice : '';
    p.querySelector('[data-r=secs]').textContent = d.ring_seconds_left + 's';
    p.hidden = false;
    startSound();
  }
  function hidePrompt() { var p = $('pa-pc-prompt'); if (!p.hidden) { p.hidden = true; stopSound(); } }

  function buildStage(cfg) {
    stageBuilt = true;
    var box = $('pa-pc-stage');
    box.innerHTML = '<div class="pa-call pa-call--compact" data-pa-call data-mode="' + esc(cfg.mode) + '"><script type="application/json" data-call-config>' + JSON.stringify(cfg).replace(/</g, '\\u003c') + '<\/script>' +
      '<header class="pa-call__top"><div class="pa-call__who"><span class="pa-call__dot"></span><div style="min-width:0"><span class="pa-call__name">' + esc(cfg.counterpart) + '</span><span class="pa-call__status" data-call-status role="status" aria-live="polite">Connecting…</span></div></div><span class="pa-call__timer" data-call-timer>00:00</span></header>' +
      '<div class="pa-call__body"><div class="pa-call__stage" data-call-stage></div><div class="pa-call__overlay" data-call-overlay><div class="pa-call__spinner" data-call-overlay-spin></div><p data-call-overlay-msg>Connecting securely…</p><div class="pa-call__overlay-actions" data-call-overlay-actions></div></div></div>' +
      '<footer class="pa-call__controls" role="toolbar"><button type="button" class="pa-call__btn" data-call-mic aria-label="Mute microphone" title="Mute"><svg class="ico-on" viewBox="0 0 24 24"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/></svg><svg class="ico-off" viewBox="0 0 24 24"><line x1="1" y1="1" x2="23" y2="23"/><path d="M9 9v3a3 3 0 0 0 5.12 2.12M15 9.34V4a3 3 0 0 0-5.94-.6"/></svg></button></footer></div>';
    var el = box.querySelector('[data-pa-call]');
    el.addEventListener('pacall', function (e) {
      var m = e.detail || {};
      if (m.type === 'joined') { inCall = true; req('POST', '/presence', { state: 'in' }); }
      if (m.type === 'ended') { inCall = false; req('POST', '/presence', { state: 'out' }); stopRecorder(true); }
    });
    if (C.videoCss && !document.querySelector('link[data-pa-vc]')) { var l = document.createElement('link'); l.rel = 'stylesheet'; l.href = C.videoCss; l.setAttribute('data-pa-vc', '1'); document.head.appendChild(l); }
    var s = document.createElement('script'); s.src = C.videoJs; document.body.appendChild(s);
  }

  // ------------------------------------------------------------ booking
  function renderBooking(d, prev) {
    var box = $('pa-pc-booking');
    if (!box) return;
    var b = d.booking;
    var card = b ? '<div class="pa-pc-booked"><div class="k">' + (C.role === 'patient' ? 'Your booking' : 'Booked') + '</div><b>' + esc(b.mode_label) + ' · ' + esc(b.when_label) + '</b><div class="sub">' + esc(b.doctor_name || '') + (b.practice_name ? ' · ' + esc(b.practice_name) : '') + '</div>' +
      (b.practice_address && b.mode === 'in_person' ? '<div class="sub">' + esc(b.practice_address) + '</div>' : '') +
      '<div class="sub">Ref ' + esc(b.booking_ref) + ' · on ' + (C.role === 'patient' ? 'your' : 'the doctor\'s and the patient\'s') + ' calendar</div>' +
      (C.role === 'patient' && b.ics_url ? '<a class="pa-pc-link" href="' + esc(b.ics_url) + '">Add to my phone calendar (.ics)</a>' : '') + '</div>' : '';
    if (C.role !== 'practice') { box.innerHTML = card || '<p class="pa-pc-muted">The practice will book your visit with you during the call. It appears here straight away.</p>'; return; }
    var o = d.booking_options;
    if (!o) { box.innerHTML = card; return; }
    if (box.dataset.ready === '1') { var c = $('pa-pc-booked-card'); if (c) c.innerHTML = card; return; }
    box.dataset.ready = '1';
    var doctors = o.doctors.map(function (x) { return '<option value="' + x.id + '"' + (x.id === (b ? b.doctor_id : o.default_doctor_id) ? ' selected' : '') + '>' + esc(x.name) + (x.availability === 'offline' ? ' (offline)' : '') + '</option>'; }).join('');
    var modes = o.modes.map(function (m) { var on = (b ? b.mode : o.default_mode) === m; return '<label class="pa-pc-radio"><input type="radio" name="pa-pc-mode" value="' + m + '"' + (on ? ' checked' : '') + '> ' + (m === 'video' ? 'Video consult' : 'In-person consult') + '</label>'; }).join('');
    box.innerHTML = '<div id="pa-pc-booked-card">' + card + '</div>' +
      '<h3>Book the appointment</h3>' +
      '<div class="pa-pc-grid"><label>Doctor<select id="pa-pc-doc">' + doctors + '</select></label>' +
      '<label>Date<input type="date" id="pa-pc-date" min="' + esc(o.today) + '" value="' + esc(b ? b.date : o.today) + '"></label></div>' +
      '<div class="pa-pc-modes">' + modes + '</div>' +
      '<div id="pa-pc-slots" class="pa-pc-slots"><span class="pa-pc-muted">Loading free times…</span></div>' +
      '<label class="pa-pc-chk"><input type="checkbox" id="pa-pc-outside"> Book outside the doctor\'s sessions at <input type="time" id="pa-pc-time" style="width:110px"></label>' +
      '<label>Reason for the visit<input type="text" id="pa-pc-reason" maxlength="500" value="' + esc(b && b.reason ? b.reason : '') + '"></label>' +
      '<label>Notes for the practice (not shown to the patient)<textarea id="pa-pc-notes" rows="2" maxlength="1000">' + esc(b && b.notes ? b.notes : '') + '</textarea></label>' +
      '<button type="button" class="pa-pc-btn green" data-a="book">' + (b ? 'Update booking' : 'Save booking') + '</button>';
    $('pa-pc-doc').addEventListener('change', loadSlots);
    $('pa-pc-date').addEventListener('change', loadSlots);
    loadSlots();
  }

  var chosenSlot = null;
  function loadSlots() {
    var doc = $('pa-pc-doc').value, date = $('pa-pc-date').value, box = $('pa-pc-slots');
    chosenSlot = S && S.booking && S.booking.date === date && String(S.booking.doctor_id) === String(doc) ? S.booking.start_time : null;
    box.innerHTML = '<span class="pa-pc-muted">Loading free times…</span>';
    fetch(C.base + '/slots?doctor_id=' + encodeURIComponent(doc) + '&date=' + encodeURIComponent(date), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.data) { box.innerHTML = '<span class="pa-pc-muted">' + esc(j.message || 'Could not load times.') + '</span>'; return; }
        var d = j.data;
        if (d.time_off) { box.innerHTML = '<span class="pa-pc-muted">The doctor is off: ' + esc(d.time_off) + '</span>'; return; }
        var all = [];
        d.sessions.forEach(function (s) { s.slots.forEach(function (sl) { all.push(sl); }); });
        if (!all.length) { box.innerHTML = '<span class="pa-pc-muted">No sessions on this day — tick "Book outside the doctor\'s sessions" to choose a time.</span>'; return; }
        box.innerHTML = all.map(function (sl) {
          var cls = sl.available ? '' : ' off'; if (chosenSlot === sl.time) cls += ' on';
          return '<button type="button" class="pa-pc-slot' + cls + '" data-t="' + sl.time + '"' + (sl.available || chosenSlot === sl.time ? '' : ' disabled') + '>' + sl.time + '</button>';
        }).join('');
        box.querySelectorAll('[data-t]').forEach(function (bt) {
          bt.addEventListener('click', function () { chosenSlot = bt.getAttribute('data-t'); box.querySelectorAll('.on').forEach(function (x) { x.classList.remove('on'); }); bt.classList.add('on'); });
        });
      });
  }

  function saveBooking() {
    var outside = $('pa-pc-outside').checked;
    var time = outside ? $('pa-pc-time').value : chosenSlot;
    var mode = (root.querySelector('input[name=pa-pc-mode]:checked') || {}).value;
    if (!time) { toast('Choose a free time (or tick "outside the sessions" and enter a time).', true); return; }
    busy = true;
    req('POST', '/booking', { doctor_profile_id: Number($('pa-pc-doc').value), date: $('pa-pc-date').value, start_time: time, mode: mode, reason: $('pa-pc-reason').value, notes: $('pa-pc-notes').value, outside_sessions: outside })
      .then(function (j) { busy = false; if (j.data) { toast(j.message || 'Booked.'); render(j.data); loadSlots(); } else toast(j.message || 'Could not book.', true); });
  }

  // ------------------------------------------------------------ shuttle
  function renderShuttle(d) {
    var box = $('pa-pc-shuttle');
    if (!box) return;
    var sh = d.shuttle, html = '';
    if (C.role === 'practice') {
      var allowed = d.booking_options && d.booking_options.shuttle;
      if (!allowed) { box.innerHTML = ''; return; }
      if (!sh || sh.status === 'offered') {
        html = '<h3>Shuttle</h3><p class="pa-pc-muted">Pickup: ' + esc(d.patient.location ? d.patient.location.label + ' (shared from the call)' : 'patient\'s saved address') + ' → your practice.</p>' +
          '<label class="pa-pc-chk"><input type="checkbox" id="pa-pc-return"' + (sh && sh.return_leg ? ' checked' : '') + '> Include the return trip</label>' +
          '<button type="button" class="pa-pc-btn ghost" data-a="quote">' + (sh ? 'Re-price & resend options' : 'Book a shuttle for the patient') + '</button>';
        if (sh) html += '<ul class="pa-pc-quotes">' + sh.quotes.map(function (q) { return '<li><b>' + esc(q.label) + '</b> ' + money(q.fare) + ' · ' + Number(q.distance_km).toFixed(1) + ' km · ~' + q.eta_minutes + ' min</li>'; }).join('') + '</ul><p class="pa-pc-muted">Sent to the patient — waiting for them to choose and pay.</p>';
      } else {
        html = '<h3>Shuttle</h3><div class="pa-pc-booked"><b>' + esc((sh.vehicle_type || '').replace(/_/g, ' ')) + ' · ' + money(sh.fare) + '</b><div class="sub">Pickup ' + (sh.pickup_at ? new Date(sh.pickup_at).toLocaleString() : 'as soon as possible') + (sh.return_leg ? ' · return trip booked' : '') + '</div><div class="sub">Payment: ' + esc(sh.payment ? sh.payment.state : '—') + '</div></div>';
      }
    } else {
      if (!sh) { box.innerHTML = ''; return; }
      if (sh.status === 'offered') {
        html = '<h3>Choose your shuttle & pay</h3><p class="pa-pc-muted">' + esc(sh.pickup ? sh.pickup.address : '') + ' → ' + esc(sh.dropoff ? sh.dropoff.address : '') + (sh.return_leg ? ' (and back)' : '') + '</p>' +
          sh.quotes.map(function (q) { return '<button type="button" class="pa-pc-quote" data-v="' + esc(q.vehicle_type) + '"><b>' + esc(q.label) + '</b><span>' + money(q.fare) + '</span><small>' + Number(q.distance_km).toFixed(1) + ' km · ~' + q.eta_minutes + ' min' + (q.surge_label ? ' · ' + esc(q.surge_label) : '') + '</small></button>'; }).join('');
      } else if (sh.ride) {
        html = '<h3>Your shuttle</h3><div class="pa-pc-booked"><b>' + esc((sh.vehicle_type || '').replace(/_/g, ' ')) + ' · ' + money(sh.fare) + '</b><div class="sub">Pickup ' + (sh.pickup_at ? new Date(sh.pickup_at).toLocaleString() : 'as soon as possible') + '</div>' +
          (sh.ride.pay_url ? '<a class="pa-pc-btn green" href="' + esc(sh.ride.pay_url) + '" target="_blank" rel="noopener">Pay now</a>' : '<div class="sub">Payment: ' + esc(sh.payment ? sh.payment.state : '—') + '</div>') +
          (sh.ride.track_url ? ' <a class="pa-pc-link" href="' + esc(sh.ride.track_url) + '" target="_blank" rel="noopener">Track trip</a>' : '') + '</div>';
      }
    }
    if (box.dataset.html !== html) { box.dataset.html = html; box.innerHTML = html; }
  }

  // ------------------------------------------------------------ recording (practice side)
  function maybeRecord(d) {
    var r = d.recording || {}, el = $('pa-pc-rec');
    if (!el) return;
    if (!d.recorded || r.method === 'off') { el.innerHTML = ''; return; }
    if (!d.connected) { el.innerHTML = r.status === 'stored' ? '<span class="pa-pc-muted">Recording saved.</span>' : ''; return; }
    if (r.method === 'jaas') {
      el.innerHTML = '<span class="pa-pc-dot"></span> Recording on the video server (JaaS)';
      if (!jaasRecStarted && window.paCall && window.paCall.api) {
        jaasRecStarted = true;
        try { window.paCall.api.executeCommand('startRecording', { mode: 'file' }); } catch (e) { el.innerHTML = 'Could not start server recording.'; }
      }
      return;
    }
    if (recStarted) { el.innerHTML = '<span class="pa-pc-dot"></span> Recording this call (' + (rec ? rec.seq : 0) + ' parts uploaded)'; return; }
    el.innerHTML = '<button type="button" class="pa-pc-btn ghost" data-a="rec">Start recording</button> <span class="pa-pc-muted">Your browser asks to share this tab — choose this tab and tick "Share tab audio".</span>';
  }

  function startRecorder() {
    if (recStarted || !navigator.mediaDevices || !window.MediaRecorder) { toast('This browser cannot record calls.', true); return; }
    var ac = new (window.AudioContext || window.webkitAudioContext)();
    var dest = ac.createMediaStreamDestination();
    var streams = [];
    navigator.mediaDevices.getDisplayMedia({ video: true, audio: true, preferCurrentTab: true, selfBrowserSurface: 'include' })
      .then(function (tab) {
        streams.push(tab);
        if (!tab.getAudioTracks().length) throw new Error('no-tab-audio');
        ac.createMediaStreamSource(new MediaStream(tab.getAudioTracks())).connect(dest);
        return navigator.mediaDevices.getUserMedia({ audio: true }).then(function (mic) { streams.push(mic); ac.createMediaStreamSource(mic).connect(dest); }).catch(function () {});
      })
      .then(function () {
        var mime = MediaRecorder.isTypeSupported('audio/webm;codecs=opus') ? 'audio/webm;codecs=opus' : 'audio/webm';
        var mr = new MediaRecorder(dest.stream, { mimeType: mime });
        rec = { mr: mr, seq: 0, mime: mime, streams: streams, ac: ac, finalSent: false };
        mr.ondataavailable = function (e) { if (e.data && e.data.size) upload(e.data, false); };
        mr.onstop = function () { upload(new Blob([], { type: 'audio/webm' }), true); };
        mr.start(15000);
        recStarted = true;
        render(S);
      })
      .catch(function (e) { streams.forEach(function (s) { s.getTracks().forEach(function (t) { t.stop(); }); }); toast(e && e.message === 'no-tab-audio' ? 'Tick "Share tab audio" so the patient\'s voice is recorded.' : 'Recording was not started.', true); });
  }
  function upload(blob, final) {
    if (!rec || rec.finalSent) return;
    if (final) rec.finalSent = true;
    var fd = new FormData();
    fd.append('chunk', blob.size ? blob : new Blob([new Uint8Array(1)], { type: 'audio/webm' }), 'part.webm');
    fd.append('seq', String(rec.seq++)); fd.append('final', final ? '1' : '0'); fd.append('mime', 'audio/webm');
    req('POST', '/recording', fd, true).catch(function () {});
  }
  function stopRecorder() {
    if (!rec || !rec.mr || rec.mr.state === 'inactive') return;
    try { rec.mr.stop(); } catch (e) {}
    rec.streams.forEach(function (s) { s.getTracks().forEach(function (t) { t.stop(); }); });
  }

  // ------------------------------------------------------------ actions
  root.addEventListener('click', function (e) {
    var b = e.target.closest('[data-a]');
    if (!b || busy) return;
    var a = b.getAttribute('data-a');
    if (a === 'accept' || a === 'decline') {
      stopSound(); busy = true;
      req('POST', '/' + a).then(function (j) { busy = false; if (j.data) render(j.data); else toast(j.message || 'Please try again.', true); });
    } else if (a === 'end') {
      var close = $('pa-pc-close') && $('pa-pc-close').checked;
      stopRecorder();
      try { if (window.paCall && window.paCall.hangup) window.paCall.hangup(); } catch (x) {}
      busy = true;
      req('POST', '/end', { close_request: !!close }).then(function (j) { busy = false; if (j.data) render(j.data); });
    } else if (a === 'again') {
      busy = true;
      req('POST', '/ring-again').then(function (j) { busy = false; if (j.data) { location.href = location.pathname.replace(/\/(\d+)(\/?)$/, '/' + j.data.id + '$2'); } else toast(j.message || 'Could not call.', true); });
    } else if (a === 'book') saveBooking();
    else if (a === 'quote') {
      busy = true;
      req('POST', '/shuttle/quote', { return_leg: $('pa-pc-return') && $('pa-pc-return').checked }).then(function (j) { busy = false; if (j.data) { toast(j.message); render(j.data); } else toast(j.message || 'Could not price a shuttle.', true); });
    } else if (a === 'loc') {
      if (!navigator.geolocation) { toast('Location is not available on this device.', true); return; }
      navigator.geolocation.getCurrentPosition(function (p) {
        req('POST', '/location', { lat: p.coords.latitude, lng: p.coords.longitude, label: 'My current location' }).then(function (j) { toast(j.ok ? 'Location shared with the practice.' : (j.message || 'Could not share.'), !j.ok); });
      }, function () { toast('Allow location access to share it.', true); }, { enableHighAccuracy: true, timeout: 15000 });
    } else if (a === 'rec') startRecorder();
  });
  root.addEventListener('click', function (e) {
    var q = e.target.closest('[data-v]');
    if (!q || busy) return;
    if (!confirm('Book this shuttle and pay now?')) return;
    busy = true;
    req('POST', '/shuttle/choose', { vehicle_type: q.getAttribute('data-v') }).then(function (j) {
      busy = false;
      if (j.data) { render(j.data); var sh = j.data.shuttle; if (sh && sh.ride && sh.ride.pay_url) window.open(sh.ride.pay_url, '_blank'); }
      else toast(j.message || 'Could not book the shuttle.', true);
    });
  });
  window.addEventListener('pagehide', function () {
    if (!inCall || !navigator.sendBeacon) return;
    try { var f = new FormData(); f.append('state', 'out'); f.append('_token', C.csrf || ''); navigator.sendBeacon(C.base + '/presence', f); } catch (e) {}
  });

  poll();
})();
/* ===================== END PRACTICE CALL ===================== */
