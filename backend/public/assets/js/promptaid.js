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

    /* 3. (removed) Same-page "./x.html" links used to fake a "Saved / Approved — done"
          toast from the static prototype. Real actions are now form posts. */

    /* 4. Quantity steppers (+/−). Other plain buttons no longer fake a "— done" toast. */
    var btn = e.target.closest('button');
    if (btn && !btn.closest('form') && !btn.hasAttribute('data-next') && !btn.hasAttribute('data-back') &&
        !btn.hasAttribute('data-pa-triage-close') && !btn.hasAttribute('data-pa-open-triage') && !btn.classList.contains('pa-symptom') && !btn.classList.contains('pa-tab')) {
      var t = btn.textContent.trim();
      if (t === '+' || t === '−') {
        var qty = btn.parentNode.querySelector('div');
        if (qty) { var n = parseInt(qty.textContent, 10) || 1; qty.textContent = Math.max(1, n + (t === '+' ? 1 : -1)); }
      }
    }
  });

  /* 5. Shuttle page: drop-off prefill (?to=) is now rendered server-side by
        ShuttlePageController@index; just acknowledge an emergency hand-off. */
  var to = new URLSearchParams(location.search).get('to');
  if (to && location.search.indexOf('priority=emergency') > -1) toast('Emergency priority — drop-off set to ' + to);
})();


/* ===================================================================
   Address typeahead — OpenStreetMap suggestions via /api/geocode.
   Usage:
     <input name="pickup_address" data-pa-address-autocomplete>
     <input type="hidden" name="pickup_lat"> <input type="hidden" name="pickup_lng">
   The lat/lng fields default to the input's name with _address -> _lat/_lng
   (override with data-pa-lat-field / data-pa-lng-field). Typing clears the
   coordinates; choosing a suggestion fills them. The server geocodes the
   free text as a fallback when no suggestion was picked.
   "Use my location": <button type="button" data-pa-geolocate="pickup_address">.
   =================================================================== */
