import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Alert, Linking, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { api, apiErrorMessage } from '../../api/client';
import { Appointment } from '../../api/types';
import { PaButton, PaCard, PaInput, PaSectionLabel } from '../../components/pa';
import { pa, paFonts, paRadius, type } from '../../theme';
import { telUrl, useSiteContent, whatsappUrl } from '../../services/siteContent';

/**
 * "Get connected to a Doctor in 30min" (PromptAid Emergency Scale, orange).
 * POST /request-doctor/instant broadcasts to every GP flagged green; the
 * first GP to accept is booked with the patient 30 minutes later. This
 * screen then polls the appointment until a GP has accepted.
 */
export default function InstantConsultScreen({ navigation }: any) {
  const { emergency } = useSiteContent();
  const [mode, setMode] = useState<'video' | 'in_person'>('video');
  const [reason, setReason] = useState('');
  const [sending, setSending] = useState(false);
  const [request, setRequest] = useState<Appointment | null>(null);
  const [noGp, setNoGp] = useState<string | null>(null);
  const timer = useRef<ReturnType<typeof setInterval> | null>(null);

  const poll = useCallback(async (id: number) => {
    try {
      const { data } = await api.get(`/appointments/${id}`);
      setRequest(data.data);
      if (data.data.status !== 'matching' && timer.current) clearInterval(timer.current);
    } catch {
      /* keep polling */
    }
  }, []);

  useEffect(() => () => {
    if (timer.current) clearInterval(timer.current);
  }, []);

  async function send() {
    setSending(true);
    setNoGp(null);
    try {
      const { data } = await api.post('/request-doctor/instant', { mode, reason: reason || undefined });
      setRequest(data.data);
      timer.current = setInterval(() => poll(data.data.id), 10000);
    } catch (e: any) {
      if (e?.response?.status === 409) setNoGp(apiErrorMessage(e));
      else Alert.alert('Could not send request', apiErrorMessage(e));
    } finally {
      setSending(false);
    }
  }

  return (
    <ScrollView style={styles.container} contentContainerStyle={{ padding: 16, paddingBottom: 40 }}>
      <Text style={styles.eyebrow}>{emergency.eyebrow} · Orange</Text>
      <Text style={styles.title}>Get connected to a doctor in 30 minutes</Text>
      <Text style={styles.sub}>
        We send your request to every GP flagged <Text style={{ color: '#1F7A4C', fontFamily: paFonts.black }}>green</Text> right now. The first GP to accept is booked with you 30 minutes later.
      </Text>

      <Pressable onPress={() => Linking.openURL(telUrl(emergency.dispatch_phone))} style={styles.stop}>
        <Text style={styles.stopText}>Not breathing, chest pain or heavy bleeding? Call dispatch: {emergency.dispatch_phone}</Text>
      </Pressable>

      {request && request.status === 'matching' ? (
        <PaCard>
          <Text style={styles.cardTitle}>Waiting for a GP to accept…</Text>
          <Text style={styles.muted}>Request {request.booking_ref} · {request.mode === 'video' ? 'Video consult' : 'In person'}. This updates automatically.</Text>
        </PaCard>
      ) : request && request.doctor ? (
        <PaCard style={{ borderLeftWidth: 6, borderLeftColor: pa.go }}>
          <Text style={[styles.cardTitle, { color: pa.go }]}>Booked · {request.booking_ref}</Text>
          <Text style={styles.cardTitle}>{request.doctor.name} will see you at {request.start_time?.slice(0, 5)}</Text>
          <Text style={styles.muted}>{request.mode === 'video' ? 'Video consult — join from your appointments.' : `In person${request.clinic ? ` at ${request.clinic.name}` : ''}.`}</Text>
          <PaButton title="My appointments" onPress={() => navigation.getParent()?.navigate('Profile', { screen: 'Appointments' })} style={{ marginTop: 12 }} />
        </PaCard>
      ) : request ? (
        <PaCard><Text style={styles.muted}>This request is {request.status}.</Text></PaCard>
      ) : noGp ? (
        <PaCard>
          <Text style={styles.cardTitle}>No GP is available within 30 minutes right now</Text>
          <Text style={[styles.muted, { marginBottom: 12 }]}>{noGp}</Text>
          <PaButton title="Book a doctor for today" onPress={() => navigation.getParent()?.navigate('Care', { screen: 'FindCare', params: { group: 'gp' } })} />
          <Pressable onPress={() => Linking.openURL(whatsappUrl(emergency.whatsapp_number))} style={styles.wa}>
            <Text style={styles.waText}>💬  {emergency.ask_doctor_label}</Text>
          </Pressable>
        </PaCard>
      ) : (
        <>
          <PaSectionLabel style={{ marginBottom: 8 }}>How would you like to be seen?</PaSectionLabel>
          <View style={styles.modeRow}>
            {(['video', 'in_person'] as const).map((m) => (
              <Pressable key={m} onPress={() => setMode(m)} style={[styles.mode, mode === m && styles.modeOn]}>
                <Text style={[styles.modeText, mode === m && { color: '#fff' }]}>{m === 'video' ? 'Video consult' : 'In person'}</Text>
              </Pressable>
            ))}
          </View>
          <PaInput label="What's wrong? (optional)" value={reason} onChangeText={setReason} placeholder="e.g. Severe pain since this morning" multiline />
          <PaButton title="Request a GP now" onPress={send} loading={sending} />
        </>
      )}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper },
  eyebrow: { ...type.label, color: '#B4520F', marginBottom: 6 },
  title: { ...type.h2, color: pa.ink, marginBottom: 6 },
  sub: { fontFamily: paFonts.regular, fontSize: 14, color: pa.inkSoft, lineHeight: 20, marginBottom: 14 },
  stop: { backgroundColor: '#FBEAEA', borderWidth: 1, borderColor: '#F2C4C4', padding: 12, marginBottom: 16 },
  stopText: { fontFamily: paFonts.bold, fontSize: 13, color: pa.sats.red },
  cardTitle: { fontFamily: paFonts.black, fontSize: 16, color: pa.ink, marginBottom: 6 },
  muted: { fontFamily: paFonts.regular, fontSize: 13, color: pa.muted },
  modeRow: { flexDirection: 'row', gap: 8, marginBottom: 14 },
  mode: { flex: 1, borderWidth: 1, borderColor: pa.line, borderRadius: paRadius.sm, paddingVertical: 12, alignItems: 'center', backgroundColor: pa.surface },
  modeOn: { backgroundColor: pa.ink, borderColor: pa.ink },
  modeText: { fontFamily: paFonts.bold, fontSize: 14, color: pa.ink },
  wa: { marginTop: 10, backgroundColor: '#1FA855', borderRadius: paRadius.sm, paddingVertical: 14, alignItems: 'center' },
  waText: { fontFamily: paFonts.black, fontSize: 15, color: '#fff' },
});
