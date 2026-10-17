import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Animated, Easing, Modal, Pressable, StyleSheet, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { api } from '../../api/client';
import { pa, paFonts } from '../../theme';
import {
  clearError,
  hasConsented,
  rememberConsent,
  resumePendingUploads,
  startRecording,
  stopRecording,
  subscribe,
  type RecorderSnapshot,
} from '../../services/tripRecorder';

interface Props {
  rideId: number;
  role: 'driver' | 'patient';
  /** Optional: show a "Recordings" link (e.g. navigate to RideRecordingsScreen). */
  onOpenRecordings?: () => void;
}

interface Summary {
  recording_active: boolean;
  recorded_by: string[];
  ride_status: string;
  can_record: boolean;
  cannot_record_reason: string | null;
  recordings: { id: number }[];
  settings: { retention_days: number; max_minutes: number };
}

const POLL_MS = 15000;

function fmt(ms: number) {
  const s = Math.max(0, Math.floor(ms / 1000));
  const h = Math.floor(s / 3600);
  const m = Math.floor((s % 3600) / 60);
  const ss = String(s % 60).padStart(2, '0');
  return h ? `${h}:${String(m).padStart(2, '0')}:${ss}` : `${m}:${ss}`;
}

/**
 * Trip audio recording (safety): mic button with red pulse + timer while
 * recording, consent explainer on first use, banner when the OTHER person is
 * recording, auto-stop when the ride completes/cancels. Recording continues
 * if this component unmounts (see services/tripRecorder.ts).
 */
