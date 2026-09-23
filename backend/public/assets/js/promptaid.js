/* Prompt Aid — shared behaviour. No dependencies. */
(function () {
  'use strict';

  // Tab strips: [data-pa-tabs] with .pa-tab children, panels via data-pa-panel
  document.addEventListener('click', function (e) {
    var tab = e.target.closest('.pa-tab[data-pa-target]');
    if (tab) {
      var scope = tab.closest('[data-pa-tabs]');
      if (!scope) return;
      e.preventDefault();
      scope.querySelectorAll('.pa-tab').forEach(function (t) { t.classList.toggle('is-on', t === tab); });
      var key = tab.getAttribute('data-pa-target');
      document.querySelectorAll('[data-pa-panel]').forEach(function (p) {
        p.classList.toggle('pa-hide', p.getAttribute('data-pa-panel') !== key);
      });
    }

    var chk = e.target.closest('.pa-check');
    if (chk) chk.classList.toggle('is-on');
  });

  // Role switcher in the admin sidebar
  var sw = document.querySelector('[data-pa-roleswitch]');
  if (sw) {
    sw.addEventListener('change', function () {
      if (this.value) window.location.href = this.value;
    });
  }
})();


/* ===================================================================
   SATS pre-triage entry modal — shows once per browsing session.
   Markup is injected so every page gets it without duplicating HTML.
   Set data-pa-no-triage on <body> to suppress (e.g. the flow itself).
   =================================================================== */
