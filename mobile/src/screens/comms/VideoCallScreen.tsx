import React, { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Alert, BackHandler, Linking, PermissionsAndroid, Platform, Pressable, StyleSheet, Text, useWindowDimensions, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { WebView, WebViewMessageEvent } from 'react-native-webview';
import { useNavigation, useRoute } from '@react-navigation/native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { StatusBar } from 'expo-status-bar';
import { apiErrorMessage } from '../../api/client';
import { endVideoCall, fetchVideoJoin, VideoJoin } from '../../api/comms';
import { paFonts } from '../../theme';

type Params = { appointmentId: number; audioOnly?: boolean; name?: string };

type CallEvent =
  | { type: 'joined' }
  | { type: 'presence'; remote: number }
  | { type: 'audio'; muted: boolean }
  | { type: 'video'; muted: boolean }
  | { type: 'ended'; reason?: string; seconds?: number }
  | { type: 'error'; code?: string; message?: string };

async function ensureAndroidPermissions(audioOnly: boolean) {
  if (Platform.OS !== 'android') return true; // iOS: WKWebView prompts using the Info.plist strings
  const wanted = audioOnly
    ? [PermissionsAndroid.PERMISSIONS.RECORD_AUDIO]
    : [PermissionsAndroid.PERMISSIONS.CAMERA, PermissionsAndroid.PERMISSIONS.RECORD_AUDIO];
  const res = await PermissionsAndroid.requestMultiple(wanted);
  return wanted.every((p) => res[p] === PermissionsAndroid.RESULTS.GRANTED);
}

function fmtTimer(sec: number) {
  const h = Math.floor(sec / 3600);
  const m = Math.floor((sec % 3600) / 60);
  const s = sec % 60;
  const mm = String(m).padStart(2, '0');
  const ss = String(s).padStart(2, '0');
  return h > 0 ? `${h}:${mm}:${ss}` : `${mm}:${ss}`;
}

/**
 * Full-screen in-app consult.
 *
 * The WebView loads OUR server page (`embed_url`, a single-use 5-minute
 * link) which runs the Jitsi IFrame API with a server-signed JWT — so the
 * user is signed in to the call automatically and never sees a Jitsi URL,
 * login, app-promo or toolbar. The page posts call events back
 * (joined / presence / mute state / ended / error) and we drive it through
 * `window.paCall.*`. Older servers without `embed_url` fall back to the
 * legacy room URL.
 *
 * Layout (no overlaps): video stage fills everything above a solid bottom
 * control bar; name + timer sit top-left under the status bar; the bar
 * sits above the home indicator; left/right insets respected.
 */
export default function VideoCallScreen() {
  const insets = useSafeAreaInsets();
  const { width } = useWindowDimensions();
  const navigation = useNavigation<any>();
  const { appointmentId, audioOnly = false, name } = useRoute<any>().params as Params;
  const webRef = useRef<WebView>(null);

  const [join, setJoin] = useState<VideoJoin | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [connected, setConnected] = useState(false);
  const [remote, setRemote] = useState(0);
  const [seconds, setSeconds] = useState(0);
  const [micOn, setMicOn] = useState(true);
  const [camOn, setCamOn] = useState(!audioOnly);
  const ended = useRef(false);
  const retried = useRef(false);

  const load = useCallback(async () => {
    setError(null);
    setJoin(null);
    const ok = await ensureAndroidPermissions(audioOnly);
    if (!ok) {
      setError('Camera and microphone permission are needed for a consultation. Enable them in Settings and try again.');
      return;
    }
    try {
      const res = await fetchVideoJoin(appointmentId, audioOnly);
      if (res.external) {
        // Admin-configured external (e.g. Google Meet) link.
        await Linking.openURL(res.join_url);
        navigation.goBack();
        return;
      }
      setJoin(res);
    } catch (e) {
      setError(apiErrorMessage(e, 'Could not open the consultation room.'));
    }
  }, [appointmentId, audioOnly, navigation]);

  useEffect(() => {
    load();
  }, [load]);

  useEffect(() => {
    if (!connected) return;
    const t = setInterval(() => setSeconds((s) => s + 1), 1000);
    return () => clearInterval(t);
  }, [connected]);

  useEffect(() => {
    const sub = BackHandler.addEventListener('hardwareBackPress', () => {
      confirmHangUp();
      return true;
    });
    return () => sub.remove();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [join]);

  const embedded = !!join?.embed_url;

  function run(js: string) {
    webRef.current?.injectJavaScript(`(function(){try{${js}}catch(e){}})(); true;`);
  }

  function toggleMic() {
    if (embedded) {
      run('window.paCall&&window.paCall.toggleAudio();'); // state follows the page's 'audio' event
    } else {
      const next = !micOn;
      setMicOn(next);
      run(`if(window.APP&&APP.conference){APP.conference.muteAudio(${!next});}`);
    }
  }

  function toggleCam() {
    if (embedded) {
      run('window.paCall&&window.paCall.toggleVideo();');
    } else {
      const next = !camOn;
      setCamOn(next);
      run(`if(window.APP&&APP.conference){APP.conference.muteVideo(${!next});}`);
    }
  }

  function flipCam() {
    run('window.paCall&&window.paCall.flipCamera();');
  }

  function openChat() {
    // Chat is pushed on top; this screen (and the call audio) stays mounted underneath.
    navigation.navigate('Chat', { appointmentId, title: join?.counterpart_name ?? name });
  }

  function leave() {
    if (ended.current) return;
    ended.current = true;
    endVideoCall(appointmentId).catch(() => undefined);
    if (navigation.canGoBack()) navigation.goBack();
  }

  function hangUp() {
    run(embedded ? 'window.paCall&&window.paCall.hangup();' : 'if(window.APP&&APP.conference){APP.conference.hangup(false);}');
    leave();
  }

  function confirmHangUp() {
    if (!join || error) {
      navigation.goBack();
      return;
    }
    Alert.alert('Leave consultation?', 'You can rejoin while the consultation window is open.', [
      { text: 'Stay', style: 'cancel' },
      { text: 'Leave', style: 'destructive', onPress: hangUp },
    ]);
  }

  function onMessage(e: WebViewMessageEvent) {
    let msg: CallEvent;
    try {
      msg = JSON.parse(e.nativeEvent.data);
    } catch {
      return;
    }
    switch (msg.type) {
      case 'joined':
        setConnected(true);
        break;
      case 'presence':
        setRemote(msg.remote);
        break;
      case 'audio':
        setMicOn(!msg.muted);
        break;
      case 'video':
        setCamOn(!msg.muted);
        break;
      case 'ended':
        leave();
        break;
      case 'error':
        // A reloaded page can't reuse its single-use link: fetch a fresh one once.
        if ((msg.code === 'expired' || msg.code === 'auth') && !retried.current) {
          retried.current = true;
          setConnected(false);
          load();
        } else {
          setError(msg.message || 'The consultation room could not be loaded.');
        }
        break;
    }
  }

  const title = join?.counterpart_name ?? name ?? 'Consultation';
  const uri = join
    ? join.embed_url ??
      // Legacy server: raw room URL with config in the hash.
      `${join.join_url}&config.toolbarButtons=${encodeURIComponent('[]')}&config.hideConferenceSubject=true&interfaceConfig.SHOW_JITSI_WATERMARK=false`
    : null;

  const status = !join ? 'Preparing…' : !connected ? 'Connecting…' : embedded && remote === 0 ? `Waiting for ${title}…` : fmtTimer(seconds);
  const compact = width < 380;
  const btn = compact ? 48 : 56;
  const endW = compact ? 64 : 72;

  return (
    <View style={styles.container}>
      <StatusBar style="light" />

      {/* Stage: everything above the control bar */}
      <View style={styles.stage}>
        {error ? (
          <View style={[styles.center, { paddingTop: insets.top + 24 }]}>
            <Ionicons name="videocam-off-outline" size={40} color="#fff" />
            <Text style={styles.errorText}>{error}</Text>
            <View style={styles.errorRow}>
              <Pressable onPress={() => { retried.current = false; load(); }} style={styles.errorBtn}>
                <Text style={styles.errorBtnText}>Try again</Text>
              </Pressable>
              <Pressable onPress={() => navigation.goBack()} style={[styles.errorBtn, styles.errorBtnGhost]}>
                <Text style={[styles.errorBtnText, { color: '#fff' }]}>Go back</Text>
              </Pressable>
            </View>
          </View>
        ) : !uri ? (
          <View style={styles.center}>
            <ActivityIndicator color="#fff" size="large" />
            <Text style={styles.connecting}>Preparing your consultation room…</Text>
          </View>
        ) : (
          <WebView
            key={uri}
            ref={webRef}
            source={{ uri }}
            style={styles.web}
            containerStyle={styles.web}
            originWhitelist={['https://*', 'about:*', 'blob:*', 'data:*']}
            javaScriptEnabled
            domStorageEnabled
            thirdPartyCookiesEnabled
            sharedCookiesEnabled
            allowsInlineMediaPlayback
            mediaPlaybackRequiresUserAction={false}
            mediaCapturePermissionGrantType="grant"
            allowsFullscreenVideo={false}
            setSupportMultipleWindows={false}
            bounces={false}
            overScrollMode="never"
            scrollEnabled={false}
            startInLoadingState
            renderLoading={() => (
              <View style={[StyleSheet.absoluteFill, styles.center]}>
                <ActivityIndicator color="#fff" size="large" />
                <Text style={styles.connecting}>Connecting securely…</Text>
              </View>
            )}
            onMessage={onMessage}
            onLoadEnd={() => {
              if (!embedded) setConnected(true);
            }}
            // Block intent:// / app-store deep links; only https frames load.
            onShouldStartLoadWithRequest={(req) => /^(https:|about:|blob:|data:)/.test(req.url)}
            onError={() => setError('The consultation room could not be loaded. Check your connection and try again.')}
            onHttpError={(ev) => {
              if (embedded && ev.nativeEvent.url === uri && ev.nativeEvent.statusCode >= 500) {
                setError('Video calls are temporarily unavailable. Please try again shortly.');
              }
            }}
          />
        )}

        {/* Name + timer: top-left, below the status bar, never over the controls */}
        {!error ? (
          <View pointerEvents="none" style={[styles.top, { top: insets.top + 8, left: insets.left + 12, right: insets.right + 12 }]}>
            <View style={styles.namePill}>
              <View style={[styles.dot, connected && (remote > 0 || !embedded) ? styles.dotLive : null]} />
              <View style={{ flexShrink: 1 }}>
                <Text style={styles.name} numberOfLines={1}>
                  {title}
                </Text>
                <Text style={styles.timer} numberOfLines={1}>
                  {status}
                  {connected && embedded && remote === 0 && seconds > 0 ? ` · ${fmtTimer(seconds)}` : ''}
                </Text>
              </View>
            </View>
          </View>
        ) : null}
      </View>

      {/* Bottom control bar: above the home indicator, evenly spaced */}
      {!error ? (
        <View style={[styles.controls, { paddingBottom: insets.bottom + 12, paddingLeft: insets.left + 12, paddingRight: insets.right + 12 }]}>
          <View style={styles.controlsRow}>
            <CtrlButton size={btn} icon={micOn ? 'mic-outline' : 'mic-off-outline'} off={!micOn} disabled={!join} onPress={toggleMic} label={micOn ? 'Mute microphone' : 'Unmute microphone'} />
            <CtrlButton size={btn} icon={camOn ? 'videocam-outline' : 'videocam-off-outline'} off={!camOn} disabled={!join} onPress={toggleCam} label={camOn ? 'Turn camera off' : 'Turn camera on'} />
            <Pressable onPress={confirmHangUp} style={[styles.hang, { width: endW, height: btn }]} accessibilityRole="button" accessibilityLabel="Hang up">
              <Ionicons name="call" size={26} color="#fff" style={{ transform: [{ rotate: '135deg' }] }} />
            </Pressable>
            {embedded ? (
              <CtrlButton size={btn} icon="camera-reverse-outline" disabled={!connected || !camOn} onPress={flipCam} label="Switch camera" />
            ) : null}
            <CtrlButton size={btn} icon="chatbubble-ellipses-outline" onPress={openChat} label="Message" />
          </View>
        </View>
      ) : null}
    </View>
  );
}

function CtrlButton({ icon, off, disabled, onPress, label, size }: { icon: keyof typeof Ionicons.glyphMap; off?: boolean; disabled?: boolean; onPress: () => void; label: string; size: number }) {
  return (
    <Pressable
      onPress={onPress}
      disabled={disabled}
      hitSlop={6}
      accessibilityRole="button"
      accessibilityLabel={label}
      accessibilityState={{ disabled: !!disabled, selected: !!off }}
      style={({ pressed }) => [
        styles.ctrl,
        { width: size, height: size, borderRadius: size / 2 },
        off && styles.ctrlOff,
        disabled && { opacity: 0.4 },
        pressed && { opacity: 0.7 },
      ]}
    >
      <Ionicons name={icon} size={Math.round(size * 0.43)} color={off ? '#1C1C1E' : '#fff'} />
    </Pressable>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#0B0B0D' },
  stage: { flex: 1, backgroundColor: '#000', overflow: 'hidden' },
  web: { flex: 1, backgroundColor: '#000' },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 32, backgroundColor: '#0B0B0D' },
  connecting: { color: '#C9C9CE', fontFamily: paFonts.regular, fontSize: 14, marginTop: 14 },
  errorText: { color: '#fff', fontFamily: paFonts.regular, fontSize: 15, lineHeight: 22, textAlign: 'center', marginTop: 16 },
  errorRow: { flexDirection: 'row', gap: 12, marginTop: 20 },
  errorBtn: { paddingHorizontal: 22, height: 46, borderRadius: 23, backgroundColor: '#fff', alignItems: 'center', justifyContent: 'center' },
  errorBtnGhost: { backgroundColor: 'rgba(255,255,255,0.14)' },
  errorBtnText: { fontFamily: paFonts.bold, fontSize: 15, color: '#1C1C1E' },
  top: { position: 'absolute', flexDirection: 'row', alignItems: 'flex-start' },
  namePill: { flexDirection: 'row', alignItems: 'center', gap: 8, backgroundColor: 'rgba(0,0,0,0.55)', paddingHorizontal: 12, paddingVertical: 7, borderRadius: 14, maxWidth: '70%' },
  dot: { width: 8, height: 8, borderRadius: 4, backgroundColor: '#F2C200' },
  dotLive: { backgroundColor: '#34C27A' },
  name: { color: '#fff', fontFamily: paFonts.bold, fontSize: 15 },
  timer: { color: '#E5E5EA', fontFamily: paFonts.regular, fontSize: 12, marginTop: 1, fontVariant: ['tabular-nums'] },
  controls: { backgroundColor: '#141417', paddingTop: 12 },
  controlsRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-evenly', width: '100%', maxWidth: 440, alignSelf: 'center' },
  ctrl: { backgroundColor: 'rgba(255,255,255,0.14)', alignItems: 'center', justifyContent: 'center' },
  ctrlOff: { backgroundColor: '#fff' },
  hang: { borderRadius: 28, backgroundColor: '#E5322D', alignItems: 'center', justifyContent: 'center' },
});
