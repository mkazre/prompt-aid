/*
 * Web driver console. Polls /driver/state every ~4 s, streams browser GPS
 * (watchPosition, sent at most every 5 s) while online or on a trip, and
 * drives offers / trip actions. Config: window.PA_DRIVER.
 */
(function () {
  'use strict';
  var C = window.PA_DRIVER, S = window.PAShuttle;
  if (!C || !S || !window.L) return;

  function $(id) { return document.getElementById(id); }
  function u(tpl, id) { return tpl.replace('__ID__', encodeURIComponent(id)); }
  function show(el, on) { if (el) el.hidden = !on; }
  function text(el, v) { if (el) el.textContent = v == null ? '' : v; }
  var toastT = null;
  function toast(msg) { var t = $('sh-toast'); t.textContent = msg; t.hidden = false; clearTimeout(toastT); toastT = setTimeout(function () { t.hidden = true; }, 4500); }

  var m = S.map('drv-map', { center: C.center, zoom: 14 });
  var fit = S.fitter(m);
  var me = S.car(m);
  var activeLine = S.route(m, { color: '#D0211C', weight: 5 });
  var tripLine = S.route(m, { color: '#101012', weight: 4, opacity: 0.45 });
  var pickupPin = null, dropPin = null, rk = '', tk = '';

  var state = null, pollTimer = null, offerTimer = null, lastOfferId = null, currentRideId = null, lastRideStatus = null;

  // ---------------- GPS ----------------
  var watchId = null, lastSent = 0, lastFix = null, sending = false, wakeLock = null;
  function wantGps() { return state && state.driver && (state.driver.online || state.ride); }
  function startGps() {
    if (watchId !== null) return;
    if (!navigator.geolocation) { text($('drv-geo'), 'This browser cannot share location — use a phone browser with GPS.'); return; }
    watchId = navigator.geolocation.watchPosition(function (p) {
      lastFix = p;
      me.moveTo(p.coords.latitude, p.coords.longitude, p.coords.heading != null && !isNaN(p.coords.heading) ? p.coords.heading : null, 1000);
      maybeSend(false);
    }, function (err) {
      text($('drv-geo'), err.code === 1 ? 'Location permission denied — allow location for this site so patients can see you.' : 'Waiting for GPS…');
    }, { enableHighAccuracy: true, maximumAge: 2000, timeout: 20000 });
    if ('wakeLock' in navigator) navigator.wakeLock.request('screen').then(function (w) { wakeLock = w; }).catch(function () {});
  }
  function stopGps() {
    if (watchId !== null) navigator.geolocation.clearWatch(watchId);
    watchId = null;
    if (wakeLock) { wakeLock.release().catch(function () {}); wakeLock = null; }
    text($('drv-geo'), 'Location sharing is off.');
  }
  function maybeSend(force) {
    if (!lastFix || sending || !wantGps()) return;
    var now = Date.now();
    if (!force && now - lastSent < 5000) return;
    sending = true; lastSent = now;
    var c = lastFix.coords;
    S.api(C.locationUrl, { method: 'POST', body: {
      lat: c.latitude, lng: c.longitude,
      heading: c.heading != null && !isNaN(c.heading) ? c.heading : null,
      speed: c.speed != null && !isNaN(c.speed) ? c.speed : null,
      accuracy: c.accuracy,
    } }).then(function (r) {
      text($('drv-geo'), r && r.accepted ? 'Sharing live location · ±' + Math.round(c.accuracy) + ' m · ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })
        : 'Location update skipped (' + ((r && r.reason) || 'n/a').replace(/_/g, ' ') + ')');
    }).catch(function (e) { text($('drv-geo'), 'Location not sent: ' + e.message); })
      .then(function () { sending = false; });
  }
  setInterval(function () { maybeSend(false); }, 5000);

  // ---------------- Online toggle ----------------
  $('drv-online').addEventListener('click', function () {
    var want = !(state && state.driver && state.driver.online);
    var btn = this; btn.disabled = true;
    S.api(C.onlineUrl, { method: 'POST', body: { online: want } }).then(function () {
      if (want) startGps();
      return refresh();
    }).catch(function (e) { toast(e.message); }).then(function () { btn.disabled = false; });
  });

  // ---------------- Render ----------------
  function navLinks(lat, lng) {
    $('drv-nav-google').href = 'https://www.google.com/maps/dir/?api=1&destination=' + lat + ',' + lng + '&travelmode=driving';
    $('drv-nav-waze').href = 'https://waze.com/ul?ll=' + lat + ',' + lng + '&navigate=yes';
  }

  function setPins(pu, dr) {
    if (pickupPin) { m.removeLayer(pickupPin); pickupPin = null; }
    if (dropPin) { m.removeLayer(dropPin); dropPin = null; }
    if (pu) pickupPin = S.pin(m, 'pickup', [pu.lat, pu.lng], 'Pickup');
    if (dr) dropPin = S.pin(m, 'dropoff', [dr.lat, dr.lng], 'Drop-off');
  }

  function renderOffer(o) {
    var box = $('drv-offer');
    // The global alert modal (pa-dispatch.js: looping sound, swipe right to accept,
    // "taken by another driver") handles offers; the legacy card stays hidden.
    if (window.PA_DISPATCH) { box.hidden = true; return; }
    if (!o) { box.hidden = true; clearInterval(offerTimer); lastOfferId = null; return; }
    if (o.id !== lastOfferId) {
      lastOfferId = o.id;
      box.hidden = false;
      try { if (navigator.vibrate) navigator.vibrate([300, 150, 300]); } catch (e) {}
      beep();
      setPins(o.pickup, o.dropoff);
      fit.fit([me.latlng(), [o.pickup.lat, o.pickup.lng], [o.dropoff.lat, o.dropoff.lng]], true);
      var deadline = Date.now() + o.seconds_left * 1000;
      clearInterval(offerTimer);
      var tickOffer = function () {
        var left = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
        text($('drv-offer-secs'), left + 's');
        $('drv-offer-bar').style.width = Math.min(100, left / o.timeout_s * 100) + '%';
        if (left <= 0) { clearInterval(offerTimer); box.hidden = true; refresh(); }
      };
      tickOffer(); offerTimer = setInterval(tickOffer, 250);
    }
    text($('drv-offer-fare'), S.money(o.fare));
    text($('drv-offer-meta'), (o.pickup_eta_min != null ? o.pickup_eta_min + ' min (' + (o.pickup_km || 0).toFixed(1) + ' km) to pickup · ' : '') + 'trip ' + (o.trip_km || 0).toFixed(1) + ' km / ~' + (o.trip_min || '?') + ' min' + (o.surge ? ' · surge' : ''));
    text($('drv-offer-type'), (o.vehicle_type || '').replace(/_/g, ' '));
    text($('drv-offer-pickup'), o.pickup_address);
    text($('drv-offer-dropoff'), o.dropoff_address);
  }

  function renderRide(r) {
    var box = $('drv-ride');
    if (!r) { box.hidden = true; currentRideId = null; activeLine.clear(); tripLine.clear(); rk = ''; tk = ''; mountRecorder(null); return; }
    if (r.id !== currentRideId) { currentRideId = r.id; setPins(r.pickup, r.dropoff); rk = ''; tk = ''; }
    box.hidden = false;
    text($('drv-ride-status'), {
      accepted: 'Head to pickup', driver_enroute: 'Head to pickup', arrived: 'Waiting for patient', in_progress: 'Driving to drop-off',
    }[r.status] || r.status_label);
    text($('drv-ride-ref'), r.ride_ref);
    text($('drv-ride-patient'), r.patient_first_name + (r.vehicle_type && r.vehicle_type !== 'sedan' ? ' · ' + r.vehicle_type.replace(/_/g, ' ') : '') + (r.wait_and_return ? ' · wait & return' : ''));
    var call = $('drv-ride-call');
    if (r.patient_phone) { call.href = 'tel:' + r.patient_phone.replace(/[^0-9+]/g, ''); call.hidden = false; } else call.hidden = true;
    text($('drv-ride-pickup'), r.pickup.address);
    text($('drv-ride-dropoff'), r.dropoff.address);
    text($('drv-ride-eta'), r.eta ? r.eta.minutes + ' min · ' + r.eta.distance_km.toFixed(1) + ' km to ' + (r.eta.target === 'pickup' ? 'pickup' : 'drop-off') + ' · fare ' + S.money(r.fare) : 'Fare ' + S.money(r.fare));
    show($('drv-test'), !!r.simulated);
    renderRider(r.rider);
    mountRecorder(r.id);

    var target = r.status === 'in_progress' ? r.dropoff : r.pickup;
    navLinks(target.lat, target.lng);

    if (r.route) { if (!r.route.key) { activeLine.clear(); rk = ''; } else if (r.route.points) { activeLine.set(r.route.points); rk = r.route.key; } }
    if (r.trip_route && r.trip_route.points) { tripLine.set(r.trip_route.points); tk = r.trip_route.key; }

    if (lastRideStatus !== r.status) {
      fit.fit([me.latlng(), [target.lat, target.lng]], false);
      lastRideStatus = r.status;
    }

    // Waiting / PIN / buttons
    var act = $('drv-ride-action'), wait = $('drv-wait');
    show($('drv-pin-wrap'), r.status === 'arrived' && r.pin_required);
    show($('drv-noshow'), r.status === 'arrived');
    show($('drv-cancel'), ['accepted', 'driver_enroute', 'arrived'].indexOf(r.status) !== -1);
    if (r.status === 'arrived' && r.waiting) {
      wait.hidden = false;
      var freeLeft = r.waiting.free_wait_s - r.waiting.waited_s, nsLeft = r.waiting.no_show_after_s - r.waiting.waited_s;
      wait.textContent = 'Waiting ' + S.mmss(r.waiting.waited_s) + (freeLeft > 0 ? ' · free wait ends in ' + S.mmss(freeLeft) : ' · paid waiting time') + (nsLeft > 0 ? ' · no-show available in ' + S.mmss(nsLeft) : '');
      $('drv-noshow').disabled = !r.waiting.no_show_allowed;
    } else wait.hidden = true;

    act.dataset.next = r.status === 'in_progress' ? 'completed' : r.status === 'arrived' ? 'in_progress' : 'arrived';
    act.textContent = r.status === 'in_progress' ? 'Complete trip' : r.status === 'arrived' ? 'Start trip' : 'Arrived at pickup';
  }

  function renderRider(rd) {
    var el = $('drv-rider');
    if (!el) return;
    if (!rd) { el.innerHTML = ''; return; }
    var v = rd.verified || {};
    var chip = function (ok, label) { return '<span class="pa-badge' + (ok ? ' is-go' : '') + '" style="margin:4px 4px 0 0">' + (ok ? '✓ ' + label + ' verified' : label + ' not verified') + '</span>'; };
    var avatar = rd.photo_url ? '<img src="' + S.esc(rd.photo_url) + '" alt="" style="width:100%;height:100%;object-fit:cover">' : S.esc(rd.initials || '?');
    var html = '<div style="display:flex;gap:12px;align-items:center;background:#F7F5F0;border:1px solid #ECE7DC;border-radius:12px;padding:10px 12px">' +
      '<div style="width:46px;height:46px;border-radius:50%;background:#101012;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:900;overflow:hidden;flex:0 0 46px">' + avatar + '</div>' +
      '<div style="min-width:0"><div style="font-weight:900;font-size:16px">' + S.esc(rd.full_name || rd.first_name) + ' · ' + S.esc(rd.rating_label) + '</div>' +
      '<div class="pa-sh-sub" style="margin:2px 0 0">' + (rd.trips_completed || 0) + ' trips' + (rd.member_since ? ' · member since ' + S.esc(rd.member_since) : '') + '</div>' +
      '<div>' + chip(v.phone, 'Phone') + chip(v.id, 'ID') + chip(v.medical_aid, 'Medical aid') + '</div></div></div>';
    if (rd.needs && rd.needs.length) html += '<div class="pa-note" style="margin-top:8px">♿ ' + rd.needs.map(S.esc).join(' · ') + '</div>';
    if (rd.pickup_notes) html += '<div class="pa-note" style="margin-top:8px">📝 ' + S.esc(rd.pickup_notes) + '</div>';
    if (rd.booked_by) html += '<div class="pa-sh-sub" style="margin-top:8px">Booked by ' + S.esc(rd.booked_by.name) + (rd.booked_by.phone ? ' · <a href="tel:' + S.esc(rd.booked_by.phone.replace(/[^0-9+]/g, '')) + '">' + S.esc(rd.booked_by.phone) + '</a>' : '') + '</div>';
    if (rd.emergency_contact) html += '<div class="pa-sh-sub" style="margin-top:4px">Emergency contact: ' + S.esc(rd.emergency_contact.name || '') + ' · <a href="tel:' + S.esc(String(rd.emergency_contact.phone).replace(/[^0-9+]/g, '')) + '">' + S.esc(rd.emergency_contact.phone) + '</a></div>';
    el.innerHTML = html;
  }

  var recorderRide = null;
  function mountRecorder(id) {
    var slot = $('drv-recorder-slot');
    if (!slot || recorderRide === id) return;
    recorderRide = id;
    slot.innerHTML = id ? '<div data-pa-trip-recorder data-ride-id="' + Number(id) + '" data-role="driver"></div>' : '';
    try { if (id && window.PaTripRecorder && window.PaTripRecorder.mount) window.PaTripRecorder.mount(slot.firstChild); } catch (e) {}
  }

  function renderQueue(q) {
    var wrap = $('drv-queue-wrap'), list = $('drv-queue');
    if (!q || !q.length) { wrap.hidden = true; list.innerHTML = ''; return; }
    wrap.hidden = false;
    list.innerHTML = q.map(function (r) {
      return '<div class="pa-drv-queue-item"><div class="pa-spread"><strong>' + S.money(r.fare) + '</strong><span class="pa-badge">' + S.esc((r.vehicle_type || '').replace(/_/g, ' ')) + '</span></div>' +
        '<div class="pa-sh-sub">' + (r.away_km != null ? r.away_km + ' km away · ' : '') + 'trip ' + (r.trip_km || 0).toFixed(1) + ' km' + (r.scheduled_for ? ' · scheduled ' + new Date(r.scheduled_for).toLocaleString([], { weekday: 'short', hour: '2-digit', minute: '2-digit' }) : '') + '</div>' +
        '<div style="font-size:13px;margin:6px 0">' + S.esc(r.pickup_address) + ' → ' + S.esc(r.dropoff_address) + '</div>' +
        '<button type="button" class="pa-btn pa-btn-sm" data-claim="' + r.id + '">Accept</button></div>';
    }).join('');
    list.querySelectorAll('[data-claim]').forEach(function (b) {
      b.addEventListener('click', function () {
        b.disabled = true;
        S.api(u(C.claimUrl, b.dataset.claim), { method: 'POST', body: {} }).then(function (r) { toast(r.message); refresh(); })
          .catch(function (e) { toast(e.message); b.disabled = false; refresh(); });
      });
    });
  }

  var ratedRide = null, lastLastId = null;
  function renderLast(l) {
    var box = $('drv-last');
    if (!l) { box.hidden = true; return; }
    box.hidden = false;
    text($('drv-last-summary'), l.ride_ref + ' · ' + (l.status === 'completed' ? 'Completed · fare ' + S.money(l.fare) : (l.cancelled_by === 'no_show' ? 'No-show' : 'Cancelled by ' + (l.cancelled_by || 'system'))));
    show($('drv-last-rate'), l.status === 'completed' && !l.rated && ratedRide !== l.id);
    if (lastLastId !== l.id) { lastLastId = l.id; $('drv-stars').dataset.ride = l.id; }
  }

  function render(st) {
    state = st;
    var d = st.driver;
    var sw = $('drv-online');
    sw.classList.toggle('on', d.online);
    sw.setAttribute('aria-pressed', d.online ? 'true' : 'false');
    text($('drv-online-label'), d.availability === 'busy' ? 'On trip' : d.online ? 'Online' : 'Offline');
    var alertMsg = d.account_status !== 'active' ? 'Your driver account is awaiting approval by Prompt Aid.' : (d.compliance_blocker ? 'Dispatch paused: ' + d.compliance_blocker + '. Upload a current document in the app.' : '');
    text($('drv-alert'), alertMsg); show($('drv-alert'), !!alertMsg);
    text($('drv-earn'), S.money(st.earnings.today_total).replace('.00', ''));
    text($('drv-trips'), st.earnings.today_trips);
    text($('drv-rating'), d.rating ? '★ ' + d.rating.toFixed(1) : 'New');

    if (wantGps()) startGps(); else stopGps();
    if (!me.exists() && d.lat != null) me.moveTo(d.lat, d.lng, d.heading, 1);
    // During a TEST simulation the server position is the truth.
    if (st.ride && st.ride.simulated && d.lat != null) me.moveTo(d.lat, d.lng, d.heading, 4000);

    renderOffer(st.offer);
    renderRide(st.ride);
    renderQueue(st.queue);
    renderLast(st.last_completed);
    if (!st.ride && !st.offer && pickupPin) setPins(null, null);
    var idle = !st.ride && !st.offer;
    show($('drv-idle'), idle);
    text($('drv-idle'), d.online ? 'You are online. New trip requests will appear here — keep this page open.' : 'Go online to start receiving trip requests.');
  }

  function refresh() {
    clearTimeout(pollTimer);
    var url = C.stateUrl + '?rk=' + encodeURIComponent(rk) + '&tk=' + encodeURIComponent(tk);
    return S.api(url).then(function (st) { render(st); })
      .catch(function (e) { if (e.status === 401 || e.status === 419) toast('Your session expired — please log in again.'); })
      .then(function () { pollTimer = setTimeout(refresh, document.hidden ? 8000 : 4000); });
  }

  // ---------------- Actions ----------------
  $('drv-offer-accept').addEventListener('click', function () {
    if (!state || !state.offer) return;
    var b = this; b.disabled = true;
    S.api(u(C.offerAcceptUrl, state.offer.id), { method: 'POST', body: {} }).then(function (r) { toast(r.message); maybeSend(true); })
      .catch(function (e) { toast(e.message); }).then(function () { b.disabled = false; refresh(); });
  });
  $('drv-offer-decline').addEventListener('click', function () {
    if (!state || !state.offer) return;
    var b = this; b.disabled = true;
    S.api(u(C.offerDeclineUrl, state.offer.id), { method: 'POST', body: {} }).then(function (r) { toast(r.message); })
      .catch(function (e) { toast(e.message); }).then(function () { b.disabled = false; refresh(); });
  });
  $('drv-ride-action').addEventListener('click', function () {
    if (!state || !state.ride) return;
    var next = this.dataset.next, body = { status: next };
    if (lastFix) { body.lat = lastFix.coords.latitude; body.lng = lastFix.coords.longitude; }
    if (next === 'in_progress' && state.ride.pin_required) {
      body.pin = ($('drv-pin').value || '').trim();
      if (!/^\d{4}$/.test(body.pin)) { toast('Enter the 4-digit PIN from the patient.'); $('drv-pin').focus(); return; }
      body.pin = body.pin.replace(/\D/g, '');
    }
    if (next === 'completed' && !window.confirm('Complete this trip at the drop-off?')) return;
    var b = this; b.disabled = true;
    S.api(u(C.statusUrl, state.ride.id), { method: 'POST', body: body }).then(function (r) { toast(r.message); $('drv-pin').value = ''; })
      .catch(function (e) { toast(e.message); }).then(function () { b.disabled = false; refresh(); });
  });
  $('drv-noshow').addEventListener('click', function () {
    if (!state || !state.ride || !window.confirm('Mark the patient as a no-show? A no-show fee may be charged to them.')) return;
    S.api(u(C.noShowUrl, state.ride.id), { method: 'POST', body: {} }).then(function (r) { toast(r.message); })
      .catch(function (e) { toast(e.message); }).then(refresh);
  });

  var cReason = null;
  $('drv-cancel').addEventListener('click', function () {
    cReason = null;
    var rs = $('drv-cancel-reasons');
    rs.innerHTML = (C.reasons || []).map(function (r) { return '<button type="button" class="pa-sh-reason">' + S.esc(r) + '</button>'; }).join('');
    rs.querySelectorAll('.pa-sh-reason').forEach(function (b) {
      b.addEventListener('click', function () {
        rs.querySelectorAll('.pa-sh-reason').forEach(function (o) { o.classList.toggle('on', o === b); });
        cReason = b.textContent; $('drv-cancel-confirm').disabled = false;
      });
    });
    $('drv-cancel-confirm').disabled = true;
    $('drv-cancel-modal').hidden = false;
  });
  $('drv-cancel-confirm').addEventListener('click', function () {
    if (!state || !state.ride || !cReason) return;
    var b = this; b.disabled = true;
    S.api(u(C.cancelUrl, state.ride.id), { method: 'POST', body: { reason: cReason } }).then(function (r) { toast(r.message); $('drv-cancel-modal').hidden = true; })
      .catch(function (e) { toast(e.message); b.disabled = false; }).then(refresh);
  });
  document.querySelectorAll('[data-sh-close]').forEach(function (b) { b.addEventListener('click', function () { b.closest('.pa-sh-modal').hidden = true; }); });

  $('drv-stars').querySelectorAll('button').forEach(function (b) {
    b.addEventListener('click', function () {
      var id = $('drv-stars').dataset.ride, v = Number(b.dataset.v);
      if (!id) return;
      $('drv-stars').querySelectorAll('button').forEach(function (o) { o.classList.toggle('on', Number(o.dataset.v) <= v); });
      S.api(u(C.rateUrl, id), { method: 'POST', body: { rating: v } }).then(function (r) { toast(r.message); ratedRide = Number(id); show($('drv-last-rate'), false); })
        .catch(function (e) { toast(e.message); });
    });
  });

  var audioCtx = null;
  function beep() {
    try {
      audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
      [0, 0.35].forEach(function (t) {
        var o = audioCtx.createOscillator(), g = audioCtx.createGain();
        o.frequency.value = 880; o.connect(g); g.connect(audioCtx.destination);
        g.gain.setValueAtTime(0.2, audioCtx.currentTime + t);
        o.start(audioCtx.currentTime + t); o.stop(audioCtx.currentTime + t + 0.2);
      });
    } catch (e) {}
  }
  document.addEventListener('visibilitychange', function () { if (!document.hidden) refresh(); });

  // Accepted from the alert modal: show the trip straight away.
  window.PA_DISPATCH_HOOKS = { onAccepted: function () { maybeSend(true); refresh(); } };

  refresh();
})();
