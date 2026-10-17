import React, { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Linking, Pressable, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { Ionicons } from '@expo/vector-icons';
import { setAudioModeAsync, useAudioPlayer, useAudioPlayerStatus } from 'expo-audio';
import { api, apiErrorMessage } from '../../api/client';
import { pa, paFonts } from '../../theme';

interface Recording {
  id: number;
  role: 'driver' | 'patient';
  recorded_by_me: boolean;
  recorded_by_name: string;
  status: string;
  is_live: boolean;
  layout: 'stream' | 'segments';
  started_at: string | null;
  duration_seconds: number;
  sha256: string | null;
  expires_at: string | null;
  legal_hold: boolean;
  play_url: string | null;
  segments: { seq: number; duration_ms: number; url: string }[];
  download_url: string | null;
  urls_expire_at: string;
}

interface Summary {
  ride_ref: string;
  my_role: 'driver' | 'patient' | null;
  settings: { retention_days: number };
  recordings: Recording[];
}

function fmt(sec: number) {
  const s = Math.max(0, Math.floor(sec));
  return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
}

function when(iso: string | null) {
  if (!iso) return '';
  const d = new Date(iso);
  return `${d.toLocaleDateString()} ${d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`;
}

/**
 * All audio recordings of one trip (both the patient's and the driver's),
 * with a player. Route params: { rideId: number }. Registered as
 * "RideRecordings" in the patient Shuttle stack and the driver Trips stack.
 */
export default function RideRecordingsScreen({ route }: any) {
  const rideId: number = route.params.rideId;
  const [summary, setSummary] = useState<Summary | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [active, setActive] = useState<{ id: number; part: number } | null>(null);
  const player = useAudioPlayer(null);
  const status = useAudioPlayerStatus(player);
  const summaryRef = useRef<Summary | null>(null);

  const load = useCallback(async () => {
    try {
      const { data } = await api.get(`/rides/${rideId}/recordings`);
      summaryRef.current = data;
      setSummary(data);
      setError(null);
      return data as Summary;
    } catch (e) {
      setError(apiErrorMessage(e, 'Could not load the recordings.'));
      return null;
    } finally {
      setLoading(false);
    }
  }, [rideId]);

  useFocusEffect(
    useCallback(() => {
      load();
      return () => {
        try {
          player.pause();
        } catch {
          /* ignore */
        }
      };
    }, [load, player]),
  );

  useEffect(() => {
    setAudioModeAsync({ playsInSilentMode: true }).catch(() => undefined);
  }, []);

  function urlFor(r: Recording, part: number): string | null {
    if (r.play_url) return r.play_url;
    return r.segments[part]?.url ?? null;
  }

  async function play(r: Recording, part = 0) {
    let rec = r;
    // Signed links last 15 minutes: refresh them if needed.
    if (new Date(r.urls_expire_at).getTime() - Date.now() < 30000) {
      const fresh = await load();
      rec = fresh?.recordings.find((x) => x.id === r.id) ?? r;
    }
    const url = urlFor(rec, part);
    if (!url) return;
    player.replace({ uri: url });
    player.play();
    setActive({ id: rec.id, part });
  }

  function toggle(r: Recording) {
    if (active?.id === r.id) {
      if (status.playing) player.pause();
      else player.play();
      return;
    }
    play(r, 0);
  }

  // App recordings are back-to-back parts: continue with the next one.
  useEffect(() => {
    if (!status.didJustFinish || !active) return;
    const r = summaryRef.current?.recordings.find((x) => x.id === active.id);
    if (r && r.layout === 'segments' && active.part < r.segments.length - 1) {
      play(r, active.part + 1);
    } else {
      setActive(null);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [status.didJustFinish]);

  if (loading && !summary) {
    return <View style={styles.center}><ActivityIndicator color={pa.signal} /></View>;
  }

  const list = summary?.recordings ?? [];

  return (
    <ScrollView style={styles.container} contentContainerStyle={{ padding: 16, paddingBottom: 40 }} refreshControl={<RefreshControl refreshing={false} onRefresh={load} />}>
      <Text style={styles.h1}>Trip recordings</Text>
      {summary ? <Text style={styles.muted}>{summary.ride_ref}</Text> : null}
      <Text style={styles.note}>
        Audio recorded on this trip by you or the {summary?.my_role === 'driver' ? 'patient' : 'driver'} as a safety measure. Both of you and authorised Prompt Aid safety staff can play it; every play is logged.
        Recordings are deleted automatically after {summary?.settings?.retention_days ?? 3} day(s) unless kept for an SOS, complaint or investigation.
      </Text>

      {error ? <Text style={styles.err}>{error}</Text> : null}
      {!error && list.length === 0 ? <Text style={styles.empty}>No recordings for this trip.</Text> : null}

      {list.map((r) => {
        const isActive = active?.id === r.id;
        const playable = !!r.play_url || r.segments.length > 0;
        return (
          <View key={r.id} style={styles.item}>
            <View style={styles.row}>
              <Pressable
                onPress={() => toggle(r)}
                disabled={!playable}
                style={[styles.play, !playable && { opacity: 0.4 }]}
                accessibilityRole="button"
                accessibilityLabel={isActive && status.playing ? 'Pause' : 'Play recording'}
              >
                <Ionicons name={isActive && status.playing ? 'pause' : 'play'} size={22} color="#fff" />
              </Pressable>
              <View style={{ flex: 1 }}>
                <Text style={styles.title}>{r.recorded_by_me ? 'You' : r.role === 'driver' ? 'Driver' : 'Patient'} · {fmt(r.duration_seconds)}</Text>
                <Text style={styles.muted}>
                  {when(r.started_at)}
                  {r.is_live ? ' · recording now' : ''}
                </Text>
                {isActive ? (
                  <Text style={styles.progress}>
                    {r.layout === 'segments' && r.segments.length > 1 ? `Part ${active!.part + 1} of ${r.segments.length} · ` : ''}
                    {fmt(status.currentTime)} / {fmt(status.duration || 0)}
                  </Text>
                ) : null}
              </View>
            </View>
            <Text style={styles.meta}>{r.legal_hold ? 'Kept for an investigation' : r.expires_at ? `Deleted after ${when(r.expires_at)}` : ''}</Text>
            {r.sha256 ? <Text style={styles.hash} selectable>SHA-256 {r.sha256}</Text> : null}
            {r.download_url ? (
              <Pressable onPress={() => Linking.openURL(r.download_url!)} style={styles.dl}>
                <Ionicons name="download-outline" size={16} color={pa.info} />
                <Text style={styles.dlText}>Download</Text>
              </Pressable>
            ) : null}
          </View>
        );
      })}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: pa.paper },
  h1: { fontFamily: paFonts.black, fontSize: 22, color: pa.ink },
  muted: { fontFamily: paFonts.regular, fontSize: 13, color: pa.muted, marginTop: 2 },
  note: { fontFamily: paFonts.regular, fontSize: 13, lineHeight: 19, color: pa.inkSoft, backgroundColor: pa.beaconWash, borderColor: pa.beaconLine, borderWidth: 1, borderRadius: 10, padding: 12, marginVertical: 12 },
  err: { fontFamily: paFonts.regular, color: pa.signalInk, marginBottom: 8 },
  empty: { fontFamily: paFonts.regular, color: pa.muted, textAlign: 'center', paddingVertical: 24 },
  item: { backgroundColor: pa.surface, borderColor: pa.line, borderWidth: 1, borderRadius: 12, padding: 12, marginBottom: 10 },
  row: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  play: { width: 44, height: 44, borderRadius: 22, backgroundColor: pa.ink, alignItems: 'center', justifyContent: 'center' },
  title: { fontFamily: paFonts.black, fontSize: 15, color: pa.ink },
  progress: { fontFamily: paFonts.bold, fontSize: 13, color: pa.signal, marginTop: 2 },
  meta: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted, marginTop: 8 },
  hash: { fontFamily: paFonts.regular, fontSize: 11, color: pa.muted2, marginTop: 4 },
  dl: { flexDirection: 'row', alignItems: 'center', gap: 6, marginTop: 8 },
  dlText: { fontFamily: paFonts.bold, fontSize: 14, color: pa.info },
});
