/*
 * Prompt Aid — trip audio recorder (website + web driver console).
 *
 * Mount:  <div data-pa-trip-recorder data-ride-id="123" data-role="patient|driver"
 *              [data-base="/rides/123/recordings"] [data-show-list="1"]></div>
 *         <script src="/assets/js/trip-recorder.js"></script>
 * Elements added later (e.g. by the driver console) are picked up
 * automatically (MutationObserver), or call window.PaTripRecorder.mount(el).
 *
 * Recording state lives per ride, not per element, so re-rendering the
 * container does not stop an active recording.
 *
 * Uses MediaRecorder with a timeslice (default 30 s): each piece is uploaded
 * as soon as it is produced (retried with back-off while offline) so audio
 * already captured survives if the phone is taken. Pieces of one recording
 * continue one file (webm/opus; audio/mp4 on Safari); the server stores them
 * encrypted and plays them back joined.
 *
 * Limits (honest): a browser may pause recording when the screen locks or the
 * tab is backgrounded (iOS Safari always does). Pieces not yet uploaded are
 * lost if the tab is closed. The app recorder is more robust.
 */
(function () {
  'use strict';
  if (window.PaTripRecorder) return;

  var CONSENT_KEY = 'paTripRecConsent.v1';
  var POLL_MS = 15000;
  var sessions = {}; // rideId -> session

  function csrf() {
    var m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.getAttribute('content') : '';
  }

  function fmt(sec) {
    sec = Math.max(0, Math.floor(sec));
    var h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60;
    return (h ? h + ':' + String(m).padStart(2, '0') : m) + ':' + String(s).padStart(2, '0');
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function storageGet(k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } }
  function storageSet(k, v) { try { window.localStorage.setItem(k, v); } catch (e) { /* ignore */ } }

  function injectCss() {
    if (document.getElementById('pa-tr-css')) return;
    var css = '' +
      '.pa-tr{font-family:inherit;margin:12px 0}' +
      '.pa-tr-banner{display:flex;gap:10px;align-items:center;padding:10px 12px;border-radius:10px;background:#FBEDEC;color:#9E1813;font-weight:700;font-size:14px;margin-bottom:10px}' +
      '.pa-tr-dot{width:10px;height:10px;border-radius:50%;background:#D0211C;flex:0 0 auto;animation:pa-tr-pulse 1.2s infinite}' +
      '@keyframes pa-tr-pulse{0%{box-shadow:0 0 0 0 rgba(208,33,28,.55)}70%{box-shadow:0 0 0 10px rgba(208,33,28,0)}100%{box-shadow:0 0 0 0 rgba(208,33,28,0)}}' +
      '.pa-tr-card{border:1px solid #E0DACB;border-radius:12px;background:#fff;padding:12px 14px}' +
      '.pa-tr-row{display:flex;align-items:center;gap:12px}' +
      '.pa-tr-btn{width:52px;height:52px;border-radius:50%;border:2px solid #D0211C;background:#fff;color:#D0211C;display:flex;align-items:center;justify-content:center;cursor:pointer;flex:0 0 auto;padding:0}' +
      '.pa-tr-btn:disabled{opacity:.45;cursor:not-allowed}' +
      '.pa-tr-btn.is-rec{background:#D0211C;color:#fff;animation:pa-tr-pulse 1.2s infinite}' +
      '.pa-tr-btn svg{width:24px;height:24px}' +
      '.pa-tr-title{font-weight:800;font-size:15px;color:#101012}' +
      '.pa-tr-sub{font-size:13px;color:#6E6A62;margin-top:2px}' +
      '.pa-tr-timer{font-variant-numeric:tabular-nums;font-weight:800;color:#D0211C}' +
      '.pa-tr-err{font-size:13px;color:#9E1813;margin-top:8px}' +
      '.pa-tr-list{margin-top:12px;display:flex;flex-direction:column;gap:10px}' +
      '.pa-tr-item{border-top:1px solid #F0EBE0;padding-top:10px}' +
      '.pa-tr-item audio{width:100%;margin-top:6px}' +
      '.pa-tr-meta{font-size:13px;color:#3A3A3E}' +
      '.pa-tr-meta a{color:#1F4E7A}' +
      '.pa-tr-modal{position:fixed;inset:0;background:rgba(16,16,18,.55);display:flex;align-items:center;justify-content:center;z-index:9999;padding:16px}' +
      '.pa-tr-modal .box{background:#fff;border-radius:14px;max-width:440px;width:100%;padding:20px;color:#101012}' +
      '.pa-tr-modal h3{margin:0 0 8px;font-size:18px}' +
      '.pa-tr-modal ul{padding-left:18px;margin:8px 0 14px;font-size:14px;line-height:1.45}' +
      '.pa-tr-modal .acts{display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap}' +
      '.pa-tr-modal button{border-radius:999px;padding:10px 16px;font-weight:700;cursor:pointer;border:1px solid #101012;background:#fff}' +
      '.pa-tr-modal button.go{background:#D0211C;border-color:#D0211C;color:#fff}';
    var st = document.createElement('style');
    st.id = 'pa-tr-css';
    st.textContent = css;
    document.head.appendChild(st);
  }

  var MIC_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="2" width="6" height="12" rx="3"/><path d="M5 10v1a7 7 0 0 0 14 0v-1"/><line x1="12" y1="18" x2="12" y2="22"/></svg>';
  var STOP_SVG = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="6" y="6" width="12" height="12" rx="2"/></svg>';

  function pickMime() {
    if (!window.MediaRecorder || !MediaRecorder.isTypeSupported) return '';
    var c = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4'];
    for (var i = 0; i < c.length; i++) if (MediaRecorder.isTypeSupported(c[i])) return c[i];
    return '';
  }

  function sha256Hex(blob) {
    if (!window.crypto || !crypto.subtle || !blob.arrayBuffer) return Promise.resolve(null);
    return blob.arrayBuffer().then(function (buf) { return crypto.subtle.digest('SHA-256', buf); }).then(function (h) {
      return Array.prototype.map.call(new Uint8Array(h), function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
    }).catch(function () { return null; });
  }

  function request(method, url, body) {
    var opts = { method: method, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf() } };
    if (body instanceof FormData) opts.body = body;
    else if (body) { opts.body = JSON.stringify(body); opts.headers['Content-Type'] = 'application/json'; }
    return fetch(url, opts).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok) { var e = new Error(j.message || ('Request failed (' + r.status + ')')); e.status = r.status; throw e; }
        return j;
      });
    });
  }

  // ------------------------------------------------------------------
  // Session (one per ride)
  // ------------------------------------------------------------------

  function Session(rideId, role, base) {
    this.rideId = rideId;
    this.role = role;
    this.base = base;
    this.views = [];
    this.summary = null;
    this.rec = null; // {id, recorder, stream, startedAt, seq, lastAt, queue, uploading, stopping, maxMs}
    this.error = '';
    this.pollTimer = null;
    this.tickTimer = null;
    this.listSig = '';
  }

  Session.prototype.attach = function (el) {
    if (this.views.indexOf(el) === -1) this.views.push(el);
    this.render(true);
    if (!this.pollTimer) this.poll();
  };

  Session.prototype.liveViews = function () {
    this.views = this.views.filter(function (el) { return document.body.contains(el); });
    return this.views;
  };

  Session.prototype.poll = function () {
    var self = this;
    clearTimeout(this.pollTimer);
    request('GET', this.base).then(function (s) {
      self.summary = s;
      // Ride over → stop automatically.
      if (self.rec && !self.rec.stopping && (s.ride_status === 'completed' || s.ride_status === 'cancelled')) {
        self.stop('ride_ended');
      }
      self.render(false);
    }).catch(function (e) {
      if (e.status === 403 || e.status === 404) { self.summary = null; self.render(false); return; }
    }).then(function () {
      if (self.liveViews().length || self.rec) self.pollTimer = setTimeout(function () { self.poll(); }, POLL_MS);
      else self.pollTimer = null;
    });
  };

  Session.prototype.askConsent = function () {
    if (storageGet(CONSENT_KEY) === '1') return Promise.resolve(true);
    return new Promise(function (resolve) {
      var other = 'the other person on this trip';
      var wrap = document.createElement('div');
      wrap.className = 'pa-tr-modal';
      wrap.setAttribute('role', 'dialog');
      wrap.setAttribute('aria-modal', 'true');
      wrap.innerHTML = '<div class="box"><h3>Record trip audio for safety</h3>' +
        '<ul>' +
        '<li>Audio from your microphone is recorded and uploaded every few seconds, so it is kept even if your phone is lost or taken.</li>' +
        '<li>' + esc(other.charAt(0).toUpperCase() + other.slice(1)) + ' is told on screen that the trip is being recorded.</li>' +
        '<li>The patient, the driver and authorised Prompt Aid safety staff can play it. Every play is logged.</li>' +
        '<li>Recordings are encrypted and deleted automatically after a few days, unless needed for an incident or complaint.</li>' +
        '<li>Only record for safety. Keep the screen on — browsers may pause recording when the phone locks.</li>' +
        '</ul><div class="acts"><button type="button" data-x>Not now</button><button type="button" class="go" data-ok>I understand, record</button></div></div>';
      document.body.appendChild(wrap);
      function done(v) { wrap.remove(); if (v) storageSet(CONSENT_KEY, '1'); resolve(v); }
      wrap.querySelector('[data-ok]').addEventListener('click', function () { done(true); });
      wrap.querySelector('[data-x]').addEventListener('click', function () { done(false); });
      wrap.addEventListener('click', function (ev) { if (ev.target === wrap) done(false); });
    });
  };

  Session.prototype.start = function () {
    var self = this;
    if (this.rec) return;
    this.error = '';
    var mime = pickMime();
    if (!mime || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      this.error = 'This browser cannot record audio. Use the Prompt Aid app or a recent Chrome, Edge, Firefox or Safari.';
      this.render(false);
      return;
    }
    this.askConsent().then(function (ok) {
      if (!ok) return null;
      return navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true } }).catch(function (e) {
        throw new Error(e && e.name === 'NotAllowedError'
          ? 'Microphone access was blocked. Allow the microphone for this site in your browser settings, then try again.'
          : 'Could not open the microphone (' + (e && e.message ? e.message : 'unknown error') + ').');
      });
    }).then(function (stream) {
      if (!stream) return;
      return request('POST', self.base, { mime: mime, layout: 'stream', consent: true }).then(function (res) {
        self.beginRecording(res, stream, mime);
      }).catch(function (e) {
        stream.getTracks().forEach(function (t) { t.stop(); });
        throw e;
      });
    }).catch(function (e) {
      self.error = e.message || 'Could not start recording.';
      self.render(false);
    });
  };

  Session.prototype.beginRecording = function (res, stream, mime) {
    var self = this;
    var chunkMs = Math.max(10, res.chunk_seconds || 30) * 1000;
    var recorder;
    try {
      recorder = new MediaRecorder(stream, { mimeType: mime, audioBitsPerSecond: 32000 });
    } catch (e) {
      recorder = new MediaRecorder(stream);
    }
    var now = Date.now();
    this.rec = {
      id: res.recording.id, recorder: recorder, stream: stream, startedAt: now, lastAt: now, seq: 0,
      queue: [], uploading: false, stopping: false, failed: 0, maxMs: (res.max_minutes || 120) * 60000,
    };
    var rec = this.rec;
    recorder.ondataavailable = function (ev) {
      if (!ev.data || !ev.data.size) return;
      var t = Date.now();
      rec.queue.push({ seq: rec.seq++, blob: ev.data, durationMs: t - rec.lastAt });
      rec.lastAt = t;
      self.pump();
    };
    recorder.onstop = function () {
      stream.getTracks().forEach(function (tr) { tr.stop(); });
      rec.recorderStopped = true;
      self.pump();
    };
    recorder.onerror = function () { self.error = 'The recorder stopped unexpectedly.'; self.stop('recorder_error'); };
    // Mic unplugged / permission revoked.
    stream.getAudioTracks().forEach(function (tr) { tr.addEventListener('ended', function () { self.stop('mic_lost'); }); });
    recorder.start(chunkMs);
    this.tickTimer = setInterval(function () {
      if (Date.now() - rec.startedAt >= rec.maxMs) self.stop('max_length');
      self.renderTimer();
    }, 1000);
    window.addEventListener('beforeunload', this._unload = function (ev) {
      if (self.rec && (self.rec.queue.length || !self.rec.stopping)) { ev.preventDefault(); ev.returnValue = ''; }
    });
    this.render(false);
    this.poll();
  };

  Session.prototype.pump = function () {
    var self = this, rec = this.rec;
    if (!rec || rec.uploading) return;
    if (!rec.queue.length) {
      if (rec.stopping && rec.recorderStopped && !rec.completed) {
        rec.completed = true;
        request('POST', this.base + '/' + rec.id + '/complete', { reason: rec.reason || 'stopped' }).catch(function () { /* server finalises stale ones */ })
          .then(function () { self.finish(); });
      }
      return;
    }
    rec.uploading = true;
    var item = rec.queue[0];
    sha256Hex(item.blob).then(function (hash) {
      var fd = new FormData();
      var ext = /mp4/.test(item.blob.type) ? 'm4a' : (/ogg/.test(item.blob.type) ? 'ogg' : 'webm');
      fd.append('chunk', item.blob, 'piece-' + item.seq + '.' + ext);
      fd.append('seq', String(item.seq));
      fd.append('duration_ms', String(item.durationMs));
      if (hash) fd.append('sha256', hash);
      return request('POST', self.base + '/' + rec.id + '/chunks', fd);
    }).then(function (j) {
      rec.queue.shift();
      rec.failed = 0;
      rec.uploading = false;
      if (j && j.limit_reached) self.stop('max_length');
      self.renderTimer();
      self.pump();
    }).catch(function (e) {
      rec.uploading = false;
      if (e.status && e.status >= 400 && e.status < 500 && e.status !== 408 && e.status !== 429 && e.status !== 419) {
        // Permanent rejection of this piece: drop it and say so.
        rec.queue.shift();
        self.error = e.message;
        if (e.status === 410 || /Maximum/.test(e.message)) self.stop('rejected');
        self.render(false);
        self.pump();
        return;
      }
      rec.failed++;
      self.renderTimer();
      setTimeout(function () { self.pump(); }, Math.min(30000, 2000 * Math.pow(2, Math.min(rec.failed, 4))));
    });
  };

  Session.prototype.stop = function (reason) {
    var rec = this.rec;
    if (!rec || rec.stopping) return;
    rec.stopping = true;
    rec.reason = reason || 'stopped';
    clearInterval(this.tickTimer);
    try { if (rec.recorder.state !== 'inactive') rec.recorder.stop(); else { rec.recorderStopped = true; this.pump(); } } catch (e) { rec.recorderStopped = true; this.pump(); }
    this.render(false);
  };

  Session.prototype.finish = function () {
    if (this._unload) window.removeEventListener('beforeunload', this._unload);
    this.rec = null;
    this.listSig = '';
    this.poll();
  };

  // ------------------------------------------------------------------
  // Rendering
  // ------------------------------------------------------------------

  Session.prototype.renderTimer = function () {
    var rec = this.rec;
    this.liveViews().forEach(function (el) {
      var t = el.querySelector('[data-tr-timer]');
      var q = el.querySelector('[data-tr-queue]');
      if (t && rec) t.textContent = fmt((Date.now() - rec.startedAt) / 1000);
      if (q && rec) {
        var n = rec.queue.length;
        q.textContent = rec.failed ? 'Offline — ' + n + ' piece(s) waiting to upload…' : (n > 1 ? n + ' pieces uploading…' : 'Uploading as you go');
      }
    });
  };

  Session.prototype.render = function (force) {
    var self = this, s = this.summary, rec = this.rec;
    var otherRole = this.role === 'driver' ? 'patient' : 'driver';
    var othersRecording = s && s.recording_active && (s.recorded_by || []).indexOf(otherRole) !== -1;
    var recordings = (s && s.recordings) || [];
    var sig = recordings.map(function (r) { return r.id + ':' + r.status + ':' + (r.is_live ? 1 : 0); }).join('|');

    this.liveViews().forEach(function (el) {
      var showList = el.getAttribute('data-show-list') === '1';
      var html = '';
      if (othersRecording) {
        html += '<div class="pa-tr-banner" role="status"><span class="pa-tr-dot"></span><span>Trip audio is being recorded for safety (by the ' + otherRole + ').</span></div>';
      }
      var canRecord = s && s.can_record;
      if (rec || canRecord) {
        html += '<div class="pa-tr-card"><div class="pa-tr-row">' +
          '<button type="button" class="pa-tr-btn' + (rec ? ' is-rec' : '') + '" data-tr-toggle aria-label="' + (rec ? 'Stop recording' : 'Record trip audio') + '"' + (rec && rec.stopping ? ' disabled' : '') + '>' + (rec ? STOP_SVG : MIC_SVG) + '</button>' +
          '<div style="min-width:0">' +
          (rec
            ? '<div class="pa-tr-title">' + (rec.stopping ? 'Saving recording…' : 'Recording trip audio') + ' <span class="pa-tr-timer" data-tr-timer>' + fmt((Date.now() - rec.startedAt) / 1000) + '</span></div><div class="pa-tr-sub" data-tr-queue>Uploading as you go</div>'
            : '<div class="pa-tr-title">Record trip audio</div><div class="pa-tr-sub">An extra safety layer. The ' + otherRole + ' is told when you record.</div>') +
          '</div></div>' +
          (self.error ? '<div class="pa-tr-err">' + esc(self.error) + '</div>' : '') +
          '</div>';
      } else if (self.error) {
        html += '<div class="pa-tr-err">' + esc(self.error) + '</div>';
      }
      if (showList) {
        html += '<div data-tr-list></div>';
      }

      // Keep existing list DOM (and any playing audio) when only the header changed.
      var oldList = el.querySelector('[data-tr-list]');
      var keepList = oldList && !force && sig === el.getAttribute('data-tr-sig');
      var playing = oldList && Array.prototype.some.call(oldList.querySelectorAll('audio'), function (a) { return !a.paused; });
      if (oldList && (keepList || playing)) oldList.parentNode.removeChild(oldList);
      el.innerHTML = '<div class="pa-tr">' + html + '</div>';
      var slot = el.querySelector('[data-tr-list]');
      if (slot && oldList && (keepList || playing)) {
        slot.parentNode.replaceChild(oldList, slot);
      } else if (slot) {
        slot.innerHTML = self.listHtml(recordings);
        self.wirePlaylists(slot, recordings);
        el.setAttribute('data-tr-sig', sig);
      }
      var btn = el.querySelector('[data-tr-toggle]');
      if (btn) btn.addEventListener('click', function () { if (self.rec) self.stop('stopped'); else self.start(); });
    });
  };

  Session.prototype.listHtml = function (recordings) {
    if (!recordings.length) return '';
    var out = '<div class="pa-tr-list"><div class="pa-tr-title">Trip recordings</div>';
    recordings.forEach(function (r) {
      var when = r.started_at ? new Date(r.started_at).toLocaleString() : '';
      var who = r.recorded_by_me ? 'You' : (r.role === 'driver' ? 'Driver' : 'Patient');
      var keep = r.legal_hold ? 'Kept for an investigation' : (r.expires_at ? 'Deleted after ' + new Date(r.expires_at).toLocaleString() : '');
      out += '<div class="pa-tr-item"><div class="pa-tr-meta"><strong>' + esc(who) + '</strong> · ' + esc(when) + ' · ' + fmt(r.duration_seconds) +
        (r.is_live ? ' · <span style="color:#D0211C;font-weight:700">recording now</span>' : '') + '</div>';
      if (r.play_url) {
        out += '<audio controls preload="none" src="' + esc(r.play_url) + '"></audio>';
      } else if (r.segments && r.segments.length) {
        out += '<audio controls preload="none" data-tr-playlist="' + r.id + '" src="' + esc(r.segments[0].url) + '"></audio>' +
          (r.segments.length > 1 ? '<div class="pa-tr-sub">' + r.segments.length + ' parts, played one after another.</div>' : '');
      } else {
        out += '<div class="pa-tr-sub">No audio received yet.</div>';
      }
      out += '<div class="pa-tr-sub">' + esc(keep) + (r.download_url ? ' · <a href="' + esc(r.download_url) + '">Download</a>' : '') + '</div></div>';
    });
    out += '<div class="pa-tr-sub">Links expire after 15 minutes — reload the page if a recording will not play.</div></div>';
    return out;
  };

  Session.prototype.wirePlaylists = function (root, recordings) {
    Array.prototype.forEach.call(root.querySelectorAll('[data-tr-playlist]'), function (audio) {
      var id = parseInt(audio.getAttribute('data-tr-playlist'), 10);
      var r = recordings.filter(function (x) { return x.id === id; })[0];
      if (!r) return;
      var i = 0;
      audio.addEventListener('ended', function () {
        if (i < r.segments.length - 1) { i++; audio.src = r.segments[i].url; audio.play().catch(function () {}); }
      });
    });
  };

  // ------------------------------------------------------------------
  // Mounting
  // ------------------------------------------------------------------

  function mount(el) {
    if (!el || el.__paTr) return;
    var rideId = parseInt(el.getAttribute('data-ride-id'), 10);
    var role = el.getAttribute('data-role') === 'driver' ? 'driver' : 'patient';
    if (!rideId) return;
    el.__paTr = true;
    injectCss();
    var base = el.getAttribute('data-base') || ((window.PA_BASE_PATH || '') + '/rides/' + rideId + '/recordings');
    var s = sessions[rideId] || (sessions[rideId] = new Session(rideId, role, base));
    s.attach(el);
  }

  function scan(root) {
    Array.prototype.forEach.call((root || document).querySelectorAll('[data-pa-trip-recorder]'), mount);
  }

  window.PaTripRecorder = {
    mount: mount,
    scan: scan,
    /** Stop any active recording for a ride (e.g. driver console after completing). */
    stop: function (rideId) { var s = sessions[rideId]; if (s) s.stop('ride_ended'); },
  };

  function boot() {
    scan(document);
    if (window.MutationObserver) {
      new MutationObserver(function (muts) {
        for (var i = 0; i < muts.length; i++) {
          for (var j = 0; j < muts[i].addedNodes.length; j++) {
            var n = muts[i].addedNodes[j];
            if (n.nodeType !== 1) continue;
            if (n.hasAttribute && n.hasAttribute('data-pa-trip-recorder')) mount(n);
            if (n.querySelectorAll) scan(n);
          }
        }
      }).observe(document.body, { childList: true, subtree: true });
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
