/*
 * Live trip tracking (patient page + public share page).
 * Config: window.PA_TRIP = { statusUrl, initial, readOnly?, cancelPreviewUrl?, cancelUrl?, retryUrl?, sosUrl?, rateUrl?, reasons? }
 * Polls statusUrl every poll_ms (server-driven, ~3.5 s); the car glides
 * between fixes; route polylines are only re-sent when they change.
 */
(function () {
  'use strict';
  var C = window.PA_TRIP;
  var S = window.PAShuttle;
  if (!C || !S || !window.L) return;

  function $(id) { return document.getElementById(id); }
  function show(el, on) { if (el) el.hidden = !on; }
  function text(el, v) { if (el) el.textContent = v == null ? '' : v; }

  var d0 = C.initial;
  var m = S.map('pa-sh-map', { center: [d0.pickup.lat, d0.pickup.lng], zoom: 14 });
  var fit = S.fitter(m);
  S.pin(m, 'pickup', [d0.pickup.lat, d0.pickup.lng], 'Pickup');
  S.pin(m, 'dropoff', [d0.dropoff.lat, d0.dropoff.lng], 'Drop-off');
  var tripLine = S.route(m, { color: '#101012', weight: 4, opacity: 0.45 });
  var activeLine = S.route(m, { color: '#D0211C', weight: 5 });
  var car = S.car(m);

  var rk = '', tk = '', lastStatus = d0.status, lastPhase = null, timer = null, toastTimer = null;

  function toast(msg) {
    var t = $('sh-toast'); if (!t) return;
    t.textContent = msg; t.hidden = false;
    clearTimeout(toastTimer); toastTimer = setTimeout(function () { t.hidden = true; }, 4500);
  }

  function phaseOf(d) {
    if (!d.is_active) return 'ended';
    if (d.status === 'requested') return 'matching';
    if (d.status === 'arrived') return 'arrived';
    if (d.status === 'in_progress') return 'to_dropoff';
    return 'to_pickup';
  }

  function fitFor(d, force) {
    var pu = [d.pickup.lat, d.pickup.lng], dr = [d.dropoff.lat, d.dropoff.lng];
    var c = d.driver && d.driver.lat != null ? [d.driver.lat, d.driver.lng] : null;
    var ph = phaseOf(d);
    var pts = ph === 'to_pickup' ? [c, pu] : ph === 'arrived' ? [c || pu, pu] : ph === 'to_dropoff' ? [c, dr] : [pu, dr];
    var inView = c ? m.getBounds().pad(-0.1).contains(c) : true;
    if (force || !inView) fit.fit(pts, false);
  }

  function renderTimeline(items) {
    var el = $('sh-timeline'); if (!el || !items) return;
    el.innerHTML = items.map(function (s) {
      var t = s.at ? new Date(s.at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';
      var cls = s.state === 'done' ? 'done' : s.state === 'now' ? 'now' : s.state === 'stop' ? 'done' : 'todo';
      var dot = s.state === 'stop' ? ' style="background:var(--pa-signal)"' : '';
      return '<div class="pa-step ' + cls + '"><span class="dot"' + dot + '></span>' + S.esc(s.label) + '<span class="t">' + S.esc(t) + '</span></div>';
    }).join('');
  }

  function render(d) {
    if (d.ended) { stop(); text($('sh-status'), 'This trip has ended'); show($('sh-eta-wrap'), false); car.remove(); activeLine.clear(); return; }

    // Routes
    if (d.trip_route && d.trip_route.points) { tripLine.set(d.trip_route.points); tk = d.trip_route.key; }
    if (d.route) {
      if (!d.route.key) { activeLine.clear(); rk = ''; }
      else if (d.route.points) { activeLine.set(d.route.points); rk = d.route.key; }
    }

    // Car
    if (d.driver && d.driver.lat != null && d.is_active && d.status !== 'requested') {
      car.moveTo(d.driver.lat, d.driver.lng, d.driver.heading, d.poll_ms || 3500);
    } else if (car.exists()) {
      car.remove();
    }

    var ph = phaseOf(d);
    fitFor(d, ph !== lastPhase);
    lastPhase = ph;

    // Status + ETA
    text($('sh-status'), d.status_label);
    var sub = '';
    if (ph === 'to_pickup') sub = d.driver ? d.driver.first_name + ' is heading to your pickup' : '';
    if (ph === 'to_dropoff') sub = 'On the way to your drop-off';
    if (ph === 'matching' && d.dispatch && d.dispatch.state === 'queue') sub = d.dispatch.scheduled_for ? 'Scheduled for ' + new Date(d.dispatch.scheduled_for).toLocaleString([], { weekday: 'short', hour: '2-digit', minute: '2-digit' }) : 'Your request is visible to available drivers';
    if (d.driver && d.driver.location_age_s != null && d.driver.location_age_s > 90 && d.is_active && d.status !== 'requested') sub += (sub ? ' · ' : '') + 'driver location last updated ' + Math.round(d.driver.location_age_s / 60) + ' min ago';
    text($('sh-sub'), sub);

    if (d.eta && (ph === 'to_pickup' || ph === 'to_dropoff')) {
      show($('sh-eta-wrap'), true);
      text($('sh-eta'), d.eta.minutes);
      var at = new Date(Date.now() + d.eta.minutes * 60000);
      text($('sh-eta-clock'), (ph === 'to_pickup' ? 'Pickup ~' : 'Arrive ~') + at.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) + ' · ' + d.eta.distance_km.toFixed(1) + ' km');
    } else {
      show($('sh-eta-wrap'), false);
    }

    // Matching
    var searching = d.status === 'requested' && d.dispatch && d.dispatch.state === 'searching';
    show($('sh-searching'), searching);
    if (searching) {
      var n = d.dispatch.drivers_notified || 0;
      text($('sh-search-text'), (n > 0 ? 'Request sent to ' + n + ' driver' + (n === 1 ? '' : 's') : 'Searching for drivers') + (d.dispatch.radius_km ? ' within ' + d.dispatch.radius_km + ' km' : '') + '…');
      var since = Date.parse(d.dispatch.searching_since || d.server_time), until = Date.parse(d.dispatch.timeout_at || d.server_time);
      var pct = until > since ? Math.min(100, Math.max(3, (Date.parse(d.server_time) - since) / (until - since) * 100)) : 50;
      var bar = $('sh-search-bar'); if (bar) bar.style.width = pct + '%';
    }
    show($('sh-nodrivers'), d.status === 'requested' && d.dispatch && d.dispatch.state === 'no_drivers' && !C.readOnly);
    var rsel = $('sh-retry-radius');
    if (rsel && !rsel.options.length && d.dispatch && d.dispatch.radius_options) {
      d.dispatch.radius_options.forEach(function (km) { var o = document.createElement('option'); o.value = km; o.textContent = km + ' km'; rsel.appendChild(o); });
      var cur = d.dispatch.patient_radius_km || d.dispatch.radius_km || 10;
      for (var i = 0; i < rsel.options.length; i++) { if (Number(rsel.options[i].value) > cur) { rsel.selectedIndex = i; break; } rsel.selectedIndex = i; }
    }
    var exp = $('sh-expired');
    if (exp) { exp.hidden = !d.is_expired; if (d.is_expired) exp.textContent = (d.cancellation && d.cancellation.reason) || 'This request has closed. You were not charged.'; }

    // Waiting at pickup
    var w = $('sh-waiting');
    if (d.waiting && w) {
      var waited = (Date.parse(d.server_time) - Date.parse(d.waiting.arrived_at)) / 1000;
      var free = d.waiting.free_wait_min * 60 - waited;
      w.hidden = false;
      w.textContent = C.readOnly ? 'The driver is waiting at the pickup point.' :
        (free > 0 ? 'Your driver is waiting. Free waiting time left: ' + S.mmss(free) + ', then ' + S.money(d.waiting.rate_per_min) + '/min.'
                  : 'Your driver is waiting. Waiting time is now charged at ' + S.money(d.waiting.rate_per_min) + '/min.');
    } else if (w) { w.hidden = true; }

    // Driver card
    var dc = $('sh-driver-card');
    if (d.driver && dc) {
      dc.hidden = false;
      var ph2 = $('sh-d-photo');
      if (ph2) ph2.innerHTML = d.driver.photo_url ? '<img src="' + S.esc(d.driver.photo_url) + '" alt="">' : S.esc(d.driver.initials);
      text($('sh-d-name'), d.driver.name);
      text($('sh-d-vehicle'), d.driver.vehicle + (d.vehicle_type ? ' · ' + d.vehicle_type.replace(/_/g, ' ') : ''));
      text($('sh-d-plate'), d.driver.plate || '');
      text($('sh-d-rating'), d.driver.rating ? '★ ' + d.driver.rating.toFixed(1) + ' (' + d.driver.rating_count + ' trips rated)' : 'New driver');
      var call = $('sh-d-call');
      if (call) { if (d.driver.phone) { call.href = 'tel:' + d.driver.phone.replace(/[^0-9+]/g, ''); call.hidden = false; } else call.hidden = true; }
    } else if (dc) { dc.hidden = true; }

    // PIN
    var pinCard = $('sh-pin-card');
    if (pinCard) {
      var showPin = !!d.trip_pin && ['accepted', 'driver_enroute', 'arrived'].indexOf(d.status) !== -1;
      pinCard.hidden = !showPin;
      if (showPin) $('sh-pin').innerHTML = String(d.trip_pin).split('').map(function (c) { return '<span>' + S.esc(c) + '</span>'; }).join('');
    }

    // Actions
    if (!C.readOnly) {
      show($('sh-actions'), d.is_active);
      show($('sh-cancel-btn'), !!d.can_cancel);
      show($('sh-share'), !!d.share_url);
    }
    show($('sh-test'), !!d.simulated);

    renderTimeline(d.timeline);

    // Trip ended while watching: reload for the server-rendered receipt / cancellation summary.
    if (!C.readOnly && lastStatus !== d.status && (d.status === 'completed' || d.status === 'cancelled')) {
      setTimeout(function () { window.location.reload(); }, 1200);
      stop();
    }
    if (C.readOnly && !d.is_active) stop();
    lastStatus = d.status;
  }

  function poll() {
    var url = C.statusUrl + (C.statusUrl.indexOf('?') === -1 ? '?' : '&') + 'rk=' + encodeURIComponent(rk) + '&tk=' + encodeURIComponent(tk);
    S.api(url).then(function (d) {
      render(d);
      if (d.poll_ms) timer = setTimeout(poll, document.hidden ? Math.max(d.poll_ms, 10000) : d.poll_ms);
    }).catch(function () { timer = setTimeout(poll, 7000); });
  }
  function stop() { clearTimeout(timer); timer = null; }

  render(d0);
  if (d0.is_active) timer = setTimeout(poll, 1200);

  if (C.readOnly) return;

  // --- Share trip ---
  var shareBtn = $('sh-share');
  if (shareBtn) shareBtn.addEventListener('click', function () {
    S.api(C.statusUrl).then(function (d) {
      if (!d.share_url) return;
      if (navigator.share) {
        navigator.share({ title: 'Follow my Prompt Aid shuttle', text: 'Track my shuttle trip live', url: d.share_url }).catch(function () {});
      } else if (navigator.clipboard) {
        navigator.clipboard.writeText(d.share_url).then(function () { toast('Trip link copied. It stops working when your trip ends.'); });
      } else {
        window.prompt('Copy this link', d.share_url);
      }
    });
  });

  // --- Modals ---
  function openModal(id) { var el = $(id); if (el) el.hidden = false; }
  document.querySelectorAll('[data-sh-close]').forEach(function (b) {
    b.addEventListener('click', function () { var mo = b.closest('.pa-sh-modal'); if (mo) mo.hidden = true; });
  });

  // --- Cancel (exact fee shown before confirming) ---
  var preview = null, reason = null;
  function loadPreview() {
    var fee = $('sh-cancel-fee'), btn = $('sh-cancel-confirm');
    fee.className = 'pa-note'; fee.textContent = 'Checking the cancellation fee…'; btn.disabled = true;
    return S.api(C.cancelPreviewUrl).then(function (p) {
      preview = p;
      if (!p.allowed) { fee.className = 'pa-note-stop'; fee.textContent = p.reason; return; }
      fee.className = p.fee > 0 ? 'pa-note-stop' : 'pa-note';
      fee.innerHTML = p.fee > 0
        ? '<strong>A cancellation fee of ' + S.money(p.fee) + ' applies.</strong><br>' + S.esc(p.reason)
        : '<strong>Cancelling now is free.</strong><br>' + S.esc(p.reason);
      var rs = $('sh-cancel-reasons');
      rs.innerHTML = (p.reasons || C.reasons || []).map(function (r) { return '<button type="button" class="pa-sh-reason">' + S.esc(r) + '</button>'; }).join('');
      rs.querySelectorAll('.pa-sh-reason').forEach(function (b) {
        b.addEventListener('click', function () {
          rs.querySelectorAll('.pa-sh-reason').forEach(function (o) { o.classList.toggle('on', o === b); });
          reason = b.textContent; btn.disabled = false;
          btn.textContent = p.fee > 0 ? 'Cancel and pay ' + S.money(p.fee) : 'Cancel trip';
        });
      });
    }).catch(function (e) { fee.className = 'pa-note-stop'; fee.textContent = e.message; });
  }
  document.querySelectorAll('[data-sh-cancel]').forEach(function (b) {
    b.addEventListener('click', function () { reason = null; openModal('sh-cancel-modal'); loadPreview(); });
  });
  var confirmBtn = $('sh-cancel-confirm');
  if (confirmBtn) confirmBtn.addEventListener('click', function () {
    if (!preview || !preview.allowed) return;
    confirmBtn.disabled = true;
    S.api(C.cancelUrl, { method: 'POST', body: { reason: reason, expected_fee: preview.fee } }).then(function (r) {
      toast(r.message); setTimeout(function () { window.location.reload(); }, 900);
    }).catch(function (e) {
      toast(e.message);
      if (e.status === 409) loadPreview(); else confirmBtn.disabled = false;
    });
  });

  // --- Retry dispatch ---
  var retry = $('sh-retry');
  if (retry) retry.addEventListener('click', function () {
    retry.disabled = true;
    var rr = $('sh-retry-radius');
    S.api(C.retryUrl, { method: 'POST', body: rr && rr.value ? { radius_km: Number(rr.value) } : {} }).then(function (r) { toast(r.message); stop(); poll(); })
      .catch(function (e) { toast(e.message); }).then(function () { retry.disabled = false; });
  });

  // --- SOS ---
  var sos = $('sh-sos');
  if (sos) sos.addEventListener('click', function () { openModal('sh-sos-modal'); });
  var sosAlert = $('sh-sos-alert');
  if (sosAlert) sosAlert.addEventListener('click', function () {
    sosAlert.disabled = true;
    var send = function (lat, lng) {
      S.api(C.sosUrl, { method: 'POST', body: { lat: lat, lng: lng } }).then(function (r) {
        text($('sh-sos-result'), r.message); sosAlert.textContent = 'Support team alerted';
      }).catch(function (e) { text($('sh-sos-result'), e.message); sosAlert.disabled = false; });
    };
    if (navigator.geolocation) {
      navigator.geolocation.getCurrentPosition(function (p) { send(p.coords.latitude, p.coords.longitude); }, function () { send(null, null); }, { timeout: 4000, maximumAge: 30000 });
    } else send(null, null);
  });

  // --- Rating ---
  var stars = $('sh-stars'), rating = 0;
  if (stars) {
    stars.querySelectorAll('button').forEach(function (b) {
      b.addEventListener('click', function () {
        rating = Number(b.dataset.v);
        stars.querySelectorAll('button').forEach(function (o) { o.classList.toggle('on', Number(o.dataset.v) <= rating); });
        $('sh-rate-send').disabled = false;
      });
    });
    $('sh-rate-send').addEventListener('click', function () {
      var btn = this; btn.disabled = true;
      S.api(C.rateUrl, { method: 'POST', body: { rating: rating, comment: $('sh-rate-comment').value || null } })
        .then(function (r) { toast(r.message); setTimeout(function () { window.location.reload(); }, 900); })
        .catch(function (e) { toast(e.message); btn.disabled = false; });
    });
  }
})();