export default function TripRecorder({ rideId, role, onOpenRecordings }: Props) {
  const [summary, setSummary] = useState<Summary | null>(null);
  const [rec, setRec] = useState<RecorderSnapshot | null>(null);
  const [now, setNow] = useState(Date.now());
  const [consentOpen, setConsentOpen] = useState(false);
  const pulse = useRef(new Animated.Value(0)).current;
  const pollTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const mounted = useRef(true);

  const mine = rec && rec.rideId === rideId ? rec : null;
  const recording = mine?.phase === 'recording' || mine?.phase === 'stopping';
  const otherRole = role === 'driver' ? 'patient' : 'driver';

  useEffect(() => subscribe(setRec), []);
  useEffect(() => {
    resumePendingUploads();
  }, []);

  const poll = useCallback(async () => {
    if (pollTimer.current) clearTimeout(pollTimer.current);
    try {
      const { data } = await api.get(`/rides/${rideId}/recordings`);
      if (!mounted.current) return;
      setSummary(data as Summary);
    } catch {
      /* keep last state */
    }
    if (mounted.current) pollTimer.current = setTimeout(poll, POLL_MS);
  }, [rideId]);

  useEffect(() => {
    mounted.current = true;
    poll();
    return () => {
      mounted.current = false;
      if (pollTimer.current) clearTimeout(pollTimer.current);
    };
  }, [poll]);

  // Auto-stop when the ride is over.
  useEffect(() => {
    if (mine?.phase === 'recording' && summary && (summary.ride_status === 'completed' || summary.ride_status === 'cancelled')) {
      stopRecording('ride_ended').then(poll);
    }
  }, [summary?.ride_status, mine?.phase, poll]);

  // Timer + pulse.
  useEffect(() => {
    if (!recording) return;
    const t = setInterval(() => setNow(Date.now()), 1000);
    const loop = Animated.loop(Animated.timing(pulse, { toValue: 1, duration: 1200, easing: Easing.out(Easing.ease), useNativeDriver: true }));
    loop.start();
    return () => {
      clearInterval(t);
      loop.stop();
      pulse.setValue(0);
    };
  }, [recording, pulse]);

  async function begin() {
    setConsentOpen(false);
    await rememberConsent();
    try {
      await startRecording(rideId);
    } catch {
      /* error shown from snapshot */
    }
    poll();
  }

  async function onPress() {
    clearError();
    if (recording) {
      await stopRecording('stopped');
      poll();
      return;
    }
    if (rec && rec.phase !== 'idle') return; // recording another ride
    if (await hasConsented()) begin();
    else setConsentOpen(true);
  }

  const othersRecording = !!summary?.recording_active && (summary?.recorded_by ?? []).includes(otherRole);
  const canRecord = !!summary?.can_record;
  const showCard = recording || canRecord;
  const count = summary?.recordings?.length ?? 0;
  const error = mine?.error ?? (rec?.phase === 'idle' ? rec?.error : null);

  if (!summary && !recording) return null;

  return (
    <View style={styles.wrap}>
      {othersRecording ? (
        <View style={styles.banner} accessibilityRole="alert">
          <View style={styles.dot} />
          <Text style={styles.bannerText}>Trip audio is being recorded for safety (by the {otherRole}).</Text>
        </View>
      ) : null}

      {showCard ? (
        <View style={styles.card}>
          <View style={styles.row}>
            <View style={styles.btnWrap}>
              {recording ? (
                <Animated.View
                  pointerEvents="none"
                  style={[styles.ring, { opacity: pulse.interpolate({ inputRange: [0, 1], outputRange: [0.6, 0] }), transform: [{ scale: pulse.interpolate({ inputRange: [0, 1], outputRange: [1, 1.6] }) }] }]}
                />
              ) : null}
              <Pressable
                onPress={onPress}
                disabled={mine?.phase === 'stopping' || mine?.phase === 'starting'}
                style={[styles.btn, recording && styles.btnRec]}
                accessibilityRole="button"
                accessibilityLabel={recording ? 'Stop recording trip audio' : 'Record trip audio'}
              >
                <Ionicons name={recording ? 'stop' : 'mic'} size={24} color={recording ? '#fff' : pa.signal} />
              </Pressable>
            </View>
            <View style={{ flex: 1 }}>
              {recording ? (
                <>
                  <Text style={styles.title}>
                    {mine?.phase === 'stopping' ? 'Saving recording…' : 'Recording trip audio'}{' '}
                    <Text style={styles.timer}>{fmt(now - (mine?.startedAt ?? now))}</Text>
                  </Text>
                  <Text style={styles.sub}>
                    {mine?.offline ? `Offline — ${mine.pending} piece(s) waiting to upload` : 'Uploading as you go · tap to stop'}
                  </Text>
                </>
              ) : (
                <>
                  <Text style={styles.title}>{mine?.phase === 'starting' ? 'Starting…' : 'Record trip audio'}</Text>
                  <Text style={styles.sub}>An extra safety layer. The {otherRole} is told when you record.</Text>
                </>
              )}
            </View>
          </View>
          {error ? <Text style={styles.err}>{error}</Text> : null}
          {!recording && rec && rec.phase === 'recording' && rec.rideId !== rideId ? (
            <Text style={styles.err}>You are already recording another trip.</Text>
          ) : null}
        </View>
      ) : null}

      {onOpenRecordings && count > 0 ? (
        <Pressable onPress={onOpenRecordings} style={styles.link} accessibilityRole="link">
          <Ionicons name="play-circle-outline" size={18} color={pa.info} />
          <Text style={styles.linkText}>Trip recordings ({count})</Text>
        </Pressable>
      ) : null}

      <Modal visible={consentOpen} transparent animationType="fade" onRequestClose={() => setConsentOpen(false)}>
        <View style={styles.backdrop}>
          <View style={styles.modal}>
            <Text style={styles.modalTitle}>Record trip audio for safety</Text>
            {[
              'Audio from your microphone is recorded and uploaded every 30 seconds, so it is kept even if your phone is lost or taken.',
              `The ${otherRole} is told on their screen that the trip is being recorded.`,
              'You, the other person on the trip and authorised Prompt Aid safety staff can play it. Every play is logged.',
              `Recordings are encrypted and deleted automatically after ${summary?.settings?.retention_days ?? 3} day(s), unless needed for an SOS, complaint or investigation.`,
              'Recording keeps going with the screen locked (you will see a notification on Android). It stops by itself when the trip ends.',
            ].map((t) => (
              <View key={t} style={styles.bulletRow}>
                <Text style={styles.bullet}>•</Text>
                <Text style={styles.bulletText}>{t}</Text>
              </View>
            ))}
            <View style={styles.modalActions}>
              <Pressable onPress={() => setConsentOpen(false)} style={[styles.mBtn, styles.mGhost]}>
                <Text style={styles.mGhostText}>Not now</Text>
              </Pressable>
              <Pressable onPress={begin} style={[styles.mBtn, styles.mGo]}>
                <Text style={styles.mGoText}>I understand, record</Text>
              </Pressable>
            </View>
          </View>
        </View>
      </Modal>
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: { marginTop: 12 },
  banner: { flexDirection: 'row', alignItems: 'center', gap: 10, backgroundColor: pa.signalWash, borderRadius: 10, paddingVertical: 10, paddingHorizontal: 12, marginBottom: 10 },
  dot: { width: 10, height: 10, borderRadius: 5, backgroundColor: pa.signal },
  bannerText: { flex: 1, color: pa.signalInk, fontFamily: paFonts.bold, fontSize: 14 },
  card: { borderWidth: 1, borderColor: pa.line, borderRadius: 12, backgroundColor: pa.surface, padding: 12 },
  row: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  btnWrap: { width: 52, height: 52, alignItems: 'center', justifyContent: 'center' },
  ring: { position: 'absolute', width: 52, height: 52, borderRadius: 26, backgroundColor: pa.signal },
  btn: { width: 52, height: 52, borderRadius: 26, borderWidth: 2, borderColor: pa.signal, backgroundColor: pa.surface, alignItems: 'center', justifyContent: 'center' },
  btnRec: { backgroundColor: pa.signal },
  title: { fontFamily: paFonts.black, fontSize: 15, color: pa.ink },
  timer: { color: pa.signal, fontVariant: ['tabular-nums'] },
  sub: { fontFamily: paFonts.regular, fontSize: 13, color: pa.muted, marginTop: 2 },
  err: { fontFamily: paFonts.regular, fontSize: 13, color: pa.signalInk, marginTop: 8 },
  link: { flexDirection: 'row', alignItems: 'center', gap: 6, marginTop: 10 },
  linkText: { fontFamily: paFonts.bold, fontSize: 14, color: pa.info },
  backdrop: { flex: 1, backgroundColor: 'rgba(16,16,18,0.55)', alignItems: 'center', justifyContent: 'center', padding: 16 },
  modal: { backgroundColor: pa.surface, borderRadius: 14, padding: 20, width: '100%', maxWidth: 440 },
  modalTitle: { fontFamily: paFonts.black, fontSize: 18, color: pa.ink, marginBottom: 10 },
  bulletRow: { flexDirection: 'row', gap: 8, marginBottom: 6 },
  bullet: { color: pa.ink, fontSize: 14, lineHeight: 20 },
  bulletText: { flex: 1, fontFamily: paFonts.regular, fontSize: 14, lineHeight: 20, color: pa.inkSoft },
  modalActions: { flexDirection: 'row', justifyContent: 'flex-end', gap: 10, marginTop: 12, flexWrap: 'wrap' },
  mBtn: { borderRadius: 999, paddingVertical: 10, paddingHorizontal: 16, borderWidth: 1 },
  mGhost: { borderColor: pa.ink, backgroundColor: pa.surface },
  mGhostText: { fontFamily: paFonts.bold, color: pa.ink },
  mGo: { borderColor: pa.signal, backgroundColor: pa.signal },
  mGoText: { fontFamily: paFonts.bold, color: '#fff' },
});