(function () {
  'use strict';

  var root = (document.body.getAttribute('data-pa-root') || '').replace(/\/+$/, '');
  var api = root + '/api/geocode';
  var uid = 0;

  function coordField(input, which) {
    var form = input.form || document;
    var name = input.getAttribute('data-pa-' + which + '-field') ||
      (input.name || '').replace(/_address$/, '') + '_' + which;
    return form.querySelector('input[name="' + name + '"]');
  }

  function setCoords(input, lat, lng) {
    var la = coordField(input, 'lat'), ln = coordField(input, 'lng');
    if (la) la.value = lat == null ? '' : lat;
    if (ln) ln.value = lng == null ? '' : lng;
  }

  function attach(input) {
    if (input.__paAc) return;
    input.__paAc = true;

    var listId = 'pa-ac-' + (++uid);
    var wrap = document.createElement('div');
    wrap.className = 'pa-ac';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);

    var list = document.createElement('ul');
    list.className = 'pa-ac-list';
    list.id = listId;
    list.setAttribute('role', 'listbox');
    list.hidden = true;
    wrap.appendChild(list);

    input.setAttribute('autocomplete', 'off');
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', listId);

    var items = [], active = -1, timer = null, seq = 0, ctrl = null;

    function close() {
      list.hidden = true; list.innerHTML = ''; items = []; active = -1;
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
    }

    function highlight(i) {
      active = i;
      [].forEach.call(list.children, function (li, idx) {
        var on = idx === i;
        li.classList.toggle('is-on', on);
        li.setAttribute('aria-selected', on ? 'true' : 'false');
        if (on) { input.setAttribute('aria-activedescendant', li.id); li.scrollIntoView({ block: 'nearest' }); }
      });
    }

    function choose(i) {
      var it = items[i];
      if (!it) return;
      input.value = it.label;
      setCoords(input, it.lat, it.lng);
      close();
      input.dispatchEvent(new CustomEvent('pa:address-selected', { bubbles: true, detail: it }));
    }

    function status(text) {
      list.innerHTML = '<li class="pa-ac-status" role="presentation">' + text + '</li>';
      list.hidden = false;
      input.setAttribute('aria-expanded', 'true');
    }

    function render(results) {
      items = results || [];
      active = -1;
      list.innerHTML = '';
      if (!items.length) { status('No matching addresses — try adding a suburb or city'); return; }
      items.forEach(function (it, i) {
        var li = document.createElement('li');
        li.className = 'pa-ac-item';
        li.id = listId + '-' + i;
        li.setAttribute('role', 'option');
        var main = document.createElement('span');
        main.className = 'pa-ac-main';
        main.textContent = it.label;
        li.appendChild(main);
        if (it.full && it.full !== it.label) {
          var sub = document.createElement('span');
          sub.className = 'pa-ac-sub';
          sub.textContent = it.full;
          li.appendChild(sub);
        }
        // mousedown so it fires before the input's blur
        li.addEventListener('mousedown', function (e) { e.preventDefault(); choose(i); });
        li.addEventListener('mousemove', function () { if (active !== i) highlight(i); });
        list.appendChild(li);
      });
      list.hidden = false;
      input.setAttribute('aria-expanded', 'true');
    }

    function lookup(q) {
      var mine = ++seq;
      if (ctrl && ctrl.abort) ctrl.abort();
      ctrl = typeof AbortController !== 'undefined' ? new AbortController() : null;
      status('Searching…');
      fetch(api + '/autocomplete?q=' + encodeURIComponent(q), {
        headers: { Accept: 'application/json' },
        signal: ctrl ? ctrl.signal : undefined
      })
        .then(function (r) { return r.ok ? r.json() : []; })
        .then(function (data) { if (mine === seq && document.activeElement === input) render(Array.isArray(data) ? data : []); })
        .catch(function (err) {
          if (err && err.name === 'AbortError') return;
          if (mine === seq) status('Address lookup is unavailable — type the full address');
        });
    }

    input.addEventListener('input', function () {
      setCoords(input, null, null); // text changed: old coordinates no longer apply
      clearTimeout(timer);
      var q = input.value.trim();
      if (q.length < 3) { seq++; close(); return; }
      timer = setTimeout(function () { lookup(q); }, 300);
    });

    input.addEventListener('keydown', function (e) {
      if (list.hidden || !items.length) {
        if (e.key === 'Escape') close();
        return;
      }
      if (e.key === 'ArrowDown') { e.preventDefault(); highlight((active + 1) % items.length); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); highlight(active <= 0 ? items.length - 1 : active - 1); }
      else if (e.key === 'Enter') { if (active >= 0) { e.preventDefault(); choose(active); } }
      else if (e.key === 'Escape') { e.preventDefault(); close(); }
      else if (e.key === 'Tab') { close(); }
    });

    input.addEventListener('blur', function () { setTimeout(close, 120); });

    document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) close(); });
  }

  function geolocate(btn) {
    var name = btn.getAttribute('data-pa-geolocate');
    var form = btn.form || btn.closest('form') || document;
    var input = form.querySelector('input[name="' + name + '"]');
    if (!input) return;
    if (!navigator.geolocation) { if (window.paToast) window.paToast('Location is not available in this browser'); return; }

    var original = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Finding you…';
    function done() { btn.disabled = false; btn.textContent = original; }

    navigator.geolocation.getCurrentPosition(function (pos) {
      var lat = +pos.coords.latitude.toFixed(6), lng = +pos.coords.longitude.toFixed(6);
      setCoords(input, lat, lng);
      fetch(api + '/reverse?lat=' + lat + '&lng=' + lng, { headers: { Accept: 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) {
          input.value = data && data.label ? data.label : 'My current location';
          setCoords(input, lat, lng); // keep the precise GPS fix, not the rounded lookup
          done();
        })
        .catch(function () { input.value = 'My current location'; done(); });
    }, function () {
      done();
      if (window.paToast) window.paToast('We could not get your location — please type your address');
    }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 });
  }

  function init(scope) {
    [].forEach.call((scope || document).querySelectorAll('input[data-pa-address-autocomplete]'), attach);
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-pa-geolocate]');
    if (btn) { e.preventDefault(); geolocate(btn); }
  });

  window.paAddressAutocomplete = init;
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { init(); });
  else init();
})();