(function () {
  'use strict';
  if (document.body.hasAttribute('data-pa-no-triage')) return;

  var KEY = 'pa-triage-seen';
  try { if (sessionStorage.getItem(KEY)) return; } catch (e) { return; }

  var base = document.body.getAttribute('data-pa-root') || '.';

  var levels = [
    ['red',    'RED',    'I need help right now',   'Not breathing properly, chest pain, heavy bleeding, unconscious, fitting, a serious injury.', 'Immediately'],
    ['orange', 'ORANGE', 'This is very urgent',     'Severe pain, a burn, a deep cut, a head knock, sudden weakness, a baby who will not feed.',    'Within 10 minutes'],
    ['yellow', 'YELLOW', 'I need to be seen today', 'Fever, vomiting, a wound, pain that is getting worse, a child who is unwell.',                 'Within 60 minutes'],
    ['green',  'GREEN',  'It can wait',             'Repeat medicine, a routine check, a small complaint, a question for a pharmacist.',            'Within 4 hours']
  ];

  var wrap = document.createElement('div');
  wrap.className = 'pa-modal';
  wrap.setAttribute('role', 'dialog');
  wrap.setAttribute('aria-modal', 'true');
  wrap.setAttribute('aria-label', 'Do you have an emergency?');
  wrap.innerHTML =
    '<div class="pa-modal-box">' +
      '<div class="pa-modal-head">' +
        '<div>' +
          '<div class="pa-row" style="margin-bottom:8px"><span class="pa-blip"></span>' +
          '<span class="pa-eyebrow">South African Triage Scale</span></div>' +
          '<h2 style="font-size:28px">Do you have an emergency?</h2>' +
          '<p class="pa-muted" style="margin:6px 0 0;font-size:14px">Pick the line that sounds most like you. ' +
          'We will ask a few questions and send you to the nearest place that can actually treat it.</p>' +
        '</div>' +
        '<button class="pa-x" type="button" data-pa-triage-close aria-label="Close">&times;</button>' +
      '</div>' +
      '<div class="pa-modal-body">' +
        '<div class="pa-sats">' +
          levels.map(function (l) {
            return '<a class="pa-sats-btn ' + l[0] + '" href="' + base + '/emergency.html?start=' + l[0] + '">' +
              '<span class="sig">' + l[1].charAt(0) + '</span>' +
              '<span><span class="t">' + l[2] + '</span><span class="d">' + l[3] + '</span></span>' +
              '<span class="w">' + l[4] + '</span></a>';
          }).join('') +
        '</div>' +
        '<div class="pa-note-stop" style="margin-top:18px">' +
          '<strong>If someone is not breathing, call 10177 now.</strong> ' +
          'This tool is a pre-triage aid, not a diagnosis. A qualified practitioner performs the formal triage on arrival.' +
        '</div>' +
      '</div>' +
      '<div class="pa-modal-foot">' +
        '<a class="pa-emergency-cta" style="padding:12px 20px;font-size:15px" href="tel:10177">Call an ambulance · 10177</a>' +
        '<button class="pa-btn-ghost" type="button" data-pa-triage-close>No emergency, just browsing</button>' +
      '</div>' +
    '</div>';

  function close() {
    wrap.setAttribute('hidden', '');
    try { sessionStorage.setItem(KEY, '1'); } catch (e) {}
  }

  document.body.appendChild(wrap);
  wrap.addEventListener('click', function (e) {
    if (e.target === wrap || e.target.closest('[data-pa-triage-close]')) close();
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
  // any triage link also counts as answered
  wrap.addEventListener('click', function (e) {
    if (e.target.closest('.pa-sats-btn')) { try { sessionStorage.setItem(KEY, '1'); } catch (err) {} }
  });
})();


/* ===================================================================
   Interaction layer — makes every static control do something.
   In Laravel these become real form posts / Livewire actions; the
   behaviour here is the contract they must meet.
   =================================================================== */
(function () {
  'use strict';

  function toast(msg) {
    var t = document.createElement('div');
    t.textContent = msg;
    t.setAttribute('role', 'status');
    t.style.cssText = 'position:fixed;left:50%;bottom:28px;transform:translateX(-50%);z-index:1000;' +
      'background:#101012;color:#fff;font:700 14px Lato,sans-serif;padding:13px 20px;border-left:4px solid #F2C200;' +
      'box-shadow:0 8px 24px -12px rgba(16,16,18,.5)';
    document.body.appendChild(t);
    setTimeout(function () { t.style.transition = 'opacity .3s'; t.style.opacity = '0'; }, 2200);
    setTimeout(function () { t.remove(); }, 2600);
  }
  window.paToast = toast;

  var here = location.pathname.split('/').pop() || 'index.html';

  document.addEventListener('click', function (e) {
    /* 1. Generic tab strips and pagination: exclusive selection within the strip */
    var tab = e.target.closest('button.pa-tab');
    if (tab && !tab.hasAttribute('data-pa-target')) {
      var strip = tab.parentNode;
      var label = tab.textContent.trim();
      if (/^(Previous|Next)$/.test(label)) {
        var nums = [].filter.call(strip.children, function (c) { return /^\d+$/.test(c.textContent.trim()); });
        var cur = nums.findIndex(function (c) { return c.classList.contains('is-on'); });
        var nxt = label === 'Next' ? Math.min(cur + 1, nums.length - 1) : Math.max(cur - 1, 0);
        nums.forEach(function (c, i) { c.classList.toggle('is-on', i === nxt); });
      } else {
        [].forEach.call(strip.querySelectorAll('.pa-tab'), function (c) { c.classList.toggle('is-on', c === tab); });
      }
      return;
    }

    /* 2. Export CSV — exports the nearest table for real */
    var exp = e.target.closest('button');
    if (exp && /Export CSV/.test(exp.textContent)) {
      var table = (exp.closest('.pa-filterbar') || exp).parentNode.querySelector('table');
      if (!table) return;
      var csv = [].map.call(table.rows, function (r) {
        return [].map.call(r.cells, function (c) { return '"' + c.innerText.replace(/\s+/g, ' ').trim().replace(/"/g, '""') + '"'; }).join(',');
      }).join('\n');
      var a = document.createElement('a');
      a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
      a.download = (document.title.split(' · ')[0] || 'export').toLowerCase().replace(/\W+/g, '-') + '.csv';
      a.click();
      toast('Exported ' + (table.rows.length - 1) + ' rows');
      return;
    }

    /* 3. Actions that point back at the same page (Save, Approve, Edit…) confirm instead of reloading */
    var link = e.target.closest('a[href]');
    if (link) {
      var href = link.getAttribute('href');
      var target = href.split('?')[0].split('#')[0].split('/').pop();
      if (href.indexOf('./') === 0 && target === here && !link.closest('.pa-navlink, .pa-acctnav')) {
        e.preventDefault();
        var verb = link.textContent.trim();
        var msg = /^Save/.test(verb) ? 'Saved' :
                  /^(Approve|Accept|Sign|Dispatch|Assign)/.test(verb) ? verb + ' — done' :
                  /^(Reject|Revoke|Pass|Cancel)/.test(verb) ? verb + ' — recorded' :
                  /^(Edit|Open|View|Change)/.test(verb) ? 'Opening editor…' :
                  /^\+|^Add|^New|^Upload|^Invite/.test(verb) ? verb.replace(/^\+\s*/, '') + ' — form opened' :
                  verb + ' — done';
        toast(msg);
      }
    }

    /* 4. Plain buttons with no other role (Add to basket, +/− qty, time slots) */
    var btn = e.target.closest('button');
    if (btn && !btn.closest('form') && !btn.hasAttribute('data-next') && !btn.hasAttribute('data-back') &&
        !btn.hasAttribute('data-pa-triage-close') && !btn.classList.contains('pa-symptom') && !btn.classList.contains('pa-tab')) {
      var t = btn.textContent.trim();
      if (t === '+' || t === '−') {
        var qty = btn.parentNode.querySelector('div');
        if (qty) { var n = parseInt(qty.textContent, 10) || 1; qty.textContent = Math.max(1, n + (t === '+' ? 1 : -1)); }
      } else if (t) toast(t + ' — done');
    }
  });

  /* 5. Shuttle page: prefill the drop-off from ?to= so every "Add shuttle" lands ready to book */
  var to = new URLSearchParams(location.search).get('to');
  if (to) {
    var fields = document.querySelectorAll('#request input.pa-field');
    if (fields[1]) { fields[1].value = to; fields[1].style.background = '#FEF6DA'; }
    if (location.search.indexOf('priority=emergency') > -1) toast('Emergency priority — drop-off set to ' + to);
    else toast('Drop-off set to ' + to);
  }
})();
