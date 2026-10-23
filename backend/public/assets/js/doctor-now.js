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
  var state = { current: cfg.current, loc: null, pollTimer: null, quoteSeq: 0, busy: false, lastBody: null };
  function modeLabel(m) { return m === 'video' ? 'Video consult' : m === 'callback' ? 'Call back' : 'In person'; }

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
    var m = mode();
    show('dn-inperson', m === 'in_person');
    show('dn-callback', m === 'callback');
    var b = $('dn-request');
    if (b) b.textContent = m === 'video' ? 'Start video consult now' : m === 'callback' ? 'Request a call back' : 'Request a doctor now';
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

    // Upfront payment: doctors are only alerted once the payment is secured.
    if (p.status === 'searching' && p.payment && p.payment.required && window.PromptAidPay && state.payOpenedFor !== p.id) {
      state.payOpenedFor = p.id;
      window.PromptAidPay.open({
        payable_type: 'consult', payable_id: p.id, amount: p.payment.amount, service: 'invoice',
        title: 'Pay to connect with a doctor', onPaid: function () { poll(); }, reload: false
      });
    }
    if (p.status === 'searching') { renderSearching(p); schedule(3000); }
    else if (p.status === 'matched') {
      renderMatched(p);
      // Video: keep a short poll until the call rings / starts; call back: watch for "calling now".
      schedule(p.mode === 'video' && !p.ring ? 4000 : p.mode === 'callback' ? 8000 : 15000);
    }
    else { renderClosed(p); }
  }

  function renderSearching(p) {
    var line;
    if (p.broadcast && p.alerted_doctors > 0) {
      line = 'Sent to ' + p.alerted_doctors + ' available doctor' + (p.alerted_doctors === 1 ? '' : 's') + ' — the first to accept will take your consult.';
    } else if (p.offer_pending) {
      line = 'Asking a doctor now (' + p.attempts + ' of up to ' + p.max_attempts + ')…';
    } else {
      line = p.online_doctors > 0 ? 'Alerting available doctors…' : 'Waiting for a ' + p.specialty + ' to come online…';
    }
    $('dn-search-line').textContent = line;
    var st = $('dn-search-title');
    if (st) st.textContent = p.mode === 'callback' ? 'Finding a doctor to call you' : p.mode === 'video' ? 'Finding a doctor for your video consult' : 'Finding your doctor';
    var meta = [esc(p.specialty), modeLabel(p.mode), money(p.price)];
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
    // Consult hub: once a doctor accepts a video / call-back consult, go straight to the
    // connection options (video, in-app voice, chat; phone / WhatsApp fallback). Only once
    // per consult per tab, so coming back here shows the card with an "Open consult" button.
    if (p.hub_url && (p.mode === 'video' || p.mode === 'callback') && (p.appointment_status === 'pending' || p.appointment_status === 'confirmed' || p.appointment_status === 'checked_in')) {
      var hubKey = 'pa-hub-opened-' + p.appointment_id, seenHub = null;
      try { seenHub = window.sessionStorage.getItem(hubKey); window.sessionStorage.setItem(hubKey, '1'); } catch (e) { /* private mode */ }
      if (!seenHub) { window.location.replace(p.hub_url); return; }
    }
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
      var joinUrl = p.call_url || p.video_url;
      where = p.ring
        ? '<div class="pa-note"><strong>' + esc(d.name || 'Your doctor') + ' is ready.</strong> Joining your video consult…</div>'
        : '<div class="pa-note">' + esc(d.name || 'Your doctor') + ' accepted. Your screen will ring and join the video consult automatically as soon as the doctor is in the room — keep this page open.</div>';
      actions += p.can_join_video && joinUrl
        ? '<a class="pa-btn" href="' + esc(joinUrl) + '">Join video now</a>'
        : '<button class="pa-btn" type="button" disabled>Video room closed</button>';
      if (p.ring && window.PromptAidRing) window.PromptAidRing.show(p.ring);
    } else if (p.mode === 'callback' && p.callback) {
      var cb = p.callback;
      var due = cb.due_at ? new Date(cb.due_at) : null;
      var dueTxt = due ? due.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';
      where = cb.outcome === 'no_answer'
        ? '<div class="pa-note-stop"><strong>' + esc(d.name || 'Your doctor') + ' tried to call you.</strong> Keep your phone close — they will try again on ' + esc(cb.phone_display || '') + '.</div>'
        : cb.called_at
          ? '<div class="pa-note"><strong>' + esc(d.name || 'Your doctor') + ' is calling you now</strong> on ' + esc(cb.phone_display || '') + ' (' + esc(cb.channel_label) + '). Answer your phone.</div>'
          : '<div class="pa-note"><strong>' + esc(d.name || 'Your doctor') + ' will call you on ' + esc(cb.phone_display || '') + ' within ' + esc(cb.target_minutes) + ' minutes</strong>' + (dueTxt ? ' (by about ' + esc(dueTxt) + ')' : '') + '. ' + esc(cb.channel_label) + '. Keep your phone close and the line free.</div>';
    } else if (p.clinic) {
      where = '<div class="pa-label">Go to</div><div style="font-weight:700">' + esc(p.clinic.name) + '</div>' +
        (p.clinic.address ? '<div class="pa-muted" style="font-size:14px">' + esc(p.clinic.address) + '</div>' : '');
      actions += '<a class="pa-btn" href="' + esc(p.clinic.directions_url) + '" target="_blank" rel="noopener">Directions</a>';
      if (p.clinic.shuttle_url) actions += '<a class="pa-btn-ghost" href="' + esc(p.clinic.shuttle_url) + '">Book a shuttle there</a>';
    } else {
      where = '<div class="pa-note">The doctor will message you with directions to their rooms.</div>';
    }
    if (p.hub_url && (p.mode === 'video' || p.mode === 'callback')) actions = '<a class="pa-btn" href="' + esc(p.hub_url) + '">Open consult: video, voice &amp; chat</a>' + actions.replace('class="pa-btn"', 'class="pa-btn-ghost"');
    actions += '<button class="pa-btn-ghost" type="button" data-dn="message">Message doctor</button>';
    if (p.can_cancel) actions += '<button class="pa-btn-ghost" type="button" data-dn="cancel">Cancel consult</button>';
    $('dn-m-where').innerHTML = where;
    $('dn-m-actions').innerHTML = actions;
    $('dn-msg-appt').value = p.appointment_id;

    var bill = '';
    if (p.invoice) {
      var paid = p.invoice.status === 'paid';
      bill = '<div class="pa-dn-price"><div><div class="pa-label" style="margin:0">Consultation · ' + esc(p.invoice.invoice_no) + '</div><div class="v" style="font-size:22px">' + money(p.invoice.total) + '</div></div>' +
        '<div>' + (paid ? '<span class="pa-badge is-go">Paid</span>' : (p.payment && p.payment.state === 'secured') ? '<span class="pa-badge is-go">Paid upfront</span>' : '<button class="pa-btn-quiet pa-btn-sm" type="button" data-dn="pay">Pay now</button>') + '</div></div>';
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
      a += '<button type="button" class="pa-btn" data-dn="retry">Try again</button>';
      if (p.dispatch_tel) a += '<a class="pa-btn-ghost" href="' + esc(p.dispatch_tel) + '">Call dispatch · ' + esc(p.dispatch_phone || '') + '</a>';
      a += p.waitlisted
        ? '<span class="pa-badge is-go" style="align-self:center">We\'ll notify you when a ' + esc(p.specialty) + ' is online</span>'
        : '<button type="button" class="pa-btn-ghost" data-dn="waitlist">Notify me when one is online</button>';
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
    if (kind === 'retry') { if (state.lastBody) submit(state.lastBody, btn); else render(null); return; }
    if (kind === 'message') { $('dn-msg-form').submit(); return; }
    if (!p) return;
    if (kind === 'cancel') {
      var warn = p.status === 'matched' && p.cancel_fee_now > 0
        ? 'Cancel this consult? A cancellation fee of ' + money(p.cancel_fee_now) + ' applies.'
        : 'Cancel this request? You will not be charged.';
      if (!window.confirm(warn)) return;
    }
    // Pay through the shared payment sheet (PayFast / Paystack / Ozow, or test mode).
    if (kind === 'pay' && window.PromptAidPay && p.invoice) {
      window.PromptAidPay.open({
        payable_type: 'invoice', payable_id: p.invoice.id, amount: p.invoice.total,
        title: 'Consultation · ' + p.invoice.invoice_no,
        onPaid: function () { if (typeof poll === 'function') poll(); else window.location.reload(); }
      });
      return;
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

  function submit(body, btn) {
    if (state.busy) return;
    state.lastBody = body;
    state.busy = true; if (btn) btn.disabled = true; error('');
    api('POST', cfg.storeUrl, body)
      .then(function (json) { render(json.data); })
      .catch(function (e) {
        error(e.message);
        if (e.payload && e.payload.data) render(e.payload.data);
      })
      .then(function () { state.busy = false; if (btn) btn.disabled = false; });
  }

  var reqBtn = $('dn-request');
  if (reqBtn) reqBtn.addEventListener('click', function () {
    if (state.busy) return;
    var m = mode();
    if (m === 'in_person' && !state.loc) { error('Share your location (or use your saved address) so we can find rooms near you.'); return; }
    var body = { specialty: specialty(), mode: m };
    if (cfg.triageRef) body.triage_reference = cfg.triageRef;
    if (m === 'in_person') { body.lat = state.loc.lat; body.lng = state.loc.lng; var rk = $('dn-radius'); if (rk && rk.value) body.radius_km = Number(rk.value); }
    if (m === 'callback') {
      var ph = $('dn-cb-phone'), ch = document.querySelector('input[name="dn-cb-channel"]:checked'), nt = $('dn-cb-note');
      body.callback_phone = ph ? ph.value.trim() : '';
      if (!body.callback_phone) { error('Enter the phone number the doctor should call.'); if (ph) ph.focus(); return; }
      body.callback_channel = ch ? ch.value : 'either';
      if (nt && nt.value.trim()) body.callback_note = nt.value.trim();
    }
    submit(body, reqBtn);
  });

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden && state.current && state.current.status !== 'expired' && state.current.status !== 'cancelled') poll();
  });

  syncMode();
  render(state.current);
})();
/* ========================== END DOCTOR NOW ========================== */