/* ===================================================================
   Search as you type — grouped results from /api/search.
   Usage:
     <form action="/doctors"><input name="search" data-pa-search></form>
   Suggestions (specialities, conditions & symptoms, doctors, clinics,
   pharmacy products) open under the input with the typed text in bold.
   Picking one navigates; Enter with nothing highlighted submits the form.
   =================================================================== */
(function () {
  'use strict';

  var root = (document.body.getAttribute('data-pa-root') || '').replace(/\/+$/, '');
  var api = root + '/api/search';
  var uid = 0;
  var GROUPS = [
    ['specialities', 'Specialities'],
    ['conditions', 'Conditions & Symptoms'],
    ['doctors', 'Doctors'],
    ['clinics', 'Clinics'],
    ['products', 'Pharmacy']
  ];

  function hrefFor(it) {
    if (it.url) return it.url;
    if (it.type === 'condition' || it.type === 'symptom') {
      if (it.urgent || !it.specialization) return root + '/emergency';
      return root + '/doctors?specialization=' + encodeURIComponent(it.specialization);
    }
    if (it.type === 'speciality' || it.type === 'service_category') {
      return root + '/doctors?specialization=' + encodeURIComponent(it.specialization || it.name);
    }
    return root + '/doctors?search=' + encodeURIComponent(it.name);
  }

  function bolded(text, term) {
    var frag = document.createDocumentFragment();
    var lower = text.toLowerCase(), needle = term.toLowerCase(), i = 0;
    while (needle && i < text.length) {
      var at = lower.indexOf(needle, i);
      if (at === -1) break;
      if (at > i) frag.appendChild(document.createTextNode(text.slice(i, at)));
      var b = document.createElement('strong');
      b.textContent = text.slice(at, at + needle.length);
      frag.appendChild(b);
      i = at + needle.length;
    }
    if (i < text.length) frag.appendChild(document.createTextNode(text.slice(i)));
    return frag;
  }

  function attach(input) {
    if (input.__paSearch) return;
    input.__paSearch = true;

    var listId = 'pa-search-' + (++uid);
    var wrap = document.createElement('div');
    wrap.className = 'pa-search-ac';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);

    var clear = document.createElement('button');
    clear.type = 'button';
    clear.className = 'pa-search-clear';
    clear.setAttribute('aria-label', 'Clear search');
    clear.innerHTML = '&times;';
    clear.hidden = !input.value;
    wrap.appendChild(clear);

    var panel = document.createElement('div');
    panel.className = 'pa-search-panel';
    panel.id = listId;
    panel.setAttribute('role', 'listbox');
    panel.hidden = true;
    wrap.appendChild(panel);

    input.setAttribute('autocomplete', 'off');
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', listId);

    var links = [], active = -1, timer = null, seq = 0, ctrl = null;

    function close() {
      panel.hidden = true; panel.innerHTML = ''; links = []; active = -1;
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
    }

    function status(text) {
      panel.innerHTML = '';
      var p = document.createElement('div');
      p.className = 'pa-search-status';
      p.textContent = text;
      panel.appendChild(p);
      panel.hidden = false;
      input.setAttribute('aria-expanded', 'true');
    }

    function highlight(i) {
      active = i;
      links.forEach(function (a, idx) {
        var on = idx === i;
        a.classList.toggle('is-on', on);
        a.setAttribute('aria-selected', on ? 'true' : 'false');
        if (on) { input.setAttribute('aria-activedescendant', a.id); a.scrollIntoView({ block: 'nearest' }); }
      });
    }

    function render(groups, term) {
      panel.innerHTML = '';
      links = []; active = -1;
      GROUPS.forEach(function (g) {
        var rows = (groups && groups[g[0]]) || [];
        if (!rows.length) return;
        var h = document.createElement('div');
        h.className = 'pa-search-group';
        h.textContent = g[1];
        panel.appendChild(h);
        rows.forEach(function (it) {
          var a = document.createElement('a');
          a.className = 'pa-search-item' + (it.urgent ? ' is-urgent' : '');
          a.href = hrefFor(it);
          a.id = listId + '-' + links.length;
          a.setAttribute('role', 'option');
          var img = it.avatar_url || it.logo_url;
          if (img) {
            var im = document.createElement('img');
            im.src = img; im.alt = ''; im.className = 'pa-search-thumb';
            a.appendChild(im);
          } else {
            var dot = document.createElement('span');
            dot.className = 'pa-search-dot pa-search-dot--' + it.type;
            a.appendChild(dot);
          }
          var body = document.createElement('span');
          body.className = 'pa-search-body';
          var main = document.createElement('span');
          main.className = 'pa-search-main';
          main.appendChild(bolded(String(it.name || ''), term));
          body.appendChild(main);
          if (it.subtitle) {
            var sub = document.createElement('span');
            sub.className = 'pa-search-sub';
            sub.textContent = it.subtitle;
            body.appendChild(sub);
          }
          a.appendChild(body);
          var idx = links.length;
          a.addEventListener('mousemove', function () { if (active !== idx) highlight(idx); });
          a.addEventListener('mousedown', function (e) { e.preventDefault(); });
          a.addEventListener('click', function () { close(); });
          links.push(a);
          panel.appendChild(a);
        });
      });
      if (!links.length) { status('No results for “' + term + '”'); return; }
      panel.hidden = false;
      input.setAttribute('aria-expanded', 'true');
    }

    function lookup(q) {
      var mine = ++seq;
      if (ctrl && ctrl.abort) ctrl.abort();
      ctrl = typeof AbortController !== 'undefined' ? new AbortController() : null;
      fetch(api + '?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' }, signal: ctrl ? ctrl.signal : undefined })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) { if (mine === seq && document.activeElement === input) render(data && data.groups, q); })
        .catch(function (err) {
          if (err && err.name === 'AbortError') return;
          if (mine === seq) status('Search is unavailable right now');
        });
    }

    input.addEventListener('input', function () {
      clear.hidden = !input.value;
      clearTimeout(timer);
      var q = input.value.trim();
      if (q.length < 2) { seq++; close(); return; }
      timer = setTimeout(function () { lookup(q); }, 250);
    });

    input.addEventListener('focus', function () {
      if (input.value.trim().length >= 2 && panel.hidden) lookup(input.value.trim());
    });

    input.addEventListener('keydown', function (e) {
      if (panel.hidden || !links.length) { if (e.key === 'Escape') close(); return; }
      if (e.key === 'ArrowDown') { e.preventDefault(); highlight((active + 1) % links.length); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); highlight(active <= 0 ? links.length - 1 : active - 1); }
      else if (e.key === 'Enter') { if (active >= 0) { e.preventDefault(); window.location.href = links[active].href; } }
      else if (e.key === 'Escape') { e.preventDefault(); close(); }
      else if (e.key === 'Tab') { close(); }
    });

    clear.addEventListener('click', function () {
      input.value = ''; clear.hidden = true; seq++; close(); input.focus();
    });

    input.addEventListener('blur', function () { setTimeout(close, 150); });
    document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) close(); });
  }

  function init(scope) {
    [].forEach.call((scope || document).querySelectorAll('input[data-pa-search]'), attach);
  }

  window.paSearch = init;
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { init(); });
  else init();
})();

