/*
 * PromptAid payment sheet (website).
 * choose method -> embedded gateway widget (our /pay/{id}/embed page in an
 * iframe) -> poll the webhook-confirmed status -> success.
 * The browser never decides that something is paid: only the server does.
 */
(function () {
  'use strict';
  var cfg = window.PromptAidPayConfig;
  var root = document.getElementById('pa-pay');
  if (!cfg || !root) return;

  var $ = function (id) { return document.getElementById(id); };
  var state = null;
  var pollTimer = null;

  function money(n) { return 'R ' + Number(n || 0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ' '); }
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function uid() { return Date.now().toString(36) + Math.random().toString(36).slice(2, 10); }

  function api(method, url, body) {
    return fetch(url, {
      method: method,
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': cfg.csrf, 'X-Requested-With': 'XMLHttpRequest' },
      body: body ? JSON.stringify(body) : undefined
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (json) {
        if (!res.ok) { var e = new Error(json.message || 'Something went wrong. Please try again.'); e.status = res.status; throw e; }
        return json;
      });
    });
  }

  function step(name) {
    Array.prototype.forEach.call(root.querySelectorAll('.pa-pay-step'), function (el) { el.hidden = el.getAttribute('data-step') !== name; });
    root.classList.toggle('is-frame', name === 'frame');
  }
  function error(msg) { var e = $('pa-pay-error'); e.textContent = msg || ''; e.hidden = !msg; }

  function renderMethods(opts) {
    var box = $('pa-pay-methods');
    $('pa-pay-test').hidden = !opts.mock;
    if (!opts.methods || !opts.methods.length) {
      box.innerHTML = '<p class="pa-muted">Online payment is not available for this item right now. Please contact us or pay at reception.</p>';
      $('pa-pay-go').disabled = true;
      return;
    }
    box.innerHTML = opts.methods.map(function (m, i) {
      return '<label class="pa-pay-method">' +
        '<input type="radio" name="pa-pay-method" value="' + esc(m.key) + '"' + (i === 0 ? ' checked' : '') + '>' +
        '<span class="pa-pay-mark" style="background:' + esc(m.color) + '">' + esc(m.mark) + '</span>' +
        '<span class="pa-pay-mtext"><strong>' + esc(m.label) + '</strong><small>' + esc(m.hint) + (m.test_mode && m.key !== 'mock' ? ' · sandbox' : '') + '</small></span>' +
        '<span class="pa-pay-tick"></span></label>';
    }).join('');
    $('pa-pay-save-wrap').hidden = !(opts.save_card_supported && state.service === 'ride');
    $('pa-pay-go').disabled = false;
    $('pa-pay-go').textContent = state.kind === 'subscribe' ? 'Subscribe · ' + money(state.amount) : 'Pay ' + money(state.amount);
  }

  function open(opts) {
    stopPoll();
    state = {
      kind: opts.kind || 'pay',
      payable_type: opts.payable_type,
      payable_id: opts.payable_id,
      plan_id: opts.plan_id,
      amount: opts.amount,
      title: opts.title || 'Pay',
      service: opts.service || ({ order: 'pharmacy', invoice: 'invoice', ride_charge: 'ride', ride: 'ride', consult: 'invoice' })[opts.payable_type] || (opts.kind === 'subscribe' ? 'subscription' : 'invoice'),
      onPaid: opts.onPaid,
      reload: opts.reload !== false,
      key: uid(),
      intent: null
    };
    $('pa-pay-title').textContent = state.title;
    $('pa-pay-kicker').textContent = state.kind === 'subscribe' ? 'Subscription' : 'Secure payment';
    $('pa-pay-amount').textContent = state.amount != null ? money(state.amount) : '';
    $('pa-pay-frame').removeAttribute('src');
    error('');
    step('choose');
    root.hidden = false;
    document.body.style.overflow = 'hidden';
    api('GET', cfg.optionsUrl + '?service=' + encodeURIComponent(state.service)).then(renderMethods).catch(function (e) { error(e.message); });
  }

  function close() {
    stopPoll();
    if (state && state.intent && state.intent.status === 'pending' && state.phase !== 'paid') {
      api('POST', cfg.intentBase + '/' + state.intent.id + '/cancel').catch(function () {});
    }
    root.hidden = true;
    document.body.style.overflow = '';
    $('pa-pay-frame').removeAttribute('src');
    var wasPaid = state && state.phase === 'paid';
    var s = state;
    state = null;
    if (wasPaid && s) {
      if (typeof s.onPaid === 'function') s.onPaid(s.intent);
      else if (s.reload) window.location.reload();
    }
  }

  function selected() { var r = root.querySelector('input[name="pa-pay-method"]:checked'); return r ? r.value : null; }

  function go() {
    if (!state) return;
    var gw = selected();
    if (!gw) { error('Choose a payment method.'); return; }
    var btn = $('pa-pay-go'); btn.disabled = true; error('');
    var req = state.kind === 'subscribe'
      ? api('POST', cfg.subscribeUrl, { plan_id: state.plan_id, gateway: gw, idempotency_key: state.key })
      : api('POST', cfg.intentsUrl, { payable_type: state.payable_type, payable_id: state.payable_id, gateway: gw, idempotency_key: state.key, save_card: $('pa-pay-save').checked });
    req.then(function (json) {
      state.intent = json.intent;
      state.phase = 'frame';
      if (json.intent && json.intent.amount != null) $('pa-pay-amount').textContent = money(json.intent.amount);
      $('pa-pay-frame').src = json.embed_url;
      $('pa-pay-newtab').href = json.embed_url;
      step('frame');
      startPoll(4000);
    }).catch(function (e) { error(e.message); }).then(function () { btn.disabled = false; });
  }

  function result(kind, title, msg) {
    step('result');
    var ic = $('pa-pay-ic');
    ic.className = 'pa-pay-ic' + (kind ? ' is-' + kind : '');
    ic.innerHTML = kind === 'go' ? '&#10003;' : kind === 'stop' ? '!' : '<span class="pa-pay-spin"></span>';
    $('pa-pay-rtitle').textContent = title;
    $('pa-pay-rmsg').textContent = msg || '';
    $('pa-pay-done').hidden = !kind;
  }

  function stopPoll() { if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; } }
  function startPoll(every) {
    stopPoll();
    var started = Date.now();
    (function tick() {
      if (!state || !state.intent) return;
      api('GET', cfg.intentBase + '/' + state.intent.id).then(function (json) {
        if (!state) return;
        var it = json.intent; state.intent = it;
        if (it.status === 'paid' || it.status === 'authorized') { state.phase = 'paid'; stopPoll(); result('go', it.status === 'authorized' ? 'Payment secured' : 'Payment successful', it.message); return; }
        if (it.status === 'failed') { stopPoll(); result('stop', 'Payment failed', it.message); return; }
        if (it.status === 'cancelled' && state.phase === 'confirming') { stopPoll(); result('stop', 'Payment cancelled', it.message); return; }
        if (state.phase === 'confirming' && Date.now() - started > 120000) {
          stopPoll();
          result('wait', 'Still confirming', 'Your bank has not confirmed yet. We will update this item automatically as soon as it does — you can close this window.');
          $('pa-pay-done').hidden = false;
          return;
        }
        pollTimer = setTimeout(tick, every);
      }).catch(function () { pollTimer = setTimeout(tick, every * 2); });
    })();
  }

  window.addEventListener('message', function (e) {
    if (!state || e.origin !== window.location.origin) return;
    var data; try { data = typeof e.data === 'string' ? JSON.parse(e.data) : e.data; } catch (x) { return; }
    if (!data || data.type !== 'pa-payment' || !state.intent || data.intent !== state.intent.id) return;
    if (data.event === 'completed') { state.phase = 'confirming'; result(null, 'Confirming your payment…', 'This usually takes a few seconds.'); startPoll(2000); }
    else if (data.event === 'cancelled') { api('POST', cfg.intentBase + '/' + state.intent.id + '/cancel').catch(function () {}); state.intent = null; state.key = uid(); stopPoll(); step('choose'); error('Payment cancelled. You can choose another method.'); }
    else if (data.event === 'error') { state.phase = 'confirming'; startPoll(2000); error(data.message || 'The payment could not be completed.'); }
  });

  $('pa-pay-go').addEventListener('click', go);
  $('pa-pay-close').addEventListener('click', close);
  $('pa-pay-done').addEventListener('click', close);
  $('pa-pay-back').addEventListener('click', function () {
    if (state && state.intent) api('POST', cfg.intentBase + '/' + state.intent.id + '/cancel').catch(function () {});
    if (state) { state.intent = null; state.key = uid(); }
    stopPoll(); $('pa-pay-frame').removeAttribute('src'); step('choose');
  });
  root.addEventListener('click', function (e) { if (e.target === root) close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !root.hidden) close(); });

  document.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target.closest('[data-pa-pay],[data-pa-subscribe]') : null;
    if (!t) return;
    e.preventDefault();
    if (t.hasAttribute('data-pa-subscribe')) {
      open({ kind: 'subscribe', plan_id: Number(t.getAttribute('data-pa-subscribe')), amount: Number(t.getAttribute('data-amount')), title: t.getAttribute('data-title') || 'Subscribe', reload: t.getAttribute('data-reload') !== '0' });
    } else {
      open({ payable_type: t.getAttribute('data-pa-pay'), payable_id: Number(t.getAttribute('data-id')), amount: Number(t.getAttribute('data-amount')), title: t.getAttribute('data-title') || 'Pay', reload: t.getAttribute('data-reload') !== '0' });
    }
  });

  window.PromptAidPay = {
    open: open,
    subscribe: function (o) { o = o || {}; o.kind = 'subscribe'; open(o); },
    close: close
  };

  // Deep link: ?pay=order:12 opens the sheet on load (e.g. after shop checkout).
  var m = /[?&]pay=(order|invoice|ride_charge):(\d+)/.exec(window.location.search);
  if (m) {
    var btn = document.querySelector('[data-pa-pay="' + m[1] + '"][data-id="' + m[2] + '"]');
    if (btn) btn.click();
  }
})();
