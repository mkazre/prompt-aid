/*
 * In-page video consult on the Jitsi IFrame API. The server renders a JSON
 * config (domain, room, short-lived JWT, display name…) into
 * <script type="application/json" data-call-config>; this file loads the
 * provider's external_api.js and drives the call with our own controls.
 *
 * mode "web" / "staff": own top bar + control bar on the page.
 * mode "app":           inside the mobile WebView; the native screen draws
 *                       the controls, sends commands via window.paCall.*
 *                       and receives events via ReactNativeWebView.postMessage.
 */
(function () {
  'use strict';

  function post(msg) {
    try {
      if (window.ReactNativeWebView) window.ReactNativeWebView.postMessage(JSON.stringify(msg));
    } catch (e) { /* ignore */ }
  }

  function fmt(sec) {
    var h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60;
    var mm = (m < 10 ? '0' : '') + m, ss = (s < 10 ? '0' : '') + s;
    return h > 0 ? h + ':' + mm + ':' + ss : mm + ':' + ss;
  }

  function loadScript(src) {
    return new Promise(function (resolve, reject) {
      if (window.JitsiMeetExternalAPI) return resolve();
      var s = document.createElement('script');
      s.src = src;
      s.async = true;
      s.onload = function () { window.JitsiMeetExternalAPI ? resolve() : reject(new Error('api-missing')); };
      s.onerror = function () { reject(new Error('script-load')); };
      document.head.appendChild(s);
    });
  }

  function init(root) {
    var cfgEl = root.querySelector('[data-call-config]');
    var cfg;
    try { cfg = JSON.parse(cfgEl ? cfgEl.textContent : '{}'); } catch (e) { cfg = {}; }

    var stage = root.querySelector('[data-call-stage]');
    var overlay = root.querySelector('[data-call-overlay]');
    var overlayMsg = root.querySelector('[data-call-overlay-msg]');
    var overlaySpin = root.querySelector('[data-call-overlay-spin]');
    var overlayActions = root.querySelector('[data-call-overlay-actions]');
    var statusEl = root.querySelector('[data-call-status]');
    var timerEl = root.querySelector('[data-call-timer]');
    var btnMic = root.querySelector('[data-call-mic]');
    var btnCam = root.querySelector('[data-call-cam]');
    var btnEnd = root.querySelector('[data-call-end]');
    var btnPanel = root.querySelector('[data-call-panel-toggle]');
    var panel = root.querySelector('[data-call-panel]');

    var api = null, seconds = 0, timer = null, remote = 0, ended = false, started = false;
    // Consult hub: 'video' | 'voice' (in-app VoIP — Jitsi audio-only, camera off, no video UI).
    var channel = cfg.audioOnly ? 'voice' : 'video';
    var camMuted = !!cfg.audioOnly;
    // Server consult start (ISO): the timer shows the consult time, not this connection's.
    var timerBase = cfg.timerFrom ? Date.parse(cfg.timerFrom) : NaN;
    var skew = cfg.serverTime ? Date.parse(cfg.serverTime) - Date.now() : 0;
    var voiceEl = null;

    function emit(msg) {
      post(msg);
      try { root.dispatchEvent(new CustomEvent('pacall', { detail: msg })); } catch (e) { /* old browsers */ }
    }

    function initialsOf(name) {
      return String(name || '').replace(/^Dr\.?\s*/, '').split(/\s+/).filter(Boolean).slice(0, 2).map(function (w) { return w.charAt(0).toUpperCase(); }).join('');
    }

    function buildVoice() {
      if (voiceEl) return voiceEl;
      var body = root.querySelector('.pa-call__body');
      if (!body) return null;
      voiceEl = document.createElement('div');
      voiceEl.className = 'pa-call__voice';
      voiceEl.setAttribute('aria-hidden', 'true');
      var av = document.createElement('div');
      av.className = 'pa-call__avatar';
      if (cfg.counterpartAvatar) {
        var img = document.createElement('img');
        img.src = cfg.counterpartAvatar; img.alt = '';
        av.appendChild(img);
      } else {
        av.textContent = initialsOf(cfg.counterpart);
      }
      var nm = document.createElement('div');
      nm.className = 'pa-call__voice-name';
      nm.textContent = cfg.counterpart || '';
      var sub = document.createElement('div');
      sub.className = 'pa-call__voice-sub';
      sub.setAttribute('data-call-voice-sub', '');
      sub.textContent = 'Voice call · uses data, not airtime';
      voiceEl.appendChild(av); voiceEl.appendChild(nm); voiceEl.appendChild(sub);
      body.appendChild(voiceEl);
      return voiceEl;
    }

    function applyChannel() {
      root.classList.toggle('is-voice', channel === 'voice');
      if (channel === 'voice') buildVoice();
    }

    function setStatus(t) { if (statusEl) statusEl.textContent = t; }

    function showOverlay(msg, opts) {
      opts = opts || {};
      if (!overlay) return;
      overlay.hidden = false;
      if (overlayMsg) overlayMsg.textContent = msg;
      if (overlaySpin) overlaySpin.hidden = !opts.spin;
      if (overlayActions) {
        overlayActions.innerHTML = '';
        (opts.actions || []).forEach(function (a) {
          var el = document.createElement(a.href ? 'a' : 'button');
          el.className = 'pa-call__pill' + (a.ghost ? ' pa-call__pill--ghost' : '');
          el.textContent = a.label;
          if (a.href) el.href = a.href; else { el.type = 'button'; el.addEventListener('click', a.onClick); }
          overlayActions.appendChild(el);
        });
      }
    }
    function hideOverlay() { if (overlay) overlay.hidden = true; }

    function startTimer() {
      if (timer) return;
      timer = setInterval(function () {
        seconds += 1;
        var shown = !isNaN(timerBase) ? Math.max(0, Math.floor((Date.now() + skew - timerBase) / 1000)) : seconds;
        if (timerEl) timerEl.textContent = fmt(shown);
      }, 1000);
    }
    function stopTimer() { if (timer) { clearInterval(timer); timer = null; } }

    function setBtn(btn, off, labelOn, labelOff) {
      if (!btn) return;
      btn.classList.toggle('is-off', off);
      btn.setAttribute('aria-pressed', off ? 'true' : 'false');
      btn.setAttribute('aria-label', off ? labelOff : labelOn);
      btn.title = off ? labelOff : labelOn;
    }

    function updatePresence() {
      if (remote > 0) {
        root.classList.add('is-live');
        setStatus('Connected');
      } else {
        root.classList.remove('is-live');
        setStatus('Waiting for ' + (cfg.counterpart || 'the other person') + ' to join…');
      }
      emit({ type: 'presence', remote: remote });
    }

    function endUrlPost() {
      if (!cfg.endUrl) return Promise.resolve();
      return fetch(cfg.endUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-CSRF-TOKEN': cfg.csrf || '', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      }).catch(function () { /* best effort */ });
    }

    function finish(reason) {
      if (ended) return;
      ended = true;
      stopTimer();
      root.classList.remove('is-live');
      setStatus('Call ended');
      try { if (api) api.dispose(); } catch (e) { /* ignore */ }
      api = null;
      if (btnMic) btnMic.disabled = true;
      if (btnCam) btnCam.disabled = true;
      if (btnEnd) btnEnd.disabled = true;
      emit({ type: 'ended', reason: reason || 'hangup', seconds: seconds });
      if (cfg.mode !== 'app') endUrlPost();
      var actions = cfg.hub
        // Consult hub: rejoin in place, on either channel (same consult, same timer).
        ? [{ label: 'Rejoin video', onClick: function () { restart('video'); } }, { label: 'Rejoin by voice', ghost: true, onClick: function () { restart('voice'); } }]
        : [{ label: 'Rejoin', onClick: function () { window.location.reload(); } }];
      if (cfg.backUrl) actions.push({ label: 'Back', href: cfg.backUrl, ghost: true });
      showOverlay(seconds > 0 ? 'Call ended · ' + fmt(seconds) : 'You left the call.', { actions: actions });
    }

    function hangup() {
      if (ended) return;
      try { if (api) api.executeCommand('hangup'); } catch (e) { /* ignore */ }
      // readyToClose may never fire if the connection is already gone.
      setTimeout(function () { finish('hangup'); }, 600);
    }

    function fail(message, code) {
      stopTimer();
      emit({ type: 'error', code: code || 'error', message: message });
      var retry = cfg.hub && code === 'network'
        ? [{ label: 'Try video again', onClick: function () { restart('video'); } }, { label: 'Switch to voice', ghost: true, onClick: function () { restart('voice'); } }]
        : [{ label: 'Try again', onClick: function () { window.location.reload(); } }];
      showOverlay(message, { actions: retry.concat(cfg.backUrl ? [{ label: 'Back', href: cfg.backUrl, ghost: true }] : []) });
    }

    function restart(ch) {
      try { if (api) api.dispose(); } catch (e) { /* ignore */ }
      api = null;
      stopTimer();
      ended = false; remote = 0; started = false;
      if (btnMic) btnMic.disabled = false;
      if (btnCam) btnCam.disabled = false;
      if (btnEnd) btnEnd.disabled = false;
      start(ch);
    }

    function start(ch) {
      if (ch === 'voice' || ch === 'video') channel = ch;
      if (started) return;
      started = true;
      camMuted = channel === 'voice';
      applyChannel();
      if (!cfg.domain || !cfg.roomName || !stage) return fail('This call could not be set up.', 'config');
      showOverlay(channel === 'voice' ? 'Connecting your voice call…' : 'Connecting securely…', { spin: true });
      setStatus('Connecting…');

      loadScript(cfg.scriptUrl).then(function () {
        var appMode = cfg.mode === 'app';
        var options = {
          roomName: cfg.roomName,
          parentNode: stage,
          width: '100%',
          height: '100%',
          lang: 'en',
          userInfo: { displayName: cfg.displayName || '', email: cfg.email || '' },
          configOverwrite: {
            prejoinPageEnabled: !!cfg.prejoin,
            prejoinConfig: { enabled: !!cfg.prejoin },
            disableDeepLinking: true,
            deeplinking: { disabled: true },
            startWithAudioMuted: false,
            startWithVideoMuted: channel === 'voice',
            toolbarButtons: cfg.toolbar || [],
            toolbarConfig: { alwaysVisible: false, autoHideWhileChatIsOpen: true },
            hideConferenceSubject: true,
            hideConferenceTimer: true,
            hideParticipantsStats: true,
            conferenceInfo: { alwaysVisible: [], autohide: [] },
            subject: ' ',
            readOnlyName: true,
            disableProfile: true,
            requireDisplayName: false,
            enableWelcomePage: false,
            enableClosePage: false,
            disableInviteFunctions: true,
            enableInsecureRoomNameWarning: false,
            disableThirdPartyRequests: true,
            disableReactions: appMode,
            disableSelfViewSettings: true,
            notifications: appMode ? [] : undefined,
            recordingService: { enabled: false },
            liveStreaming: { enabled: false },
            transcription: { enabled: false },
            fileRecordingsEnabled: false,
            liveStreamingEnabled: false,
            giphy: { enabled: false },
            filmstrip: { disableResizable: true },
            disableShortcuts: appMode,
          },
          interfaceConfigOverwrite: {
            SHOW_JITSI_WATERMARK: false,
            SHOW_WATERMARK_FOR_GUESTS: false,
            SHOW_BRAND_WATERMARK: false,
            SHOW_POWERED_BY: false,
            MOBILE_APP_PROMO: false,
            SHOW_CHROME_EXTENSION_BANNER: false,
            DISABLE_JOIN_LEAVE_NOTIFICATIONS: true,
            HIDE_INVITE_MORE_HEADER: true,
            DISABLE_FOCUS_INDICATOR: true,
            DISABLE_DOMINANT_SPEAKER_INDICATOR: true,
            VIDEO_LAYOUT_FIT: 'both',
            TOOLBAR_BUTTONS: cfg.toolbar || [],
          },
        };
        if (cfg.jwt) options.jwt = cfg.jwt;

        api = new window.JitsiMeetExternalAPI(cfg.domain, options);
        window.paCall.api = api;

        api.addListener('videoConferenceJoined', function () {
          hideOverlay();
          startTimer();
          try { remote = Math.max(0, (api.getNumberOfParticipants() || 1) - 1); } catch (e) { remote = 0; }
          updatePresence();
          emit({ type: 'joined' });
        });
        api.addListener('participantJoined', function () { remote += 1; updatePresence(); });
        api.addListener('participantLeft', function () {
          remote = Math.max(0, remote - 1);
          updatePresence();
          if (remote === 0) setStatus((cfg.counterpart || 'The other person') + ' left the call');
        });
        api.addListener('audioMuteStatusChanged', function (e) {
          setBtn(btnMic, !!e.muted, 'Mute microphone', 'Unmute microphone');
          emit({ type: 'audio', muted: !!e.muted });
        });
        api.addListener('videoMuteStatusChanged', function (e) {
          camMuted = !!e.muted;
          setBtn(btnCam, !!e.muted, 'Turn camera off', 'Turn camera on');
          emit({ type: 'video', muted: !!e.muted });
        });
        api.addListener('readyToClose', function () { finish('hangup'); });
        api.addListener('videoConferenceLeft', function () { finish('left'); });
        api.addListener('errorOccurred', function (e) {
          var err = (e && e.error) || {};
          if (!err.isFatal) return;
          var name = String(err.name || '');
          if (/auth|token|password|notAllowed/i.test(name)) {
            fail('Your secure call pass expired. Tap Try again to rejoin.', 'auth');
          } else {
            fail('The call was interrupted. Check your connection and try again.', 'network');
          }
        });
        if (channel === 'voice') setBtn(btnCam, true, 'Turn camera off', 'Turn camera on');
      }).catch(function () {
        fail('We could not reach the video service. Check your connection and try again.', 'network');
      });
    }

    if (btnMic) btnMic.addEventListener('click', function () { if (api) api.executeCommand('toggleAudio'); });
    if (btnCam) btnCam.addEventListener('click', function () { if (api) api.executeCommand('toggleVideo'); });
    if (btnEnd) btnEnd.addEventListener('click', function () {
      if (ended) return;
      if (window.confirm('Leave this consultation? You can rejoin while the room is open.')) hangup();
    });
    if (btnPanel && panel) {
      var togglePanel = function () {
        panel.hidden = !panel.hidden;
        btnPanel.classList.toggle('is-active', !panel.hidden);
        btnPanel.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
      };
      btnPanel.addEventListener('click', togglePanel);
      var close = panel.querySelector('[data-call-panel-close]');
      if (close) close.addEventListener('click', togglePanel);
      if (window.matchMedia && window.matchMedia('(max-width: 900px)').matches) {
        panel.hidden = true;
        btnPanel.classList.remove('is-active');
        btnPanel.setAttribute('aria-expanded', 'false');
      }
    }
    window.addEventListener('pagehide', function () {
      if (!ended && api && cfg.mode !== 'app') { try { api.executeCommand('hangup'); } catch (e) { /* ignore */ } }
    });

    // Commands from the native app screen.
    window.paCall = {
      api: null,
      toggleAudio: function () { if (api) api.executeCommand('toggleAudio'); },
      toggleVideo: function () { if (api) api.executeCommand('toggleVideo'); },
      flipCamera: function () { if (api) api.executeCommand('toggleCamera'); },
      hangup: hangup,
      // Consult hub: start (deferred pages), switch video <-> voice in the same
      // conference (no reconnect), rejoin after a drop, consult timer base.
      start: function (ch) { if (ended || !api) { if (ended) restart(ch); else start(ch); } else setChannel(ch); },
      restart: restart,
      setChannel: setChannel,
      channel: function () { return channel; },
      isActive: function () { return !!api && !ended; },
      setTimerBase: function (iso, serverIso) {
        var t = Date.parse(iso || '');
        if (!isNaN(t)) timerBase = t;
        if (serverIso) skew = Date.parse(serverIso) - Date.now();
      },
    };

    function setChannel(ch) {
      if (ch !== 'voice' && ch !== 'video') return;
      channel = ch;
      applyChannel();
      // Camera follows the channel: voice = camera off; video = camera on.
      if (api && ((ch === 'voice' && !camMuted) || (ch === 'video' && camMuted))) {
        try { api.executeCommand('toggleVideo'); } catch (e) { /* ignore */ }
      }
      emit({ type: 'channel', channel: ch });
    }

    if (cfg.autostart === false) {
      showOverlay(cfg.waitingText || 'Choose how to connect.', {});
      setStatus('Not connected');
    } else {
      start();
    }
  }

  function boot() {
    var roots = document.querySelectorAll('[data-pa-call]');
    for (var i = 0; i < roots.length; i++) {
      if (!roots[i].__paCall) { roots[i].__paCall = true; init(roots[i]); }
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