/* ===================================================================
   Booking slot picker — month select + "Today" + day strip +
   session-grouped slots. Markup (doctors/show): an element with
   data-pa-slot-picker, data-slots-url, data-clinic-select (#id),
   data-date-input (#id), data-time-input (#id), data-submit (#id).
   Reads `sessions` from the slots endpoint; booked slots are struck
   through. The surrounding form still POSTs as before.
   =================================================================== */
(function () {
  'use strict';

  var DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function parse(s) { var p = String(s).split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); }
  function t12(hm) {
    var h = +String(hm).slice(0, 2), m = String(hm).slice(3, 5);
    return ((h % 12) || 12) + ':' + m + (h >= 12 ? ' PM' : ' AM');
  }
  function icon(start) {
    var h = +String(start).slice(0, 2);
    return h < 12 ? '☀︎' : h < 17 ? '⛅︎' : '☾';
  }

  function attach(el) {
    if (el.__paSlots) return;
    el.__paSlots = true;

    var url = el.getAttribute('data-slots-url');
    var clinic = document.querySelector(el.getAttribute('data-clinic-select'));
    var dateInput = document.querySelector(el.getAttribute('data-date-input'));
    var timeInput = document.querySelector(el.getAttribute('data-time-input'));
    var submit = document.querySelector(el.getAttribute('data-submit'));
    if (!url || !clinic || !dateInput || !timeInput) return;

    var today = new Date(); today.setHours(0, 0, 0, 0);
    var seq = 0;

    el.innerHTML =
      '<div class="pa-sp-head"><select class="pa-field pa-sp-month" aria-label="Month"></select>' +
      '<button type="button" class="pa-sp-today">Today</button></div>' +
      '<div class="pa-sp-strip" role="listbox" aria-label="Day"></div>' +
      '<div class="pa-label pa-sp-label">Available slots</div>' +
      '<div class="pa-sp-sessions" aria-live="polite"></div>';

    var month = el.querySelector('.pa-sp-month');
    var strip = el.querySelector('.pa-sp-strip');
    var sessionsEl = el.querySelector('.pa-sp-sessions');

    for (var i = 0; i < 12; i++) {
      var m = new Date(today.getFullYear(), today.getMonth() + i, 1);
      var o = document.createElement('option');
      o.value = m.getFullYear() + '-' + pad(m.getMonth() + 1);
      o.textContent = MONTHS[m.getMonth()] + ' ' + m.getFullYear();
      month.appendChild(o);
    }

    function setTime(v) {
      timeInput.value = v || '';
      if (submit) { submit.disabled = !v; submit.textContent = v ? 'Confirm booking · ' + t12(v) : 'Select a time'; }
    }

    function renderStrip() {
      var sel = parse(dateInput.value);
      month.value = sel.getFullYear() + '-' + pad(sel.getMonth() + 1);
      strip.innerHTML = '';
      var d = new Date(sel.getFullYear(), sel.getMonth(), 1);
      if (d < today) d = new Date(today);
      var end = new Date(sel.getFullYear(), sel.getMonth() + 1, 0);
      for (; d <= end; d.setDate(d.getDate() + 1)) {
        var on = ymd(d) === dateInput.value;
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'pa-sp-day' + (on ? ' is-on' : '');
        b.setAttribute('role', 'option');
        b.setAttribute('aria-selected', on ? 'true' : 'false');
        b.setAttribute('data-date', ymd(d));
        var dn = document.createElement('span'); dn.textContent = DAYS[d.getDay()];
        var dd = document.createElement('strong'); dd.textContent = String(d.getDate());
        b.appendChild(dn); b.appendChild(dd);
        strip.appendChild(b);
      }
      var cur = strip.querySelector('.is-on');
      if (cur) strip.scrollLeft = Math.max(0, cur.offsetLeft - strip.offsetLeft - 60);
    }

    function note(text) {
      sessionsEl.innerHTML = '';
      var p = document.createElement('p');
      p.className = 'pa-sp-note';
      p.textContent = text;
      sessionsEl.appendChild(p);
    }

    function load() {
      var mine = ++seq;
      setTime('');
      note('Loading…');
      fetch(url + '?clinic_id=' + encodeURIComponent(clinic.value) + '&date=' + encodeURIComponent(dateInput.value), { headers: { Accept: 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : Promise.reject(r); })
        .then(function (data) {
          if (mine !== seq) return;
          if (data.time_off) { note('Not available on this day (' + data.time_off + '). Please choose another date.'); return; }
          var sessions = data.sessions || [];
          if (!sessions.length && data.slots && data.slots.length) {
            sessions = [{ label: 'Session 1', start: data.slots[0], end: data.slots[data.slots.length - 1], slots: data.slots.map(function (t) { return { time: t, available: true }; }) }];
          }
          if (!sessions.length) { note('No sessions on this day — try another date.'); return; }
          sessionsEl.innerHTML = '';
          var any = false;
          sessions.forEach(function (s) {
            var box = document.createElement('div');
            box.className = 'pa-sp-session';
            var h = document.createElement('div');
            h.className = 'pa-sp-session-head';
            var ic = document.createElement('span'); ic.className = 'pa-sp-icon'; ic.setAttribute('aria-hidden', 'true'); ic.textContent = icon(s.start);
            var lb = document.createElement('strong'); lb.textContent = s.label;
            var rg = document.createElement('span'); rg.className = 'pa-sp-range'; rg.textContent = t12(s.start) + ' – ' + t12(s.end);
            h.appendChild(ic); h.appendChild(lb); h.appendChild(rg);
            box.appendChild(h);
            var grid = document.createElement('div');
            grid.className = 'pa-sp-grid';
            s.slots.forEach(function (c) {
              var b = document.createElement('button');
              b.type = 'button';
              b.className = 'pa-sp-slot' + (c.booked ? ' is-booked' : (!c.available ? ' is-past' : ''));
              b.textContent = t12(c.time);
              b.disabled = !c.available;
              if (!c.available) b.setAttribute('aria-label', t12(c.time) + (c.booked ? ', booked' : ', unavailable'));
              else { any = true; b.setAttribute('data-time', c.time); }
              grid.appendChild(b);
            });
            box.appendChild(grid);
            sessionsEl.appendChild(box);
          });
          if (!any) {
            var p = document.createElement('p');
            p.className = 'pa-sp-note';
            p.textContent = 'Fully booked on this day — try another date.';
            sessionsEl.appendChild(p);
          }
        })
        .catch(function () { if (mine === seq) note('Could not load times. Please try again.'); });
    }

    function pick(dateStr) {
      dateInput.value = dateStr;
      renderStrip();
      load();
    }

    strip.addEventListener('click', function (e) {
      var b = e.target.closest('.pa-sp-day');
      if (b) pick(b.getAttribute('data-date'));
    });
    sessionsEl.addEventListener('click', function (e) {
      var b = e.target.closest('.pa-sp-slot');
      if (!b || b.disabled) return;
      [].forEach.call(sessionsEl.querySelectorAll('.pa-sp-slot.is-on'), function (x) { x.classList.remove('is-on'); });
      b.classList.add('is-on');
      setTime(b.getAttribute('data-time'));
    });
    month.addEventListener('change', function () {
      var p = month.value.split('-');
      var first = new Date(+p[0], +p[1] - 1, 1);
      pick(ymd(first < today ? today : first));
    });
    el.querySelector('.pa-sp-today').addEventListener('click', function () { pick(ymd(today)); });
    clinic.addEventListener('change', load);

    if (!dateInput.value || parse(dateInput.value) < today) dateInput.value = ymd(today);
    renderStrip();
    load();
  }

  function init(scope) {
    [].forEach.call((scope || document).querySelectorAll('[data-pa-slot-picker]'), attach);
  }

  window.paSlotPicker = init;
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { init(); });
  else init();
})();
