/* Prompt Aid — live alerts for signed-in users (website + staff panel).
 *
 * Polls a JSON endpoint and, when the unread count goes up:
 *   • plays a two-tone chime (Web Audio — browsers only allow sound after
 *     the user has clicked/tapped the page once, so audio is unlocked on
 *     the first interaction),
 *   • shows a desktop notification if the user allowed it,
 *   • updates the bell badge.
 *
 * Web: <a data-pa-alerts="/account/notifications/poll" data-pa-alerts-count="N">
 * Staff panel: window.PaAlerts.start({ url, onCount }) from notification-sound.blade.php
 */
(function () {
  'use strict';
  if (window.PaAlerts) return;

  var ctx = null;
  function unlock() {
    try {
      ctx = ctx || new (window.AudioContext || window.webkitAudioContext)();
      if (ctx.state === 'suspended') ctx.resume();
    } catch (e) {}
  }
  ['click', 'keydown', 'touchstart'].forEach(function (ev) {
    document.addEventListener(ev, unlock, { once: true, passive: true });
  });

  function tone(freq, start, dur) {
    var osc = ctx.createOscillator();
    var gain = ctx.createGain();
    osc.type = 'sine';
    osc.frequency.setValueAtTime(freq, ctx.currentTime + start);
    gain.gain.setValueAtTime(0.0001, ctx.currentTime + start);
    gain.gain.exponentialRampToValueAtTime(0.25, ctx.currentTime + start + 0.02);
    gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + start + dur);
    osc.connect(gain).connect(ctx.destination);
    osc.start(ctx.currentTime + start);
    osc.stop(ctx.currentTime + start + dur + 0.05);
  }
  function chime() {
    try {
      unlock();
      if (!ctx || ctx.state !== 'running') return;
      tone(880, 0, 0.25);
      tone(1320, 0.18, 0.35);
    } catch (e) {}
  }

  function desktop(title, body, url) {
    try {
      if (!('Notification' in window) || Notification.permission !== 'granted') return;
      var n = new Notification(title || 'Prompt Aid', { body: body || '', icon: '/assets/img/favicon.ico', tag: 'pa-' + Date.now() });
      if (url) n.onclick = function () { window.focus(); window.location.href = url; };
    } catch (e) {}
  }

  // Ask once, after a user gesture, for desktop alerts.
  function askPermission() {
    try {
      if ('Notification' in window && Notification.permission === 'default') Notification.requestPermission();
    } catch (e) {}
  }
  document.addEventListener('click', askPermission, { once: true });

  function start(opts) {
    var last = typeof opts.initial === 'number' ? opts.initial : null;
    var KEY = 'pa-alerts-last:' + opts.url;
    try { var saved = parseInt(sessionStorage.getItem(KEY), 10); if (!isNaN(saved)) last = saved; } catch (e) {}

    function poll() {
      if (document.hidden && opts.skipHidden) return;
      fetch(opts.url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) {
          if (!data) return;
          var count = data.count || 0;
          if (last !== null && count > last) {
            chime();
            var l = data.latest || {};
            desktop(l.title || (count - last) + ' new notification' + (count - last > 1 ? 's' : ''), l.body, l.url);
            if (opts.onNew) opts.onNew(count - last, l);
          }
          last = count;
          try { sessionStorage.setItem(KEY, String(count)); } catch (e) {}
          if (opts.onCount) opts.onCount(count);
        })
        .catch(function () {});
    }
    poll();
    return setInterval(poll, opts.interval || 15000);
  }

  window.PaAlerts = { start: start, chime: chime };

  // Website bell.
  var bell = document.querySelector('[data-pa-alerts]');
  if (bell) {
    start({
      url: bell.getAttribute('data-pa-alerts'),
      initial: parseInt(bell.getAttribute('data-pa-alerts-count') || '0', 10),
      onCount: function (count) {
        var badge = bell.querySelector('.pa-bell-count');
        if (count > 0) {
          if (!badge) { badge = document.createElement('span'); badge.className = 'pa-bell-count'; bell.appendChild(badge); }
          badge.textContent = count > 99 ? '99+' : String(count);
        } else if (badge) {
          badge.remove();
        }
      },
      onNew: function (n, latest) {
        if (window.paToast) window.paToast((latest && latest.title) || (n + ' new notification' + (n > 1 ? 's' : '')));
      }
    });
  }
})();
