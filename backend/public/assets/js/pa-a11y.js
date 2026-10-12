/*
 * PromptAid accessibility toolbar.
 *
 * Loaded in <head> (not deferred) so saved preferences are applied to <html>
 * before the page paints. The button + panel markup is rendered by
 * resources/views/components/layout.blade.php when the admin toggle
 * (Staff → System → Features → Accessibility toolbar) is on.
 * Preferences live in localStorage under "pa-a11y".
 */
(function () {
  'use strict';

  var KEY = 'pa-a11y';
  var root = document.documentElement;
  var MAX_TEXT = 3;

  function load() {
    try {
      var raw = window.localStorage.getItem(KEY);
      var p = raw ? JSON.parse(raw) : {};
      return {
        text: Math.max(0, Math.min(MAX_TEXT, parseInt(p.text, 10) || 0)),
        contrast: !!p.contrast,
        links: !!p.links,
        motion: !!p.motion
      };
    } catch (e) {
      return { text: 0, contrast: false, links: false, motion: false };
    }
  }

  function save(p) {
    try { window.localStorage.setItem(KEY, JSON.stringify(p)); } catch (e) { /* private mode */ }
  }

  function apply(p) {
    for (var i = 1; i <= MAX_TEXT; i++) root.classList.toggle('pa-a11y-text-' + i, p.text === i);
    root.classList.toggle('pa-a11y-contrast', p.contrast);
    root.classList.toggle('pa-a11y-links', p.links);
    root.classList.toggle('pa-a11y-motion', p.motion);
  }

  var prefs = load();
  apply(prefs);

  function init() {
    var wrap = document.querySelector('[data-pa-a11y]');
    if (!wrap) return;
    var btn = wrap.querySelector('.pa-a11y-btn');
    var panel = wrap.querySelector('.pa-a11y-panel');
    if (!btn || !panel) return;

    function sync() {
      panel.querySelectorAll('[data-pa-a11y-text]').forEach(function (b) {
        b.setAttribute('aria-pressed', String(parseInt(b.getAttribute('data-pa-a11y-text'), 10) === prefs.text));
      });
      panel.querySelectorAll('[data-pa-a11y-toggle]').forEach(function (b) {
        var on = !!prefs[b.getAttribute('data-pa-a11y-toggle')];
        b.setAttribute('aria-pressed', String(on));
        b.textContent = on ? 'On' : 'Off';
      });
    }

    function open(show) {
      panel.hidden = !show;
      btn.setAttribute('aria-expanded', String(show));
      if (show) {
        var first = panel.querySelector('button');
        if (first) first.focus();
      }
    }

    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      open(panel.hidden);
    });

    panel.addEventListener('click', function (e) {
      e.stopPropagation();
      var t = e.target.closest('button');
      if (!t) return;
      if (t.hasAttribute('data-pa-a11y-text')) {
        prefs.text = parseInt(t.getAttribute('data-pa-a11y-text'), 10) || 0;
      } else if (t.hasAttribute('data-pa-a11y-toggle')) {
        var k = t.getAttribute('data-pa-a11y-toggle');
        prefs[k] = !prefs[k];
      } else if (t.hasAttribute('data-pa-a11y-reset')) {
        prefs = { text: 0, contrast: false, links: false, motion: false };
      } else if (t.hasAttribute('data-pa-a11y-close')) {
        open(false);
        btn.focus();
        return;
      }
      apply(prefs);
      save(prefs);
      sync();
    });

    document.addEventListener('click', function () { if (!panel.hidden) open(false); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !panel.hidden) {
        open(false);
        btn.focus();
      }
    });

    sync();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
