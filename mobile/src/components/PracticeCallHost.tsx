import React, { useCallback, useEffect, useRef, useState } from 'react';
import { AppState, Modal, Pressable, StyleSheet, Text, Vibration, View } from 'react-native';
import * as Notifications from 'expo-notifications';
import { AudioPlayer, createAudioPlayer, setAudioModeAsync } from 'expo-audio';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { acceptPracticeCall, declinePracticeCall, getPracticeRing, PracticeRing } from '../services/practiceCalls';
import { paFonts } from '../theme';

const RING_SOUND = require('../../assets/sounds/request_alert.wav');
const VIBRATION = [0, 800, 500, 800, 1200];
const keyOf = (r: PracticeRing) => `${r.call_id}@${r.started_at}`;

/** Screens showing the call itself answer their own ring. */
let activeCallId: number | null = null;
export function setActivePracticeCall(id: number | null) {
  activeCallId = id;
}

/**
 * Orange practice call — the in-app voice call that rings BOTH sides after a
 * practice accepted an urgent request. Mounted once at the root for patients
 * and practice users (reception, GPs): polls GET /practice-calls/ring and
 * shows a full-screen Accept / Decline with ringtone and the recording
 * notice. Accept → PracticeCall screen (the call + booking / shuttle).
 */
export default function PracticeCallHost({ enabled, navigate }: { enabled: boolean; navigate: (name: string, params?: Record<string, unknown>) => void }) {
  const insets = useSafeAreaInsets();
  const [ring, setRing] = useState<PracticeRing | null>(null);
  const [busy, setBusy] = useState(false);
  const handled = useRef<Record<string, true>>({});
  const player = useRef<AudioPlayer | null>(null);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const ringRef = useRef<PracticeRing | null>(null);
  ringRef.current = ring;

  const stop = useCallback(() => {
    Vibration.cancel();
    try {
      player.current?.pause();
    } catch {
      /* ignore */
    }
  }, []);

  const start = useCallback(async () => {
    Vibration.vibrate(VIBRATION, true);
    try {
      await setAudioModeAsync({ playsInSilentMode: true, interruptionMode: 'duckOthers', shouldPlayInBackground: false });
      if (!player.current) player.current = createAudioPlayer(RING_SOUND);
      player.current.loop = true;
      player.current.seekTo(0).catch(() => {});
      player.current.play();
    } catch {
      /* vibration still runs */
    }
  }, []);

  const close = useCallback(() => {
    stop();
    setRing(null);
  }, [stop]);

  const poll = useCallback(async () => {
    if (timer.current) clearTimeout(timer.current);
    if (!enabled) return;
    let next = 7000;
    if (AppState.currentState === 'active') {
      const r = await getPracticeRing();
      if (r && !handled.current[keyOf(r)] && r.call_id !== activeCallId) {
        if (!ringRef.current || keyOf(ringRef.current) !== keyOf(r)) {
          setRing(r);
          start();
        }
        next = 3000;
      } else if (!r && ringRef.current) {
        close();
      }
    } else {
      next = 15000;
    }
    timer.current = setTimeout(poll, next);
  }, [enabled, start, close]);

  useEffect(() => {
    if (!enabled) return;
    poll();
    const app = AppState.addEventListener('change', (s) => s === 'active' && poll());
    const rx = Notifications.addNotificationReceivedListener((n) => {
      if ((n.request.content.data as any)?.type === 'practice_call') poll();
    });
    return () => {
      app.remove();
      rx.remove();
      if (timer.current) clearTimeout(timer.current);
    };
  }, [enabled, poll]);

  useEffect(() => () => stop(), [stop]);

  async function accept() {
    const r = ringRef.current;
    if (!r || busy) return;
    handled.current[keyOf(r)] = true;
    setBusy(true);
    close();
    try {
      await acceptPracticeCall(r.call_id);
    } catch {
      /* the call screen shows the real state */
    }
    setBusy(false);
    navigate('PracticeCall', { callId: r.call_id });
  }

  async function decline() {
    const r = ringRef.current;
    if (!r) return;
    handled.current[keyOf(r)] = true;
    close();
    declinePracticeCall(r.call_id).catch(() => undefined);
  }

  if (!enabled || !ring) return null;
  return (
    <Modal visible animationType="fade" transparent={false} onRequestClose={decline} statusBarTranslucent>
      <View style={[styles.wrap, { paddingTop: insets.top + 40, paddingBottom: insets.bottom + 32 }]}>
        <View style={{ alignItems: 'center' }}>
          <Text style={styles.eyebrow}>Incoming in-app voice call</Text>
          <View style={styles.halo}>
            <Text style={{ fontSize: 44 }}>📞</Text>
          </View>
          <Text style={styles.title}>{ring.caller_name} is calling you</Text>
          {ring.caller_sub ? <Text style={styles.sub}>{ring.caller_sub}</Text> : null}
          <Text style={styles.line}>{ring.side === 'patient' ? 'They will book your visit with you on this call. It uses data, not airtime.' : 'Answer to talk to the patient and book the visit.'}</Text>
          {ring.recorded && ring.notice ? <Text style={styles.notice}>● {ring.notice}</Text> : null}
        </View>
        <View style={styles.actions}>
          <Pressable onPress={decline} style={[styles.btn, styles.decline]} accessibilityRole="button" accessibilityLabel="Decline">
            <Text style={styles.btnText}>Decline</Text>
          </Pressable>
          <Pressable onPress={accept} style={[styles.btn, styles.join]} accessibilityRole="button" accessibilityLabel="Accept">
            <Text style={styles.btnText}>Accept</Text>
          </Pressable>
        </View>
      </View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  wrap: { flex: 1, backgroundColor: '#101012', justifyContent: 'space-between', paddingHorizontal: 24 },
  eyebrow: { color: '#C9C6BE', fontFamily: paFonts.bold, fontSize: 12, letterSpacing: 1.6, textTransform: 'uppercase', marginBottom: 28 },
  halo: { width: 132, height: 132, borderRadius: 66, backgroundColor: '#2A2A2E', alignItems: 'center', justifyContent: 'center', borderWidth: 10, borderColor: 'rgba(30,142,62,0.35)', marginBottom: 24 },
  title: { color: '#fff', fontFamily: paFonts.black, fontSize: 26, textAlign: 'center' },
  sub: { color: '#C9C6BE', fontFamily: paFonts.regular, fontSize: 15, marginTop: 4, textAlign: 'center' },
  line: { color: '#E8E6E1', fontFamily: paFonts.regular, fontSize: 16, marginTop: 18, textAlign: 'center' },
  notice: { color: '#FDBA74', fontFamily: paFonts.bold, fontSize: 13, marginTop: 16, textAlign: 'center' },
  actions: { flexDirection: 'row', gap: 16, justifyContent: 'center' },
  btn: { flex: 1, maxWidth: 180, minHeight: 60, borderRadius: 999, alignItems: 'center', justifyContent: 'center' },
  decline: { backgroundColor: '#3A3A3F' },
  join: { backgroundColor: '#1E8E3E' },
  btnText: { color: '#fff', fontFamily: paFonts.black, fontSize: 17 },
});
