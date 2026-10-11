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
   PromptAid Emergency Scale — entry modal. Shows once per browsing
   session, and on demand from any [data-pa-open-triage] control (the
   header "Emergency" button, "See a doctor now", etc.).
   Markup is injected so every page gets it without duplicating HTML.
   Set data-pa-no-triage on <body> to suppress the automatic show (the
   on-demand open still works).
   =================================================================== */
(function () {
  'use strict';

  var KEY = 'pa-triage-seen';
  var base = document.body.getAttribute('data-pa-root') || '.';

  // Rendered server-side into window.PromptAidConfig (see
  // components/layout.blade.php) from admin-editable Settings /
  // EmergencyContact rows, so this static file never hardcodes a number.
  var cfg = window.PromptAidConfig || {};
  var dispatch = cfg.dispatch || { phone: '081 670 9522', tel: 'tel:0816709522' };
  var urls = cfg.urls || {};
  var findCare = urls.findCare || (base + '/doctors');

  function esc(v) {
    return String(v).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  // Popup copy is admin-editable (Site content → Emergency popup); the
  // literals here are only a fallback if the config global is missing.
  var popup = cfg.popup || {};
  var copy = popup.levels || {};
  function lv(colour, field, fallback) { return (copy[colour] && copy[colour][field]) || fallback; }

  // [colour, letter, title, description, timeframe, action label, href]
  var levels = [
    ['red',    'R', lv('red', 'title', 'I need help right now'),      lv('red', 'description', 'Not breathing properly, chest pain, heavy bleeding, unconscious, fitting, a serious injury.'), lv('red', 'timeframe', 'Immediately'),             lv('red', 'action', 'Get ambulance now!'),                    dispatch.tel],
    ['orange', 'O', lv('orange', 'title', 'This is very urgent'),     lv('orange', 'description', 'Severe pain, a burn, a deep cut, a head knock, sudden weakness, a baby who will not feed.'),  lv('orange', 'timeframe', 'Within 30 minutes'),    lv('orange', 'action', 'Get connected to a Doctor in 30min'), urls.doctorNow || (base + '/see-a-doctor-now')],
    ['yellow', 'Y', lv('yellow', 'title', 'I need to be seen today'), lv('yellow', 'description', 'Fever, vomiting, a wound, pain that is getting worse, a child who is unwell.'),               lv('yellow', 'timeframe', 'Within 8 hours'),       lv('yellow', 'action', 'Manually book a Doctor to see today'), findCare],
    ['green',  'G', lv('green', 'title', 'It can wait'),              lv('green', 'description', 'Book a specialist, dentist, allied practitioner e.g. physio, radiology/x-ray, lab services.'), lv('green', 'timeframe', 'In the next few days'), lv('green', 'action', 'Book a non urgent service or consult'), findCare]
  ];

  var waIcon = '<svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" fill="currentColor"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38a9.9 9.9 0 0 0 4.74 1.21h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.83 9.83 0 0 0 12.04 2zm0 18.15h-.01a8.23 8.23 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.2 8.2 0 0 1-1.26-4.38c0-4.54 3.7-8.23 8.25-8.23 2.2 0 4.27.86 5.82 2.42a8.18 8.18 0 0 1 2.41 5.82c0 4.54-3.7 8.23-8.23 8.23zm4.52-6.16c-.25-.12-1.47-.72-1.69-.81-.23-.08-.39-.12-.56.13-.16.25-.64.81-.79.97-.14.17-.29.19-.54.06-.25-.12-1.05-.39-1.99-1.23-.74-.66-1.23-1.47-1.38-1.72-.14-.25-.02-.38.11-.51.11-.11.25-.29.37-.43.13-.15.17-.25.25-.42.08-.17.04-.31-.02-.43-.06-.12-.56-1.34-.76-1.84-.2-.48-.41-.42-.56-.43h-.48c-.17 0-.43.06-.66.31-.22.25-.86.85-.86 2.07 0 1.22.89 2.4 1.01 2.56.12.17 1.75 2.67 4.23 3.74.59.26 1.05.41 1.41.52.59.19 1.13.16 1.56.1.48-.07 1.47-.6 1.67-1.18.21-.58.21-1.07.14-1.18-.06-.1-.22-.16-.47-.28z"/></svg>';

  var wrap = document.createElement('div');
  wrap.className = 'pa-modal';
  wrap.setAttribute('role', 'dialog');
  wrap.setAttribute('aria-modal', 'true');
  wrap.setAttribute('aria-label', 'Do you have an emergency?');
  wrap.setAttribute('hidden', '');
  wrap.innerHTML =
    '<div class="pa-modal-box">' +
      '<div class="pa-modal-head">' +
        '<div>' +
          '<div class="pa-row" style="margin-bottom:8px"><span class="pa-blip"></span>' +
          '<span class="pa-eyebrow">' + esc(popup.eyebrow || 'PromptAid Emergency Scale') + '</span></div>' +
          '<h2 style="font-size:28px">' + esc(popup.title || 'Do you have an emergency?') + '</h2>' +
          '<p class="pa-muted" style="margin:6px 0 0;font-size:14px">' + esc(popup.intro || 'Pick the line that sounds most like you.') + '</p>' +
        '</div>' +
        '<button class="pa-x" type="button" data-pa-triage-close aria-label="Close">&times;</button>' +
      '</div>' +
      '<div class="pa-modal-body">' +
        '<div class="pa-sats">' +
          levels.map(function (l) {
            return '<a class="pa-sats-btn ' + l[0] + '" href="' + esc(l[6]) + '">' +
              '<span class="sig">' + l[1] + '</span>' +
              '<span><span class="t">' + esc(l[2]) + '</span><span class="d">' + esc(l[3]) + '</span></span>' +
              '<span class="w">' + esc(l[4]) + '<span class="pa-sats-act">(' + esc(l[5]) + ')</span></span></a>';
          }).join('') +
        '</div>' +
        '<div class="pa-note-stop" style="margin-top:18px">' +
          '<strong>' + esc(popup.note || 'If someone is not breathing, call the PromptAid dispatch centre now.') + ' <a href="' + esc(dispatch.tel) + '" style="color:inherit">' + esc(dispatch.phone) + '</a></strong> ' +
          'This tool is a pre-triage aid, not a diagnosis. A qualified practitioner performs the formal triage. ' +
          '<a href="' + esc(base) + '/emergency" style="color:inherit">Not sure? Answer a few questions</a>.' +
        '</div>' +
      '</div>' +
      '<div class="pa-modal-foot">' +
        '<a class="pa-btn-whatsapp" href="' + esc(urls.whatsapp || '#') + '" target="_blank" rel="noopener">' + waIcon + esc(popup.askDoctorLabel || 'ASK A DOCTOR') + '</a>' +
        '<a class="pa-btn-video" href="' + esc(urls.virtualConsult || (base + '/virtual-consult')) + '">' + esc(popup.videoLabel || 'VIRTUAL/VIDEO consult') + '</a>' +
      '</div>' +
    '</div>';

  function markSeen() { try { sessionStorage.setItem(KEY, '1'); } catch (e) {} }
  function close() { wrap.setAttribute('hidden', ''); markSeen(); }
  function open() { wrap.removeAttribute('hidden'); }
  window.paOpenTriage = open;

  document.body.appendChild(wrap);
  wrap.addEventListener('click', function (e) {
    if (e.target === wrap || e.target.closest('[data-pa-triage-close]')) close();
    // any choice also counts as answered
    else if (e.target.closest('a')) markSeen();
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !wrap.hasAttribute('hidden')) close(); });
  document.addEventListener('click', function (e) {
    var trigger = e.target.closest('[data-pa-open-triage]');
    if (trigger) { e.preventDefault(); open(); }
  });

  if (document.body.hasAttribute('data-pa-no-triage')) return;
  var seen = false;
  try { seen = !!sessionStorage.getItem(KEY); } catch (e) { seen = true; }
  if (!seen) open();
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
        !btn.hasAttribute('data-pa-triage-close') && !btn.hasAttribute('data-pa-open-triage') && !btn.classList.contains('pa-symptom') && !btn.classList.contains('pa-tab')) {
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
