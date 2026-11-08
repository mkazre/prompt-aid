/* PromptAid website dashboards: 15 s live refresh of [data-pa-dash-live] blocks
   (patient "My active requests", driver console stats) + per-second countdowns. */
(function () {
  if (window.__paDash) return;
  window.__paDash = true;

  function fmt(sec) {
    var a = Math.abs(sec);
    if (a < 60) return a + ' s';
    var m = Math.floor(a / 60);
    if (m < 60) return m + ' min';
    var h = Math.floor(m / 60);
    if (h < 48) return h + ' h' + (m % 60 ? ' ' + (m % 60) + ' min' : '');
    return Math.floor(h / 24) + ' days';
  }

  function tick() {
    var els = document.querySelectorAll('[data-pa-countdown]');
    for (var i = 0; i < els.length; i++) {
      var t = Date.parse(els[i].getAttribute('data-pa-countdown'));
      if (isNaN(t)) continue;
      var s = Math.round((t - Date.now()) / 1000);
      els[i].textContent = s > 0 ? 'in ' + fmt(s) : (s > -60 ? 'now' : 'started ' + fmt(s) + ' ago');
    }
  }

  function refresh(el) {
    if (document.hidden || el.__busy) return;
    el.__busy = true;
    fetch(el.getAttribute('data-pa-dash-live'), { credentials: 'same-origin', headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.ok ? r.text() : null; })
      .then(function (html) { if (html !== null) { el.innerHTML = html; tick(); } })
      .catch(function () {})
      .then(function () { el.__busy = false; });
  }

  function start() {
    var blocks = document.querySelectorAll('[data-pa-dash-live]');
    for (var i = 0; i < blocks.length; i++) {
      (function (el) {
        var every = Math.max(10, parseInt(el.getAttribute('data-pa-dash-every') || '15', 10)) * 1000;
        setInterval(function () { refresh(el); }, every);
      })(blocks[i]);
    }
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) for (var j = 0; j < blocks.length; j++) refresh(blocks[j]);
    });
    tick();
    setInterval(tick, 1000);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
