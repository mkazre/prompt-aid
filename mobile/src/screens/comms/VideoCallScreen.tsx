import React, { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Alert, BackHandler, Linking, PermissionsAndroid, Platform, Pressable, StyleSheet, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { WebView } from 'react-native-webview';
import { useNavigation, useRoute } from '@react-navigation/native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { StatusBar } from 'expo-status-bar';
import { apiErrorMessage } from '../../api/client';
import { endVideoCall, fetchVideoJoin, VideoJoin } from '../../api/comms';
import { paFonts } from '../../theme';

type Params = { appointmentId: number; audioOnly?: boolean; name?: string };

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
 * Full-screen in-app consult (Jitsi-compatible room loaded in a WebView).
 * Our own overlay shows the counterpart's name + a running timer and round
 * camera / hang-up / mic buttons; Jitsi's toolbar is hidden via URL config.
 * Hang-up leaves the room and records the end time on the appointment.
 */
export default function VideoCallScreen() {
  const insets = useSafeAreaInsets();
  const navigation = useNavigation<any>();
  const { appointmentId, audioOnly = false, name } = useRoute<any>().params as Params;
  const webRef = useRef<WebView>(null);

  const [join, setJoin] = useState<VideoJoin | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [connected, setConnected] = useState(false);
  const [seconds, setSeconds] = useState(0);
  const [micOn, setMicOn] = useState(true);
  const [camOn, setCamOn] = useState(!audioOnly);
  const ended = useRef(false);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      const ok = await ensureAndroidPermissions(audioOnly);
      if (!ok) {
        setError('Camera and microphone permission are needed for a consultation. Enable them in Settings and try again.');
        return;
      }
      try {
        const res = await fetchVideoJoin(appointmentId, audioOnly);
        if (cancelled) return;
        if (res.external) {
          // Admin-configured external (e.g. Google Meet) link.
          await Linking.openURL(res.join_url);
          navigation.goBack();
          return;
        }
        setJoin(res);
      } catch (e) {
        if (!cancelled) setError(apiErrorMessage(e, 'Could not open the consultation room.'));
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [appointmentId, audioOnly, navigation]);

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

  function run(js: string) {
    webRef.current?.injectJavaScript(`(function(){try{${js}}catch(e){}})(); true;`);
  }

  function toggleMic() {
    const next = !micOn;
    setMicOn(next);
    run(`if(window.APP&&APP.conference){APP.conference.muteAudio(${!next});}`);
  }

  function toggleCam() {
    const next = !camOn;
    setCamOn(next);
    run(`if(window.APP&&APP.conference){APP.conference.muteVideo(${!next});}`);
  }

  async function hangUp() {
    if (ended.current) return;
    ended.current = true;
    run('if(window.APP&&APP.conference){APP.conference.hangup(false);}');
    endVideoCall(appointmentId).catch(() => undefined);
    navigation.goBack();
  }

  function confirmHangUp() {
    if (!join) {
      navigation.goBack();
      return;
    }
    Alert.alert('Leave consultation?', 'You can rejoin while the consultation window is open.', [
      { text: 'Stay', style: 'cancel' },
      { text: 'Leave', style: 'destructive', onPress: hangUp },
    ]);
  }

  const title = join?.counterpart_name ?? name ?? 'Consultation';
  // Hide Jitsi's own toolbar/watermarks — our overlay drives the call.
  const uri = join ? `${join.join_url}&config.toolbarButtons=${encodeURIComponent('[]')}&config.hideConferenceSubject=true&interfaceConfig.SHOW_JITSI_WATERMARK=false` : null;

  return (
    <View style={styles.container}>
      <StatusBar style="light" />
      {error ? (
        <View style={styles.center}>
          <Ionicons name="videocam-off-outline" size={40} color="#fff" />
          <Text style={styles.errorText}>{error}</Text>
          <Pressable onPress={() => navigation.goBack()} style={styles.errorBtn}>
            <Text style={styles.errorBtnText}>Go back</Text>
          </Pressable>
        </View>
      ) : !uri ? (
        <View style={styles.center}>
          <ActivityIndicator color="#fff" size="large" />
          <Text style={styles.connecting}>Preparing your consultation room…</Text>
        </View>
      ) : (
        <WebView
          ref={webRef}
          source={{ uri }}
          style={styles.web}
          originWhitelist={['https://*', 'about:*', 'blob:*', 'data:*']}
          javaScriptEnabled
          domStorageEnabled
          allowsInlineMediaPlayback
          mediaPlaybackRequiresUserAction={false}
          mediaCapturePermissionGrantType="grant"
          allowsFullscreenVideo
          startInLoadingState
          renderLoading={() => (
            <View style={[StyleSheet.absoluteFill, styles.center]}>
              <ActivityIndicator color="#fff" size="large" />
              <Text style={styles.connecting}>Connecting…</Text>
            </View>
          )}
          onLoadEnd={() => setConnected(true)}
          // Block intent:// / app-store deep links the Jitsi web app may try on mobile.
          onShouldStartLoadWithRequest={(req) => /^(https:|about:|blob:|data:)/.test(req.url)}
          onError={() => setError('The consultation room could not be loaded. Check your connection and try again.')}
        />
      )}

      {/* Overlay: name + timer */}
      <View pointerEvents="box-none" style={[styles.top, { paddingTop: insets.top + 10 }]}>
        <Pressable onPress={confirmHangUp} hitSlop={10} style={styles.topBtn} accessibilityLabel="Leave call">
          <Ionicons name="chevron-down" size={22} color="#fff" />
        </Pressable>
        <View style={styles.namePill}>
          <Text style={styles.name} numberOfLines={1}>
            {title}
          </Text>
          <Text style={styles.timer}>{connected ? fmtTimer(seconds) : audioOnly ? 'Audio call' : 'Video call'}</Text>
        </View>
        <View style={{ width: 40 }} />
      </View>

      {/* Controls */}
      {!error ? (
        <View style={[styles.controls, { paddingBottom: insets.bottom + 24 }]}>
          <Pressable onPress={toggleCam} disabled={!join} style={[styles.ctrl, !camOn && styles.ctrlOff]} accessibilityLabel={camOn ? 'Turn camera off' : 'Turn camera on'}>
            <Ionicons name={camOn ? 'videocam-outline' : 'videocam-off-outline'} size={26} color={camOn ? '#fff' : '#1C1C1E'} />
          </Pressable>
          <Pressable onPress={confirmHangUp} style={styles.hang} accessibilityLabel="Hang up">
            <Ionicons name="call" size={30} color="#fff" style={{ transform: [{ rotate: '135deg' }] }} />
          </Pressable>
          <Pressable onPress={toggleMic} disabled={!join} style={[styles.ctrl, !micOn && styles.ctrlOff]} accessibilityLabel={micOn ? 'Mute microphone' : 'Unmute microphone'}>
            <Ionicons name={micOn ? 'mic-outline' : 'mic-off-outline'} size={26} color={micOn ? '#fff' : '#1C1C1E'} />
          </Pressable>
        </View>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#0B0B0D' },
  web: { flex: 1, backgroundColor: '#0B0B0D' },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 32, backgroundColor: '#0B0B0D' },
  connecting: { color: '#C9C9CE', fontFamily: paFonts.regular, fontSize: 14, marginTop: 14 },
  errorText: { color: '#fff', fontFamily: paFonts.regular, fontSize: 15, lineHeight: 22, textAlign: 'center', marginTop: 16 },
  errorBtn: { marginTop: 20, paddingHorizontal: 24, height: 46, borderRadius: 23, backgroundColor: '#fff', alignItems: 'center', justifyContent: 'center' },
  errorBtnText: { fontFamily: paFonts.bold, fontSize: 15, color: '#1C1C1E' },
  top: { position: 'absolute', left: 0, right: 0, top: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingHorizontal: 16 },
  topBtn: { width: 40, height: 40, borderRadius: 20, backgroundColor: 'rgba(0,0,0,0.35)', alignItems: 'center', justifyContent: 'center' },
  namePill: { alignItems: 'center', backgroundColor: 'rgba(0,0,0,0.35)', paddingHorizontal: 16, paddingVertical: 6, borderRadius: 18, maxWidth: '60%' },
  name: { color: '#fff', fontFamily: paFonts.bold, fontSize: 16 },
  timer: { color: '#E5E5EA', fontFamily: paFonts.regular, fontSize: 12, marginTop: 1, fontVariant: ['tabular-nums'] },
  controls: { position: 'absolute', left: 0, right: 0, bottom: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 28 },
  ctrl: { width: 60, height: 60, borderRadius: 30, backgroundColor: 'rgba(255,255,255,0.18)', alignItems: 'center', justifyContent: 'center' },
  ctrlOff: { backgroundColor: '#fff' },
  hang: { width: 72, height: 72, borderRadius: 36, backgroundColor: '#E5322D', alignItems: 'center', justifyContent: 'center' },
});
