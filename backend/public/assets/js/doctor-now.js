/* ============================ DOCTOR NOW ============================
 * Patient "See a doctor now" page (/account/see-a-doctor).
 * Quote → request → poll status every 3s while searching → matched card /
 * fallback options. Config comes from window.PromptAidDoctorNow.
 * ==================================================================== */
(function () {
  'use strict';

  var cfg = window.PromptAidDoctorNow;
  if (!cfg || !document.getElementById('dn-form')) return;

  var $ = function (id) { return document.getElementById(id); };
  var csrfMeta = document.querySelector('meta[name="csrf-token"]');
  var csrf = csrfMeta ? csrfMeta.getAttribute('content') : '';
  var state = { current: cfg.current, loc: null, pollTimer: null, quoteSeq: 0, busy: false };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function money(n) { return 'R' + Number(n || 0).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function show(id, on) { var el = $(id); if (el) el.classList.toggle('pa-hide', !on); }
  function mode() { var r = document.querySelector('input[name="dn-mode"]:checked'); return r ? r.value : 'video'; }
  function specialty() { var s = $('dn-specialty'); return s ? s.value : ''; }
  function fmtSecs(s) {
    s = Math.max(0, Math.round(s || 0));
    var m = Math.floor(s / 60), r = s % 60;
    return m > 0 ? m + ' min ' + (r < 10 ? '0' : '') + r + 's' : r + 's';
  }

  function error(msg) {
    var el = $('dn-error');
    if (!el) return;
    el.textContent = msg || '';
    el.classList.toggle('pa-hide', !msg);
  }

  function api(method, url, body) {
    var opts = { method: method, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' };
    if (body) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    return fetch(url, opts).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (json) {
        if (!res.ok) {
          var msg = json.message || 'Something went wrong. Please try again.';
          if (json.errors) { var k = Object.keys(json.errors)[0]; if (k) msg = json.errors[k][0]; }
          var err = new Error(msg); err.status = res.status; err.payload = json; throw err;
        }
        return json;
      });
    });
  }

  // ---------------------------------------------------------------- quote
  function loadQuote() {
    var box = $('dn-quote');
    if (!box || !specialty()) return;
    var seq = ++state.quoteSeq;
    box.innerHTML = '<div class="pa-muted" style="font-size:13px">Loading price…</div>';
    api('GET', cfg.quoteUrl + '?specialty=' + encodeURIComponent(specialty()) + '&mode=' + encodeURIComponent(mode()))
      .then(function (json) {
        if (seq !== state.quoteSeq) return;
        var q = json.data;
        var notes = (q.notes || []).map(function (n) { return '<span class="pa-chip">' + esc(n) + '</span>'; }).join(' ');
        var online = q.online_doctors > 0
          ? '<span class="pa-badge is-go">' + q.online_doctors + ' online now</span>'
          : '<span class="pa-badge is-wait">None online right now</span>';
        box.innerHTML =
          '<div class="pa-dn-price"><div><div class="pa-label" style="margin:0">Consultation price</div>' +
          '<div class="v">' + money(q.price) + '</div>' +
          '<div class="pa-muted" style="font-size:12px">Standard ' + esc(q.specialty) + ' fee ' + money(q.base_fee) +
          (q.multiplier > 1 ? ' × ' + q.multiplier : '') + '. Price is locked when you request.</div></div>' +
          '<div>' + online + '</div></div>' +
          (notes ? '<div style="margin-top:10px;display:flex;gap:6px;flex-wrap:wrap">' + notes + '</div>' : '') +
          (q.is_after_hours ? '<p class="pa-muted" style="font-size:12px;margin:8px 0 0">After-hours, weekend and public-holiday consults cost more so doctors are available around the clock.</p>' : '') +
          (q.online_doctors === 0 ? '<p class="pa-muted" style="font-size:12px;margin:8px 0 0">You can still request — we\'ll keep looking for a few minutes, or you can ask us to notify you when a doctor comes online.</p>' : '');
      })
      .catch(function (e) { if (seq === state.quoteSeq) box.innerHTML = '<div class="pa-muted" style="font-size:13px">' + esc(e.message) + '</div>'; });
  }

  // ---------------------------------------------------------------- location
  function setLoc(lat, lng, label) {
    state.loc = { lat: lat, lng: lng };
    var s = $('dn-locstatus');
    if (s) s.textContent = '✓ ' + label;
  }
  function syncMode() {
    show('dn-inperson', mode() === 'in_person');
    loadQuote();
  }

  // ---------------------------------------------------------------- render
  function render(p) {
    state.current = p;
    stopPoll();
    show('dn-form', !p);
    show('dn-searching', !!p && p.status === 'searching');
    show('dn-matched', !!p && p.status === 'matched');
    show('dn-closed', !!p && (p.status === 'expired' || p.status === 'cancelled'));
    if (!p) { loadQuote(); return; }

    if (p.status === 'searching') { renderSearching(p); schedule(3000); }
    else if (p.status === 'matched') { renderMatched(p); schedule(15000); }
    else { renderClosed(p); }
  }

  function renderSearching(p) {
    var line = p.offer_pending
      ? 'Asking a doctor now (' + p.attempts + ' of up to ' + p.max_attempts + ')…'
      : (p.online_doctors > 0 ? 'Contacting available doctors…' : 'Waiting for a ' + p.specialty + ' to come online…');
    $('dn-search-line').textContent = line;
    var meta = [esc(p.specialty), p.mode === 'video' ? 'Video consult' : 'In person', money(p.price)];
    if (p.eta_seconds) meta.push('usually matched in ~' + fmtSecs(p.eta_seconds));
    meta.push('search ends in ' + fmtSecs(p.seconds_left));
    $('dn-search-meta').innerHTML = meta.join(' · ');

    var none = $('dn-none-online');
    if (p.online_doctors === 0) {
      none.innerHTML = '<strong>No ' + esc(p.specialty) + ' doctors are online right now.</strong> We\'ll keep looking until the search ends. You can also:' +
        '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">' +
        (p.waitlisted ? '<span class="pa-badge is-go">We\'ll notify you when one is online</span>' : '<button type="button" class="pa-btn-ghost pa-btn-sm" data-dn="waitlist">Notify me when one is online</button>') +
        (p.book_url ? '<a class="pa-btn-ghost pa-btn-sm" href="' + esc(p.book_url) + '">Book the next slot</a>' : '') + '</div>';
      none.classList.remove('pa-hide');
    } else {
      none.classList.add('pa-hide');
    }
  }

  function initials(name) {
    return String(name || '').replace(/^Dr\.\s*/, '').split(/\s+/).filter(Boolean).slice(0, 2).map(function (w) { return w.charAt(0).toUpperCase(); }).join('');
  }

  function renderMatched(p) {
    var d = p.doctor || {};
    var photo = $('dn-m-photo');
    photo.innerHTML = d.photo_url ? '<img src="' + esc(d.photo_url) + '" alt="">' : esc(initials(d.name));
    $('dn-m-name').textContent = d.name || 'Your doctor';
    $('dn-m-spec').textContent = [d.specialization, d.qualification].filter(Boolean).join(' · ');
    $('dn-m-rating').innerHTML = d.rating_count > 0 ? '★ ' + esc(d.rating) + ' <span class="pa-muted">(' + esc(d.rating_count) + ' reviews)</span>' : '<span class="pa-muted">New on Prompt Aid</span>';
    $('dn-m-badge').textContent = p.appointment_status === 'confirmed' ? 'Confirmed' : String(p.appointment_status || '').replace(/_/g, ' ');

    var where = '';
    var actions = '';
    if (p.mode === 'video') {
      where = '<div class="pa-note">Your video consult is ready. Join now — the room stays open for 30 minutes after the booked half hour.</div>';
      actions += p.can_join_video && p.video_url
        ? '<a class="pa-btn" href="' + esc(p.video_url) + '" target="_blank" rel="noopener">Join video</a>'
        : '<button class="pa-btn" type="button" disabled>Video room closed</button>';
    } else if (p.clinic) {
      where = '<div class="pa-label">Go to</div><div style="font-weight:700">' + esc(p.clinic.name) + '</div>' +
        (p.clinic.address ? '<div class="pa-muted" style="font-size:14px">' + esc(p.clinic.address) + '</div>' : '');
      actions += '<a class="pa-btn" href="' + esc(p.clinic.directions_url) + '" target="_blank" rel="noopener">Directions</a>';
      if (p.clinic.shuttle_url) actions += '<a class="pa-btn-ghost" href="' + esc(p.clinic.shuttle_url) + '">Book a shuttle there</a>';
    } else {
      where = '<div class="pa-note">The doctor will message you with directions to their rooms.</div>';
    }
    actions += '<button class="pa-btn-ghost" type="button" data-dn="message">Message doctor</button>';
    if (p.can_cancel) actions += '<button class="pa-btn-ghost" type="button" data-dn="cancel">Cancel consult</button>';
    $('dn-m-where').innerHTML = where;
    $('dn-m-actions').innerHTML = actions;
    $('dn-msg-appt').value = p.appointment_id;

    var bill = '';
    if (p.invoice) {
      var paid = p.invoice.status === 'paid';
      bill = '<div class="pa-dn-price"><div><div class="pa-label" style="margin:0">Consultation · ' + esc(p.invoice.invoice_no) + '</div><div class="v" style="font-size:22px">' + money(p.invoice.total) + '</div></div>' +
        '<div>' + (paid ? '<span class="pa-badge is-go">Paid</span>' : '<button class="pa-btn-quiet pa-btn-sm" type="button" data-dn="pay">Pay now by card</button>') + '</div></div>';
      if (p.can_cancel) {
        bill += '<p class="pa-muted" style="font-size:12px;margin:8px 0 0">' + (p.cancel_fee_now > 0
          ? 'Cancelling now costs ' + money(p.cancel_fee_now) + ' (the doctor has set time aside for you).'
          : 'You can still cancel free of charge for a short while after the doctor accepted.') + '</p>';
      }
    }
    $('dn-m-bill').innerHTML = bill;
  }

  function renderClosed(p) {
    var expired = p.status === 'expired';
    $('dn-c-title').textContent = expired ? 'No doctor available right now' : 'Request cancelled';
    $('dn-c-msg').textContent = p.outcome_message || (expired ? 'No doctor accepted in time. You have not been charged.' : 'This request was cancelled.');
    var a = '';
    if (expired) {
      a += p.waitlisted
        ? '<span class="pa-badge is-go" style="align-self:center">We\'ll notify you when a ' + esc(p.specialty) + ' is online</span>'
        : '<button type="button" class="pa-btn" data-dn="waitlist">Notify me when one is online</button>';
    }
    if (p.book_url) a += '<a class="pa-btn-ghost" href="' + esc(p.book_url) + '">Book the next slot</a>';
    a += '<button type="button" class="pa-btn-ghost" data-dn="new">Start a new request</button>';
    $('dn-c-actions').innerHTML = a;
  }

  // ---------------------------------------------------------------- polling
  function stopPoll() { if (state.pollTimer) { clearTimeout(state.pollTimer); state.pollTimer = null; } }
  function schedule(ms) { stopPoll(); state.pollTimer = setTimeout(poll, ms); }
  function poll() {
    var p = state.current;
    if (!p) return;
    if (document.hidden && p.status !== 'searching') { schedule(15000); return; }
    api('GET', cfg.base + '/' + p.id + '/status')
      .then(function (json) { error(''); render(json.data); })
      .catch(function () { schedule(6000); });
  }

  // ---------------------------------------------------------------- actions
  function act(kind, btn) {
    var p = state.current;
    if (state.busy) return;
    if (kind === 'new') { render(null); return; }
    if (kind === 'message') { $('dn-msg-form').submit(); return; }
    if (!p) return;
    if (kind === 'cancel') {
      var warn = p.status === 'matched' && p.cancel_fee_now > 0
        ? 'Cancel this consult? A cancellation fee of ' + money(p.cancel_fee_now) + ' applies.'
        : 'Cancel this request? You will not be charged.';
      if (!window.confirm(warn)) return;
    }
    var url = cfg.base + '/' + p.id + '/' + (kind === 'cancel' ? 'cancel' : kind === 'pay' ? 'pay' : 'waitlist');
    state.busy = true;
    if (btn) btn.disabled = true;
    api('POST', url, {})
      .then(function (json) { error(''); render(json.data); })
      .catch(function (e) { error(e.message); if (btn) btn.disabled = false; })
      .then(function () { state.busy = false; });
  }

  document.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target.closest('[data-dn]') : null;
    if (t) { e.preventDefault(); act(t.getAttribute('data-dn'), t); }
  });
  var cancelSearch = $('dn-cancel-search');
  if (cancelSearch) cancelSearch.addEventListener('click', function () { act('cancel', cancelSearch); });

  var spec = $('dn-specialty');
  if (spec) spec.addEventListener('change', loadQuote);
  Array.prototype.forEach.call(document.querySelectorAll('input[name="dn-mode"]'), function (r) { r.addEventListener('change', syncMode); });

  var locBtn = $('dn-locate');
  if (locBtn) locBtn.addEventListener('click', function () {
    var s = $('dn-locstatus');
    if (!navigator.geolocation) { s.textContent = 'Your browser can\'t share location — use your saved address instead.'; return; }
    s.textContent = 'Finding you…';
    navigator.geolocation.getCurrentPosition(
      function (pos) { setLoc(pos.coords.latitude, pos.coords.longitude, 'Using your current location'); },
      function () { s.textContent = 'Location permission was denied. Allow it in your browser, or use your saved address.'; },
      { enableHighAccuracy: true, timeout: 12000, maximumAge: 60000 }
    );
  });
  var savedBtn = $('dn-saved');
  if (savedBtn && cfg.savedLocation) savedBtn.addEventListener('click', function () {
    setLoc(cfg.savedLocation.lat, cfg.savedLocation.lng, cfg.savedLocation.label);
  });

  var reqBtn = $('dn-request');
  if (reqBtn) reqBtn.addEventListener('click', function () {
    if (state.busy) return;
    var m = mode();
    if (m === 'in_person' && !state.loc) { error('Share your location (or use your saved address) so we can find rooms near you.'); return; }
    var body = { specialty: specialty(), mode: m };
    if (m === 'in_person') { body.lat = state.loc.lat; body.lng = state.loc.lng; }
    state.busy = true; reqBtn.disabled = true; error('');
    api('POST', cfg.storeUrl, body)
      .then(function (json) { render(json.data); })
      .catch(function (e) {
        error(e.message);
        if (e.payload && e.payload.data) render(e.payload.data);
      })
      .then(function () { state.busy = false; reqBtn.disabled = false; });
  });

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden && state.current && state.current.status !== 'expired' && state.current.status !== 'cancelled') poll();
  });

  syncMode();
  render(state.current);
})();
/* ========================== END DOCTOR NOW ========================== */
