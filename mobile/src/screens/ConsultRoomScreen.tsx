import React, { useEffect, useLayoutEffect, useMemo, useState } from 'react';
import { ScrollView, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useNavigation, useRoute } from '@react-navigation/native';
import { Appointment } from '../api/types';
import { PaButton } from '../components/pa';
import { pa, paFonts, type } from '../theme';
import { useAuth } from '../context/AuthContext';

/**
 * Video consult pre-call lobby — shared between the doctor and patient
 * flows. Shows when the room opens (10 min before start → 30 min after the
 * end, the same window the server enforces in
 * GET /api/appointments/{id}/video) and routes "Join" into the in-app
 * VideoCall screen (root stack). "Message" opens the appointment's chat.
 */
export default function ConsultRoomScreen() {
  const insets = useSafeAreaInsets();
  const navigation = useNavigation<any>();
  const { user } = useAuth();
  const { appointment } = useRoute<any>().params as { appointment: Appointment };
  const [now, setNow] = useState(() => new Date());

  const counterpartName = user?.role === 'doctor' ? appointment.patient?.name ?? 'Patient' : appointment.doctor?.name ? `Dr. ${appointment.doctor.name}` : 'Doctor';

  useLayoutEffect(() => {
    navigation.setOptions({ title: 'Video consult' });
  }, [navigation]);

  // Re-evaluate the join window every 20s so the button unlocks on time.
  useEffect(() => {
    const t = setInterval(() => setNow(new Date()), 20000);
    return () => clearInterval(t);
  }, []);

  const { canJoin, opensLabel } = useMemo(() => {
    if (!appointment.date || !appointment.start_time) {
      return { canJoin: false, opensLabel: null as string | null };
    }
    const start = new Date(`${appointment.date}T${appointment.start_time}`);
    const end = new Date(`${appointment.date}T${appointment.end_time || appointment.start_time}`);
    const windowStart = new Date(start.getTime() - 10 * 60 * 1000);
    const windowEnd = new Date(end.getTime() + 30 * 60 * 1000);

    if (['cancelled', 'no_show'].includes(appointment.status)) {
      return { canJoin: false, opensLabel: 'This consult was cancelled' };
    }
    if (now < windowStart) {
      const mins = Math.round((windowStart.getTime() - now.getTime()) / 60000);
      return { canJoin: false, opensLabel: mins > 60 ? `Opens ${start.toLocaleDateString('en-ZA', { weekday: 'short', day: '2-digit', month: 'short' })}` : `Opens in ${mins} minute${mins === 1 ? '' : 's'}` };
    }
    if (now > windowEnd) {
      return { canJoin: false, opensLabel: 'This consult has ended' };
    }
    return { canJoin: true, opensLabel: 'Ready to join' };
  }, [appointment.date, appointment.start_time, appointment.end_time, appointment.status, now]);

  function join(audioOnly = false) {
    navigation.navigate('VideoCall', { appointmentId: appointment.id, audioOnly, name: counterpartName });
  }

  return (
    <ScrollView style={styles.container} contentContainerStyle={{ padding: 20, paddingTop: insets.top + 20, paddingBottom: 48 }}>
      <View style={styles.eyebrowRow}>
        <View style={styles.blip} />
        <Text style={styles.eyebrow}>{opensLabel ?? 'Video consult'}</Text>
      </View>

      <Text style={styles.name}>{counterpartName}</Text>
      <Text style={styles.meta}>
        {appointment.date ? new Date(`${appointment.date}T00:00:00`).toLocaleDateString('en-ZA', { weekday: 'short', day: '2-digit', month: 'short' }) : '—'}
        {' · '}
        {appointment.start_time?.slice(0, 5)}
        {appointment.reason ? ` · ${appointment.reason}` : ''}
      </Text>

      <InfoRow label="Room" value="Private, created for this appointment" />
      <InfoRow label="Camera & microphone" value="Requested when you join" />
      <InfoRow label="Opens" value="10 min before · closes 30 min after" />

      <View style={{ marginTop: 18, gap: 10 }}>
        <PaButton title={canJoin ? 'Join video consult' : opensLabel ?? 'Not open yet'} variant="beacon" disabled={!canJoin} onPress={() => join(false)} />
        <PaButton title="Join with audio only" variant="dark-ghost" disabled={!canJoin} onPress={() => join(true)} />
        <PaButton title="Message" variant="dark-ghost" onPress={() => navigation.navigate('Chat', { appointmentId: appointment.id, title: counterpartName })} />
      </View>
      <Text style={styles.footnote}>The call runs inside Prompt Aid. You'll be asked to allow camera and microphone access the first time.</Text>
    </ScrollView>
  );
}

function InfoRow({ label, value }: { label: string; value: string }) {
  return (
    <View style={styles.infoRow}>
      <Text style={styles.infoLabel}>{label}</Text>
      <Text style={styles.infoValue}>{value}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.ink },
  eyebrowRow: { flexDirection: 'row', alignItems: 'center', gap: 8, marginBottom: 14 },
  blip: { width: 7, height: 7, borderRadius: 4, backgroundColor: pa.beacon },
  eyebrow: { ...type.label, color: pa.beacon },
  name: { ...type.h1, color: '#fff', marginBottom: 4 },
  meta: { fontFamily: paFonts.regular, fontSize: 13, color: '#8A857C', marginBottom: 18 },
  infoRow: { flexDirection: 'row', justifyContent: 'space-between', gap: 12, paddingVertical: 10, borderBottomWidth: 1, borderBottomColor: '#2A2A2E' },
  infoLabel: { fontFamily: paFonts.regular, fontSize: 13, color: '#8A857C' },
  infoValue: { fontFamily: paFonts.bold, fontSize: 13, color: '#7FD1A4', flexShrink: 1, textAlign: 'right' },
  footnote: { fontFamily: paFonts.regular, fontSize: 12, color: '#8A857C', textAlign: 'center', marginTop: 12 },
});
