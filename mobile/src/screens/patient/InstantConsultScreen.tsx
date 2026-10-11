import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Alert, Linking, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import * as Location from 'expo-location';
import { useFocusEffect } from '@react-navigation/native';
import { api, apiErrorMessage } from '../../api/client';
import { PaButton, PaCard, PaSectionLabel } from '../../components/pa';
import { pa, paFonts, paRadius, type } from '../../theme';
import { telUrl, useSiteContent, whatsappUrl } from '../../services/siteContent';

/** Shape of DoctorMatchService::patientPayload() (GET /doctor-match/current). */
interface MatchPayload {
  id: number;
  status: 'searching' | 'matched' | 'expired' | 'cancelled';
  booking_ref: string | null;
  specialty: string;
  mode: 'video' | 'in_person';
  price: number;
  seconds_left: number;
  online_doctors: number;
  outcome_message: string | null;
  doctor: { name: string; specialization: string } | null;
  clinic: { name: string; address: string | null; directions_url?: string } | null;
  video_url: string | null;
  can_join_video: boolean;
  can_cancel: boolean;
  cancel_fee_now: number;
}

/**
 * "Get connected to a Doctor in 30min" (PromptAid Emergency Scale, orange).
 * Uses the doctor-now engine: POST /request-doctor offers the request to the
 * best available doctor in the speciality, moving on to the next if they
 * don't accept in time. This screen polls GET /doctor-match/current.
 */
