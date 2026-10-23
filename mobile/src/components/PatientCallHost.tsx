import React, { useCallback, useEffect, useRef, useState } from 'react';
import { AppState, Image, Modal, Pressable, StyleSheet, Text, Vibration, View } from 'react-native';
import * as Notifications from 'expo-notifications';
import { AudioPlayer, createAudioPlayer, setAudioModeAsync } from 'expo-audio';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { answerRing, declineRing, getRing, RingPayload } from '../services/consults';
import { paFonts } from '../theme';

const RING_SOUND = require('../../assets/sounds/request_alert.wav');
const VIBRATION = [0, 800, 500, 800, 1200];

/** Lets screens (e.g. InstantConsult's own status poll) open the incoming call at once. */
const listeners = new Set<(r: RingPayload) => void>();
export function showIncomingCall(ring: RingPayload) {
  listeners.forEach((fn) => fn(ring));
}

const keyOf = (r: RingPayload) => `${r.appointment_id}@${r.started_at}`;

/**
 * Mounted once at the app root for patients. When a doctor accepts an
 * on-demand video consult or presses Start on a booked one, the server
 * "rings" the patient: this shows a full-screen "Dr X is ready — joining
 * your video consult…" with ringtone + vibration and opens the in-app
 * VideoCallScreen after auto_join_seconds. "Join now" is always there;
 * "Not now" declines (the doctor is told). Polls GET /consults/ring (6 s,
 * cache-only on the server) while the app is open; a "consult_call" push
 * triggers an immediate poll (a tapped push opens the call directly —
 * see RootNavigator).
 */
