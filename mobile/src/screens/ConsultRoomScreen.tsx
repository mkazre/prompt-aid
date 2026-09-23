import React, { useLayoutEffect, useMemo } from 'react';
import { Alert, Linking, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useNavigation, useRoute } from '@react-navigation/native';
import { Appointment } from '../api/types';
import { PaButton } from '../components/pa';
import { pa, paFonts, type } from '../theme';
import { useAuth } from '../context/AuthContext';

/**
 * Video consult pre-call screen — shared between the doctor and patient
 * flows (mirrors new-ui/promptaid-mobile/consult-room.html, which is filed
 * under the "patient" mockup stack but is the same screen either side joins
 * from). Doctors reach it from "My day" / the calendar when an appointment's
 * `mode` is 'video'; patients would reach it from their appointments list.
 *
 * `meet_url` isn't serialized by AppointmentResource yet (the appointments
 * table has the column — see the video-fields migration — but the API
 * doesn't expose it), so this screen has to degrade to "no link yet" rather
 * than invent one. Join uses Linking.openURL, not a WebView, per the
 * mockup's own Expo note: a WebView doesn't reliably get camera/mic
 * permission for Meet.
 */
export default function ConsultRoomScreen() {
  const navigation = useNavigation<any>();
  const { user } = useAuth();
  const { appointment } = useRoute<any>().params as { appointment: Appointment };

  const counterpartName = user?.role === 'doctor' ? appointment.patient?.name ?? 'Patient' : appointment.doctor?.name ?? 'Doctor';

  useLayoutEffect(() => {
    navigation.setOptions({ title: 'Video consult' });
  }, [navigation]);

  const { canJoin, opensLabel } = useMemo(() => {
    if (!appointment.date || !appointment.start_time || !appointment.end_time) {
      return { canJoin: false, opensLabel: null as string | null };
    }
    const start = new Date(`${appointment.date}T${appointment.start_time}`);
    const end = new Date(`${appointment.date}T${appointment.end_time}`);
    const windowStart = new Date(start.getTime() - 10 * 60 * 1000);
    const windowEnd = new Date(end.getTime() + 30 * 60 * 1000);
    const now = new Date();

    if (now < windowStart) {
      const mins = Math.round((windowStart.getTime() - now.getTime()) / 60000);
      return { canJoin: false, opensLabel: mins > 60 ? `Opens ${start.toLocaleDateString('en-ZA', { weekday: 'short', day: '2-digit', month: 'short' })}` : `Opens in ${mins} minute${mins === 1 ? '' : 's'}` };
    }
    if (now > windowEnd) {
      return { canJoin: false, opensLabel: 'This consult has ended' };
    }
    return { canJoin: true, opensLabel: 'Ready to join' };
  }, [appointment.date, appointment.start_time, appointment.end_time]);

  async function join() {
    if (!appointment.meet_url) {
      Alert.alert('No video link yet', 'This appointment does not have a Google Meet link attached yet.');
      return;
    }
    const supported = await Linking.canOpenURL(appointment.meet_url);
    if (!supported) {
      Alert.alert('Could not open link', 'Your device cannot open this video link.');
      return;
    }
    Linking.openURL(appointment.meet_url);
  }

  return (
    <ScrollView style={styles.container} contentContainerStyle={{ padding: 20, paddingBottom: 48 }}>
      <View style={styles.eyebrowRow}>
        <View style={styles.blip} />
        <Text style={styles.eyebrow}>{opensLabel ?? 'Video consult'}</Text>
      </View>

      <Text style={styles.name}>{counterpartName}</Text>
      <Text style={styles.meta}>
        {appointment.date ? new Date(appointment.date).toLocaleDateString('en-ZA', { weekday: 'short', day: '2-digit', month: 'short' }) : '—'}
        {' · '}
        {appointment.start_time?.slice(0, 5)}
        {appointment.reason ? ` · ${appointment.reason}` : ''}
      </Text>

      <View style={styles.preview}>
        <Text style={styles.previewText}>Camera preview</Text>
      </View>

      <InfoRow label="Camera & microphone" value="Requested by Google Meet on join" />
      <InfoRow label="Link" value={appointment.meet_url ? 'Attached' : 'Not attached yet'} />

      <View style={{ marginTop: 18 }}>
        <PaButton
          title={appointment.meet_url ? (canJoin ? 'Join on Google Meet' : 'Join on Google Meet') : 'No video link yet'}
          variant="beacon"
          disabled={!appointment.meet_url}
          onPress={join}
        />
      </View>
      <Text style={styles.footnote}>Opens the Meet app or browser. Camera and microphone permissions are handled by Google Meet.</Text>
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
  preview: { backgroundColor: '#1C1C20', height: 220, alignItems: 'center', justifyContent: 'center', marginBottom: 14 },
  previewText: { fontFamily: paFonts.regular, fontSize: 12, color: '#6E6A62' },
  infoRow: { flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 10, borderBottomWidth: 1, borderBottomColor: '#2A2A2E' },
  infoLabel: { fontFamily: paFonts.regular, fontSize: 13, color: '#8A857C' },
  infoValue: { fontFamily: paFonts.bold, fontSize: 13, color: '#7FD1A4' },
  footnote: { fontFamily: paFonts.regular, fontSize: 12, color: '#8A857C', textAlign: 'center', marginTop: 10 },
});
