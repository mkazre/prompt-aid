/* Prompt Aid — support stream behaviours (loaded on every public page):
   announcement dismissal, newsletter AJAX signup, double-submit guard,
   help-centre instant filter, cookie-notice lift for the WhatsApp button,
   doctor profile Share + QR. Plain ES5, no dependencies. */
(function () {
  'use strict';

  function store(op, key, value) {
    try {
      if (op === 'get') return window.localStorage.getItem(key);
      window.localStorage.setItem(key, value);
    } catch (e) { /* private mode — not remembered */ }
    return null;
  }

  // ---- Announcement bars: dismissal remembered per id + last edit ----
  var bars = document.querySelectorAll('[data-pa-annc]');
  Array.prototype.forEach.call(bars, function (bar) {
    var key = 'pa_annc_dismissed_' + bar.getAttribute('data-pa-annc');
    var close = bar.querySelector('[data-pa-annc-close]');
    if (!close) return;
    if (store('get', key)) { bar.hidden = true; return; }
    bar.hidden = false;
    close.addEventListener('click', function () {
      bar.hidden = true;
      store('set', key, '1');
    });
  });

  // ---- Forms that must only submit once (tickets, replies, surveys) ----
  Array.prototype.forEach.call(document.querySelectorAll('form[data-pa-once]'), function (form) {
    form.addEventListener('submit', function (e) {
      if (form.getAttribute('data-pa-sent')) { e.preventDefault(); return; }
      form.setAttribute('data-pa-sent', '1');
      var btn = form.querySelector('button[type=submit]');
      if (btn) { btn.disabled = true; btn.style.opacity = '.6'; }
    });
  });

  // ---- Newsletter signup (AJAX, double-submit safe) ----
  Array.prototype.forEach.call(document.querySelectorAll('form[data-pa-newsletter]'), function (form) {
    var msg = form.querySelector('[data-pa-newsletter-msg]');
    var btn = form.querySelector('button[type=submit]');
    var busy = false;
    function say(text, ok) {
      if (!msg) return;
      msg.textContent = text;
      msg.className = 'pa-news-msg ' + (ok ? 'is-ok' : 'is-err');
    }
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (busy) return;
      var email = form.querySelector('input[name=email]');
      var consent = form.querySelector('input[name=consent]');
      if (!email.value || !/^\S+@\S+\.\S+$/.test(email.value)) { say('Please enter a valid email address.', false); email.focus(); return; }
      if (consent && !consent.checked) { say('Please tick the box to agree to receive our emails.', false); return; }
      busy = true;
      if (btn) btn.disabled = true;
      var token = (document.querySelector('meta[name=csrf-token]') || {}).content || '';
      fetch(form.action, {
        method: 'POST',
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
        body: new FormData(form),
        credentials: 'same-origin'
      }).then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (data) { return { ok: r.ok, status: r.status, data: data }; });
      }).then(function (res) {
        if (res.ok) {
          say(res.data.message || 'Thanks — you\'re subscribed.', true);
          form.reset();
        } else if (res.status === 429) {
          say('Too many attempts. Please try again in a minute.', false);
        } else {
          var errs = res.data && res.data.errors;
          var first = errs ? errs[Object.keys(errs)[0]][0] : (res.data && res.data.message);
          say(first || 'Something went wrong. Please try again.', false);
        }
      }).catch(function () {
        say('Could not reach the server. Please try again.', false);
      }).then(function () {
        busy = false;
        if (btn) btn.disabled = false;
      });
    });
  });

  // ---- Help centre: instant filter while typing ----
  var q = document.getElementById('pa-help-q');
  if (q) {
    var items = document.querySelectorAll('[data-pa-help-item]');
    var groups = document.querySelectorAll('[data-pa-help-group]');
    var none = document.getElementById('pa-help-none');
    q.addEventListener('input', function () {
      var term = q.value.trim().toLowerCase();
      var shown = 0;
      Array.prototype.forEach.call(items, function (it) {
        var hit = !term || (it.getAttribute('data-text') || '').indexOf(term) !== -1;
        it.hidden = !hit;
        if (hit) shown++;
        if (term && hit && shown === 1) it.open = true;
      });
      Array.prototype.forEach.call(groups, function (g) {
        g.hidden = !g.querySelector('[data-pa-help-item]:not([hidden])');
      });
      if (none) none.hidden = shown > 0 || items.length === 0;
    });
  }

  // ---- Keep the WhatsApp button clear of the cookie notice (:has fallback) ----
  var cookie = document.getElementById('pa-cookie');
  if (cookie && document.querySelector('.pa-wa-fab')) {
    var sync = function () { document.body.classList.toggle('pa-cookie-open', !cookie.hidden); };
    sync();
    if (window.MutationObserver) new MutationObserver(sync).observe(cookie, { attributes: true, attributeFilter: ['hidden'] });
  }

  // ---- Doctor profile: Share + QR ----
  var share = document.querySelector('[data-pa-share]');
  if (share) {
    share.addEventListener('click', function () {
      var url = share.getAttribute('data-url');
      var title = share.getAttribute('data-title') || document.title;
      if (navigator.share) {
        navigator.share({ title: title, text: title, url: url }).catch(function () {});
        return;
      }
      var done = function () {
        var old = share.textContent;
        share.textContent = 'Link copied';
        setTimeout(function () { share.textContent = old; }, 2000);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(done, function () { window.prompt('Copy this link:', url); });
      } else {
        window.prompt('Copy this link:', url);
      }
    });
  }

  var qrOpen = document.querySelector('[data-pa-qr-open]');
  var qrModal = document.getElementById('pa-qr-modal');
  if (qrOpen && qrModal) {
    var close = function () { qrModal.hidden = true; };
    qrOpen.addEventListener('click', function () {
      qrModal.hidden = false;
      // Client-side fallback when the server could not render the SVG.
      var holder = qrModal.querySelector('[data-pa-qr-fallback]');
      if (holder && !holder.getAttribute('data-done') && window.QRCode) {
        holder.setAttribute('data-done', '1');
        new window.QRCode(holder, { text: holder.getAttribute('data-pa-qr-fallback'), width: 240, height: 240 });
      }
    });
    Array.prototype.forEach.call(qrModal.querySelectorAll('[data-pa-qr-close]'), function (b) { b.addEventListener('click', close); });
    qrModal.addEventListener('click', function (e) { if (e.target === qrModal) close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
  }
})();