export default function PatientCallHost({ enabled, navigate }: { enabled: boolean; navigate: (name: string, params?: Record<string, unknown>) => void }) {
  const insets = useSafeAreaInsets();
  const [ring, setRing] = useState<RingPayload | null>(null);
  const [secs, setSecs] = useState(0);
  const handled = useRef<Record<string, true>>({});
  const player = useRef<AudioPlayer | null>(null);
  const pollTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const ringRef = useRef<RingPayload | null>(null);
  ringRef.current = ring;

  const stopAlert = useCallback(() => {
    Vibration.cancel();
    try {
      player.current?.pause();
    } catch {
      /* ignore */
    }
  }, []);

  const startAlert = useCallback(async () => {
    Vibration.vibrate(VIBRATION, true);
    try {
      await setAudioModeAsync({ playsInSilentMode: true, interruptionMode: 'duckOthers', shouldPlayInBackground: false });
      if (!player.current) player.current = createAudioPlayer(RING_SOUND);
      player.current.loop = true;
      player.current.volume = 1;
      player.current.seekTo(0).catch(() => {});
      player.current.play();
    } catch {
      /* vibration still runs */
    }
  }, []);

  const open = useCallback(
    (r: RingPayload) => {
      if (!r?.appointment_id || handled.current[keyOf(r)]) return;
      if (ringRef.current && keyOf(ringRef.current) === keyOf(r)) return;
      setRing(r);
      setSecs(Math.max(0, Number(r.auto_join_seconds ?? 0)));
      startAlert();
    },
    [startAlert],
  );

  const close = useCallback(() => {
    stopAlert();
    setRing(null);
  }, [stopAlert]);

  const join = useCallback(() => {
    const r = ringRef.current;
    if (!r) return;
    handled.current[keyOf(r)] = true;
    close();
    answerRing(r.appointment_id).catch(() => {});
    // Consult hub servers send the channel: voice = in-app audio-only call (no phone number exchanged).
    navigate('VideoCall', { appointmentId: r.appointment_id, name: r.doctor_name, audioOnly: r.channel === 'voice' });
  }, [close, navigate]);

  const decline = useCallback(() => {
    const r = ringRef.current;
    if (!r) return;
    handled.current[keyOf(r)] = true;
    close();
    declineRing(r.appointment_id).catch(() => {});
  }, [close]);

  // Auto-join countdown.
  useEffect(() => {
    if (!ring) return;
    if (secs <= 0) {
      const t = setTimeout(join, 300);
      return () => clearTimeout(t);
    }
    const t = setTimeout(() => setSecs((s) => s - 1), 1000);
    return () => clearTimeout(t);
  }, [ring, secs, join]);

  const poll = useCallback(async () => {
    if (pollTimer.current) clearTimeout(pollTimer.current);
    if (!enabled) return;
    let next = 6000;
    try {
      if (AppState.currentState === 'active') {
        const r = await getRing();
        if (r) open(r);
        else if (ringRef.current) close(); // the doctor left or it stopped ringing
        next = r ? 4000 : 6000;
      } else {
        next = 15000;
      }
    } catch {
      next = 10000;
    }
    pollTimer.current = setTimeout(poll, next);
  }, [enabled, open, close]);

  useEffect(() => {
    if (!enabled) return;
    listeners.add(open);
    poll();
    const app = AppState.addEventListener('change', (s) => {
      if (s === 'active') poll();
    });
    const rx = Notifications.addNotificationReceivedListener((n) => {
      const d: any = n.request.content.data;
      if (d?.type === 'consult_call') poll();
    });
    return () => {
      listeners.delete(open);
      app.remove();
      rx.remove();
      if (pollTimer.current) clearTimeout(pollTimer.current);
    };
  }, [enabled, poll, open]);

  useEffect(
    () => () => {
      stopAlert();
      try {
        player.current?.remove();
      } catch {
        /* ignore */
      }
      player.current = null;
    },
    [stopAlert],
  );

  if (!enabled || !ring) return null;
  const voice = ring.channel === 'voice';
  const what = voice ? 'voice call' : 'video consult';
  const initials = ring.doctor_name
    .replace(/^Dr\.?\s*/, '')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w[0]?.toUpperCase())
    .join('');

  return (
    <Modal visible animationType="fade" transparent={false} onRequestClose={decline} statusBarTranslucent>
      <View style={[styles.wrap, { paddingTop: insets.top + 40, paddingBottom: insets.bottom + 32 }]}>
        <View style={{ alignItems: 'center' }}>
          <Text style={styles.eyebrow}>{voice ? 'Incoming voice call (in-app)' : 'Incoming video consult'}</Text>
          <View style={styles.halo}>
            {ring.doctor_photo_url ? <Image source={{ uri: ring.doctor_photo_url }} style={styles.photo} /> : <Text style={styles.initials}>{initials}</Text>}
          </View>
          <Text style={styles.title}>{ring.doctor_name} {voice ? 'is calling you' : 'is ready'}</Text>
          {ring.doctor_specialization ? <Text style={styles.sub}>{ring.doctor_specialization}</Text> : null}
          <Text style={styles.line}>{secs > 0 ? `Joining your ${what} in ${secs}s…` : `Joining your ${what}…`}</Text>
        </View>
        <View style={styles.actions}>
          <Pressable onPress={decline} style={[styles.btn, styles.decline]} accessibilityRole="button" accessibilityLabel="Not now">
            <Text style={styles.btnText}>Not now</Text>
          </Pressable>
          <Pressable onPress={join} style={[styles.btn, styles.join]} accessibilityRole="button" accessibilityLabel={voice ? 'Answer' : 'Join now'}>
            <Text style={styles.btnText}>{voice ? 'Answer' : 'Join now'}</Text>
          </Pressable>
        </View>
      </View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  wrap: { flex: 1, backgroundColor: '#101012', justifyContent: 'space-between', paddingHorizontal: 24 },
  eyebrow: { color: '#C9C6BE', fontFamily: paFonts.bold, fontSize: 12, letterSpacing: 1.6, textTransform: 'uppercase', marginBottom: 28 },
  halo: {
    width: 132,
    height: 132,
    borderRadius: 66,
    backgroundColor: '#2A2A2E',
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 10,
    borderColor: 'rgba(30,142,62,0.35)',
    overflow: 'hidden',
    marginBottom: 24,
  },
  photo: { width: '100%', height: '100%' },
  initials: { color: '#fff', fontFamily: paFonts.black, fontSize: 40 },
  title: { color: '#fff', fontFamily: paFonts.black, fontSize: 26, textAlign: 'center' },
  sub: { color: '#C9C6BE', fontFamily: paFonts.regular, fontSize: 15, marginTop: 4 },
  line: { color: '#E8E6E1', fontFamily: paFonts.regular, fontSize: 16, marginTop: 18, textAlign: 'center' },
  actions: { flexDirection: 'row', gap: 16, justifyContent: 'center' },
  btn: { flex: 1, maxWidth: 180, minHeight: 60, borderRadius: 999, alignItems: 'center', justifyContent: 'center' },
  decline: { backgroundColor: '#3A3A3F' },
  join: { backgroundColor: '#1E8E3E' },
  btnText: { color: '#fff', fontFamily: paFonts.black, fontSize: 17 },
});