export default function InstantConsultScreen({ navigation }: any) {
  const { emergency } = useSiteContent();
  const [specialties, setSpecialties] = useState<string[]>([]);
  const [specialty, setSpecialty] = useState<string | null>(null);
  const [mode, setMode] = useState<'video' | 'in_person'>('video');
  const [match, setMatch] = useState<MatchPayload | null>(null);
  const [sending, setSending] = useState(false);
  const [cancelling, setCancelling] = useState(false);
  const timer = useRef<ReturnType<typeof setInterval> | null>(null);

  const refresh = useCallback(async () => {
    try {
      const { data } = await api.get('/doctor-match/current');
      setMatch(data.data ?? null);
    } catch {
      /* keep last state */
    }
  }, []);

  useEffect(() => {
    api.get('/doctor-match/specialties').then(({ data }) => {
      const list: string[] = data.data ?? [];
      setSpecialties(list);
      setSpecialty((cur) => cur ?? list.find((s) => /general/i.test(s)) ?? list[0] ?? null);
    }).catch(() => {});
  }, []);

  useFocusEffect(useCallback(() => {
    refresh();
    timer.current = setInterval(refresh, 5000);
    return () => {
      if (timer.current) clearInterval(timer.current);
    };
  }, [refresh]));

  async function send() {
    if (!specialty) return;
    setSending(true);
    try {
      const body: Record<string, unknown> = { specialty, mode };
      if (mode === 'in_person') {
        const perm = await Location.requestForegroundPermissionsAsync();
        if (!perm.granted) {
          Alert.alert('Location needed', 'To see a doctor in person we need your location to find the nearest one. You can choose a video consult instead.');
          return;
        }
        const pos = await Location.getCurrentPositionAsync({});
        body.lat = pos.coords.latitude;
        body.lng = pos.coords.longitude;
      }
      const { data } = await api.post('/request-doctor', body);
      setMatch(data.match ?? null);
    } catch (e: any) {
      if (e?.response?.data?.match) setMatch(e.response.data.match);
      Alert.alert('Could not request a doctor', apiErrorMessage(e));
    } finally {
      setSending(false);
    }
  }

  function cancel() {
    if (!match) return;
    const fee = match.cancel_fee_now > 0 ? ` A cancellation fee of R ${Math.round(match.cancel_fee_now)} applies.` : '';
    Alert.alert('Cancel this request?', `The doctor will be released.${fee}`, [
      { text: 'Keep it', style: 'cancel' },
      {
        text: 'Cancel request',
        style: 'destructive',
        onPress: async () => {
          setCancelling(true);
          try {
            const { data } = await api.post(`/doctor-match/${match.id}/cancel`, {});
            setMatch(data.data ?? null);
          } catch (e) {
            Alert.alert('Could not cancel', apiErrorMessage(e));
          } finally {
            setCancelling(false);
          }
        },
      },
    ]);
  }

  const active = match && (match.status === 'searching' || match.status === 'matched');

  return (
    <ScrollView style={styles.container} contentContainerStyle={{ padding: 16, paddingBottom: 40 }}>
      <View style={styles.banner}>
        <Text style={styles.bannerEyebrow}>{emergency.eyebrow} · Orange</Text>
        <Text style={styles.bannerTitle}>Get connected to a doctor now</Text>
        <Text style={styles.bannerText}>Your request goes to the best available doctor flagged green. If they can't take it, it moves to the next one automatically.</Text>
      </View>

      <Pressable onPress={() => Linking.openURL(telUrl(emergency.dispatch_phone))} style={styles.stop}>
        <Text style={styles.stopText}>Not breathing, chest pain or heavy bleeding? Call dispatch: {emergency.dispatch_phone}</Text>
      </Pressable>

      {active && match.status === 'searching' ? (
        <PaCard>
          <Text style={styles.cardTitle}>Finding you a doctor…</Text>
          <Text style={styles.muted}>
            {match.specialty} · {match.mode === 'video' ? 'Video' : 'In person'} · {match.online_doctors} online
            {match.seconds_left ? ` · ${Math.ceil(match.seconds_left / 60)} min left` : ''}
          </Text>
          {match.can_cancel ? <PaButton title="Cancel request" variant="ghost" onPress={cancel} loading={cancelling} style={{ marginTop: 12 }} /> : null}
        </PaCard>
      ) : active && match.status === 'matched' ? (
        <PaCard style={{ borderLeftWidth: 6, borderLeftColor: pa.go }}>
          <Text style={[styles.cardTitle, { color: pa.go }]}>Matched{match.booking_ref ? ` · ${match.booking_ref}` : ''}</Text>
          <Text style={styles.cardTitle}>{match.doctor?.name ?? 'Your doctor'}</Text>
          <Text style={styles.muted}>
            {match.mode === 'video' ? 'Video consult' : `In person${match.clinic ? ` at ${match.clinic.name}${match.clinic.address ? `, ${match.clinic.address}` : ''}` : ''}`}
          </Text>
          {match.video_url ? <PaButton title={match.can_join_video ? 'Join video now' : 'Open video room'} onPress={() => Linking.openURL(match.video_url!)} style={{ marginTop: 12 }} /> : null}
          {match.mode === 'in_person' && match.clinic?.directions_url ? (
            <PaButton title="Directions" variant="ghost" onPress={() => Linking.openURL(match.clinic!.directions_url!)} style={{ marginTop: 8 }} />
          ) : null}
          <PaButton title="My appointments" variant="ghost" onPress={() => navigation.getParent()?.navigate('Profile', { screen: 'Appointments' })} style={{ marginTop: 8 }} />
          {match.can_cancel ? <PaButton title="Cancel" variant="ghost" onPress={cancel} loading={cancelling} style={{ marginTop: 8 }} /> : null}
        </PaCard>
      ) : (
        <>
          {match && (match.status === 'expired' || match.status === 'cancelled') && match.outcome_message ? (
            <PaCard style={{ marginBottom: 14 }}>
              <Text style={styles.muted}>{match.outcome_message}</Text>
              <Pressable onPress={() => Linking.openURL(whatsappUrl(emergency.whatsapp_number))} style={styles.wa}>
                <Text style={styles.waText}>💬  {emergency.ask_doctor_label}</Text>
              </Pressable>
            </PaCard>
          ) : null}

          <PaSectionLabel style={{ marginBottom: 8 }}>Who do you need?</PaSectionLabel>
          <View style={styles.chipRow}>
            {specialties.map((s) => (
              <Pressable key={s} onPress={() => setSpecialty(s)} style={[styles.chip, specialty === s && styles.chipOn]}>
                <Text style={[styles.chipText, specialty === s && { color: '#fff' }]}>{s}</Text>
              </Pressable>
            ))}
          </View>

          <PaSectionLabel style={{ marginBottom: 8 }}>How would you like to be seen?</PaSectionLabel>
          <View style={styles.modeRow}>
            {(['video', 'in_person'] as const).map((m) => (
              <Pressable key={m} onPress={() => setMode(m)} style={[styles.mode, mode === m && styles.modeOn]}>
                <Text style={[styles.modeText, mode === m && { color: '#fff' }]}>{m === 'video' ? 'Video consult' : 'In person'}</Text>
              </Pressable>
            ))}
          </View>
          <PaButton title="Request a doctor now" onPress={send} loading={sending} disabled={!specialty} />
        </>
      )}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper },
  banner: { backgroundColor: '#E4701E', borderRadius: 14, padding: 16, marginBottom: 12 },
  bannerEyebrow: { ...type.label, color: '#FFE7D3', marginBottom: 6 },
  bannerTitle: { fontFamily: paFonts.black, fontSize: 20, color: '#fff', marginBottom: 6 },
  bannerText: { fontFamily: paFonts.regular, fontSize: 13, color: '#FFF3EA', lineHeight: 19 },
  stop: { backgroundColor: '#FBEAEA', borderWidth: 1, borderColor: '#F2C4C4', padding: 12, marginBottom: 16, borderRadius: 10 },
  stopText: { fontFamily: paFonts.bold, fontSize: 13, color: pa.sats.red },
  cardTitle: { fontFamily: paFonts.black, fontSize: 16, color: pa.ink, marginBottom: 6 },
  muted: { fontFamily: paFonts.regular, fontSize: 13, color: pa.muted },
  chipRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginBottom: 14 },
  chip: { borderWidth: 1, borderColor: pa.line, borderRadius: 999, paddingHorizontal: 12, paddingVertical: 8, backgroundColor: pa.surface },
  chipOn: { backgroundColor: pa.ink, borderColor: pa.ink },
  chipText: { fontFamily: paFonts.bold, fontSize: 12, color: pa.ink },
  modeRow: { flexDirection: 'row', gap: 8, marginBottom: 14 },
  mode: { flex: 1, borderWidth: 1, borderColor: pa.line, borderRadius: paRadius.sm, paddingVertical: 12, alignItems: 'center', backgroundColor: pa.surface },
  modeOn: { backgroundColor: pa.ink, borderColor: pa.ink },
  modeText: { fontFamily: paFonts.bold, fontSize: 14, color: pa.ink },
  wa: { marginTop: 10, backgroundColor: '#1FA855', borderRadius: paRadius.sm, paddingVertical: 14, alignItems: 'center' },
  waText: { fontFamily: paFonts.black, fontSize: 15, color: '#fff' },
});
