/*
 * Consult hub (website patient page + doctor staff page).
 * Config: <script type="application/json" data-hub-config> rendered by
 * ConsultHubController::webConfig. Channels in order: video (default),
 * in-app voice (Jitsi audio-only), chat; phone / WhatsApp as fallback.
 * The call itself is pa-video-call.js (window.paCall); this file:
 *  - polls the hub state (status line, consult timer, ring, presence),
 *  - starts / switches channels (doctor: rings the patient with the channel),
 *  - sends presence heartbeats while this side is inside the call
 *    (both sides in = connected → server fires ConsultStarted once),
 *  - runs the embedded chat (4 s poll, photo / PDF attachments),
 *  - patient fallback (ask for a phone / WhatsApp call), doctor fallback
 *    (Call / WhatsApp / patient answered) and End consult.
 */
(function () {
  'use strict';

  function fmt(sec) {
    sec = Math.max(0, Math.floor(sec));
    var h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60;
    var mm = (m < 10 ? '0' : '') + m, ss = (s < 10 ? '0' : '') + s;
    return h > 0 ? h + ':' + mm + ':' + ss : mm + ':' + ss;
  }
  function clock(iso) {
    var d = new Date(iso);
    return isNaN(d) ? '' : d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  }

  function init(root) {
    var cfgEl = root.querySelector('[data-hub-config]');
    var C;
    try { C = JSON.parse(cfgEl ? cfgEl.textContent : '{}'); } catch (e) { return; }
    var isDoctor = C.role === 'doctor';
    var state = C.initial || {};
    var $ = function (sel) { return root.querySelector(sel); };
    var $$ = function (sel) { return Array.prototype.slice.call(root.querySelectorAll(sel)); };

    var statusEl = $('[data-hub-status]'), timerEl = $('[data-hub-timer]'), noteEl = $('[data-hub-note]');
    var callRoot = root.querySelector('[data-pa-call]');
    var inCall = false, callChannel = null, beatTimer = null, stateTimer = null, ringHandled = {}, ringTimer = null;
    var skew = 0, connectedAt = NaN;

    function req(method, url, body, isForm) {
      var headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': C.csrf || '' };
      var opts = { method: method, credentials: 'same-origin', headers: headers };
      if (body && isForm) opts.body = body;
      else if (body) { headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
      return fetch(url, opts).then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (j) {
          if (!r.ok) { var err = new Error((j && j.message) || 'Something went wrong. Please try again.'); err.status = r.status; throw err; }
          return j;
        });
      });
    }

    function note(text) {
      if (!noteEl) return;
      noteEl.textContent = text || '';
      noteEl.hidden = !text;
    }

    // ------------------------------------------------------------ state
    function render(s) {
      if (!s) return;
      state = s;
      if (statusEl) statusEl.textContent = s.status_text || '';
      root.setAttribute('data-open', s.is_open ? '1' : '0');
      root.setAttribute('data-live', s.consult && s.consult.live_call ? '1' : '0');
      if (s.server_time) skew = Date.parse(s.server_time) - Date.now();
      connectedAt = s.consult && s.consult.connected_at ? Date.parse(s.consult.connected_at) : NaN;
      if (window.paCall && window.paCall.setTimerBase && s.consult && s.consult.connected_at) window.paCall.setTimerBase(s.consult.connected_at, s.server_time);

      var ch = s.channels || {};
      $$('[data-hub-ch]').forEach(function (b) {
        var k = b.getAttribute('data-hub-ch');
        var avail = ch[k] && ch[k].available;
        b.disabled = !s.is_open || !avail;
        b.classList.toggle('is-current', inCall && k === callChannel);
      });
      if (!s.is_open) {
        note(s.consult && s.consult.outcome_label ? 'This consult has ended: ' + s.consult.outcome_label + '.' : 'This consult has ended.');
        if (window.paCall && window.paCall.isActive && window.paCall.isActive()) window.paCall.hangup();
        $$('[data-hub-end] button, [data-hub-callback], [data-hub-connected], [data-hub-compose] button').forEach(function (b) { b.disabled = true; });
        stopRing();
      } else if (ch.video && !ch.video.available && ch.video.reason && !isDoctor) {
        note(ch.video.reason);
      } else {
        note('');
      }

      // A call room that opened after the page loaded (doctor turned a call back into a call): reload to get the room config.
      if (!C.callReady && s.is_open && ch.video && ch.video.available && !isDoctor && s.ring) {
        window.location.href = s.ring.hub_url || window.location.pathname + '?join=' + (s.ring.channel || 'video');
        return;
      }
      if (!isDoctor) handleRing(s.ring);
    }

    function tickTimer() {
      if (!timerEl) return;
      if (state.consult && state.consult.state === 'ended') { timerEl.textContent = fmt(state.consult.connected_seconds || 0); return; }
      timerEl.textContent = isNaN(connectedAt) ? '--:--' : fmt((Date.now() + skew - connectedAt) / 1000);
    }

    function poll() {
      clearTimeout(stateTimer);
      if (document.hidden && !inCall) { stateTimer = setTimeout(poll, 15000); return; }
      req('GET', C.stateUrl).then(function (j) { render(j.data); }).catch(function () {})
        .then(function () { if (state.is_open !== false) stateTimer = setTimeout(poll, 5000); });
    }

    // ------------------------------------------------------------ ring (patient)
    var ringBox = $('[data-hub-ring]');
    function stopRing() { clearInterval(ringTimer); ringTimer = null; if (ringBox) ringBox.hidden = true; }
    function handleRing(r) {
      if (!ringBox) return;
      if (!r) { stopRing(); return; }
      var key = r.appointment_id + '@' + r.started_at;
      if (ringHandled[key] || ringTimer) return;
      if (inCall && callChannel === (r.channel || 'video')) { ringHandled[key] = 1; answer(); return; }
      if (inCall) { ringHandled[key] = 1; answer(); window.paCall.setChannel(r.channel || 'video'); return; }
      var secs = Math.max(0, Number(r.auto_join_seconds || 0));
      $('[data-hub-ring-title]').textContent = (r.doctor_name || 'Your doctor') + ' is calling you (' + (r.channel === 'voice' ? 'voice' : 'video') + ')';
      var line = $('[data-hub-ring-line]');
      ringBox.hidden = false;
      function upd() { line.textContent = secs > 0 ? 'Joining automatically in ' + secs + 's…' : 'Joining…'; }
      upd();
      ringTimer = setInterval(function () {
        secs -= 1; upd();
        if (secs <= 0) { join(r.channel || 'video'); }
      }, 1000);
      ringBox.__ring = r;
      function join(chan) { ringHandled[key] = 1; stopRing(); answer(); startCall(chan); }
      $('[data-hub-ring-join]').onclick = function () { join(r.channel || 'video'); };
      $('[data-hub-ring-decline]').onclick = function () {
        ringHandled[key] = 1; stopRing();
        req('POST', C.declineUrl).catch(function () {});
      };
    }
    function answer() { req('POST', C.answerUrl).catch(function () {}); }

    // ------------------------------------------------------------ calls
    function presence(stateIn) {
      if (!callChannel) return;
      req('POST', C.presenceUrl, { channel: callChannel, state: stateIn ? 'in' : 'out' })
        .then(function (j) { render(j.data); }).catch(function () {});
    }
    function beat() {
      clearInterval(beatTimer);
      beatTimer = setInterval(function () { if (inCall) presence(true); }, 15000);
    }

    function startCall(chan) {
      if (!window.paCall || !callRoot) {
        if (C.meetUrl) { window.open(C.meetUrl, '_blank', 'noopener'); return; }
        // No room config on this page yet (e.g. doctor converting a call back): reload into the channel.
        window.location.href = window.location.pathname + '?join=' + chan;
        return;
      }
      callChannel = chan;
      if (callRoot.scrollIntoView) callRoot.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      window.paCall.start(chan);
      if (inCall) presence(true);
      render(state);
    }

    if (callRoot) {
      callRoot.addEventListener('pacall', function (e) {
        var m = e.detail || {};
        if (m.type === 'joined') { inCall = true; callChannel = window.paCall.channel(); presence(true); beat(); render(state); }
        else if (m.type === 'channel') { callChannel = m.channel; if (inCall) presence(true); render(state); }
        else if (m.type === 'ended' || m.type === 'error') {
          if (inCall) presence(false);
          inCall = false; clearInterval(beatTimer); render(state);
        }
      });
    }
    window.addEventListener('pagehide', function () {
      if (inCall && callChannel && navigator.sendBeacon) {
        var fd = new FormData();
        fd.append('channel', callChannel); fd.append('state', 'out'); fd.append('_token', C.csrf || '');
        try { navigator.sendBeacon(C.presenceUrl, fd); } catch (e) { /* ignore */ }
      }
    });

    $$('[data-hub-ch]').forEach(function (b) {
      b.addEventListener('click', function () {
        var chan = b.getAttribute('data-hub-ch');
        if (chan === 'chat') { focusChat(); return; }
        if (isDoctor) {
          b.disabled = true;
          req('POST', C.startUrl, { channel: chan })
            .then(function (j) { render(j.data); startCall(chan); })
            .catch(function (err) { note(err.message); })
            .then(function () { b.disabled = false; });
        } else {
          startCall(chan);
        }
      });
    });

    // ------------------------------------------------------------ fallback
    $$('[data-hub-calling]').forEach(function (a) {
      a.addEventListener('click', function () {
        req('POST', C.callingUrl, { via: a.getAttribute('data-hub-calling') }).then(function (j) { render(j.data); }).catch(function (err) { note(err.message); });
      });
    });
    $$('[data-hub-connected]').forEach(function (b) {
      b.addEventListener('click', function () {
        b.disabled = true;
        req('POST', C.connectedUrl, { via: b.getAttribute('data-hub-connected') })
          .then(function (j) { render(j.data); note('Recorded: the consult is connected by ' + (b.getAttribute('data-hub-connected') === 'whatsapp' ? 'WhatsApp' : 'phone') + '.'); })
          .catch(function (err) { note(err.message); })
          .then(function () { b.disabled = false; });
      });
    });
    $$('[data-hub-callback]').forEach(function (b) {
      b.addEventListener('click', function () {
        var ph = $('[data-hub-cb-phone]');
        b.disabled = true;
        req('POST', C.callbackUrl, { via: b.getAttribute('data-hub-callback'), phone: ph ? ph.value.trim() : '' })
          .then(function (j) { render(j.data); note('Your doctor has been asked to ' + (b.getAttribute('data-hub-callback') === 'whatsapp' ? 'WhatsApp-call' : 'phone') + ' you. Keep your phone close.'); })
          .catch(function (err) { note(err.message); })
          .then(function () { b.disabled = false; });
      });
    });

    var endForm = $('[data-hub-end]');
    if (endForm) {
      endForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var outcome = endForm.elements.outcome.value;
        if (!window.confirm('End this consult now? This closes it for you and the patient.')) return;
        var btn = endForm.querySelector('button');
        btn.disabled = true;
        req('POST', C.endUrl, { outcome: outcome, notes: endForm.elements.notes.value })
          .then(function (j) { if (inCall && window.paCall) window.paCall.hangup(); render(j.data); })
          .catch(function (err) { note(err.message); btn.disabled = false; });
      });
    }

    // ------------------------------------------------------------ chat
    var msgsEl = $('[data-hub-msgs]'), emptyEl = $('[data-hub-msgs-empty]'), form = $('[data-hub-compose]');
    var textEl = $('[data-hub-text]'), fileEl = $('[data-hub-file]'), chip = $('[data-hub-filechip]');
    var lastId = 0, seen = {}, chatTimer = null;

    function focusChat() {
      var tab = $('[data-hub-tab="chat"]');
      if (tab) tab.click();
      if (form && form.scrollIntoView) form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      if (textEl) textEl.focus();
    }

    function bubble(m) {
      var el = document.createElement('div');
      el.className = 'pa-hub__msg' + (m.is_mine ? ' is-mine' : '');
      if (m.body) el.appendChild(document.createTextNode(m.body));
      if (m.attachment && m.attachment.url) {
        var a = document.createElement('a');
        a.href = m.attachment.url; a.target = '_blank'; a.rel = 'noopener';
        if (m.attachment.is_image) {
          var img = document.createElement('img'); img.src = m.attachment.url; img.alt = m.attachment.name || 'Photo'; img.loading = 'lazy';
          a.appendChild(img);
        } else {
          a.textContent = '📎 ' + (m.attachment.name || 'Attachment');
        }
        el.appendChild(a);
      }
      var t = document.createElement('time');
      t.textContent = (m.is_mine ? 'You' : (m.sender_name || '')) + ' · ' + clock(m.created_at);
      el.appendChild(t);
      return el;
    }

    function add(list) {
      var atBottom = msgsEl.scrollHeight - msgsEl.scrollTop - msgsEl.clientHeight < 60;
      list.forEach(function (m) {
        if (seen[m.id]) return;
        seen[m.id] = 1;
        lastId = Math.max(lastId, m.id);
        msgsEl.appendChild(bubble(m));
      });
      if (emptyEl) emptyEl.hidden = msgsEl.querySelectorAll('.pa-hub__msg').length > 0;
      if (atBottom || list.length) msgsEl.scrollTop = msgsEl.scrollHeight;
    }

    function pollChat() {
      clearTimeout(chatTimer);
      if (!msgsEl || !(state.channels && state.channels.chat && state.channels.chat.available)) { chatTimer = setTimeout(pollChat, 8000); return; }
      if (document.hidden) { chatTimer = setTimeout(pollChat, 15000); return; }
      req('GET', C.chatUrl + (lastId ? '?after=' + lastId : '')).then(function (j) { add(j.data || []); }).catch(function () {})
        .then(function () { chatTimer = setTimeout(pollChat, 4000); });
    }

    if (fileEl) {
      fileEl.addEventListener('change', function () {
        var f = fileEl.files && fileEl.files[0];
        if (f && f.size > (C.maxUploadKb || 10240) * 1024) { fileEl.value = ''; chip.hidden = false; chip.textContent = 'That file is too big (max ' + Math.round((C.maxUploadKb || 10240) / 1024) + ' MB).'; return; }
        chip.hidden = !f; chip.textContent = f ? 'Attached: ' + f.name : '';
      });
    }
    if (textEl) {
      textEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit')); }
      });
    }
    if (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var body = textEl.value.trim(), f = fileEl && fileEl.files && fileEl.files[0];
        if (!body && !f) return;
        var fd = new FormData();
        if (body) fd.append('body', body);
        if (f) fd.append('attachment', f);
        var btn = form.querySelector('button[type="submit"]');
        btn.disabled = true;
        req('POST', C.chatUrl, fd, true)
          .then(function (j) {
            textEl.value = ''; if (fileEl) fileEl.value = ''; if (chip) { chip.hidden = true; chip.textContent = ''; }
            if (j.data) add([j.data]);
          })
          .catch(function (err) { if (chip) { chip.hidden = false; chip.textContent = err.message; } })
          .then(function () { btn.disabled = false; textEl.focus(); });
      });
    }

    // Doctor tabs (chat / patient summary).
    $$('[data-hub-tab]').forEach(function (t) {
      t.addEventListener('click', function () {
        var which = t.getAttribute('data-hub-tab');
        $$('[data-hub-tab]').forEach(function (x) { var on = x === t; x.classList.toggle('is-active', on); x.setAttribute('aria-selected', on ? 'true' : 'false'); });
        $$('[data-hub-panel]').forEach(function (p) { p.hidden = p.getAttribute('data-hub-panel') !== which; });
      });
    });

    // ------------------------------------------------------------ boot
    render(state);
    setInterval(tickTimer, 1000); tickTimer();
    setTimeout(poll, 4000);
    pollChat();
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { poll(); pollChat(); } });
    // Arrived via "Dr X is calling you" (or ?join=): join straight away in that channel.
    if (C.autoJoin && C.callReady) {
      var chan = C.join || (state.ring && state.ring.channel) || 'video';
      if (state.ring) ringHandled[state.ring.appointment_id + '@' + state.ring.started_at] = 1;
      answer();
      setTimeout(function () { startCall(chan); }, 400);
    } else if (isDoctor && C.join && C.callReady) {
      setTimeout(function () { startCall(C.join); }, 400);
    }
  }

  function boot() {
    var roots = document.querySelectorAll('[data-pa-hub]');
    for (var i = 0; i < roots.length; i++) {
      if (!roots[i].__paHub) { roots[i].__paHub = true; init(roots[i]); }
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
