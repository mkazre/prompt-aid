import React, { useEffect, useLayoutEffect, useMemo, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { StatusBar } from 'expo-status-bar';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useNavigation, useRoute } from '@react-navigation/native';
import { Appointment } from '../api/types';
import { PaButton } from '../components/pa';
import { pa, paFonts, type } from '../theme';
import { useAuth } from '../context/AuthContext';

/** Appointment dates/times are South African clinic time (UTC+2, no DST), same as the server. */
function clinicTime(date: string, time: string) {
  return new Date(`${date}T${time.length === 5 ? `${time}:00` : time}+02:00`);
}

/**
 * Video consult pre-call lobby — shared between the doctor and patient
 * flows. Shows when the room opens (10 min before start → 30 min after the
 * end, the same window the server enforces in
 * GET /api/appointments/{id}/video) and routes "Join" into the in-app
 * VideoCall screen (root stack). The call signs in automatically — no
 * Jitsi login. "Message" opens the appointment's chat.
 */
export default function ConsultRoomScreen() {
  const insets = useSafeAreaInsets();
  const navigation = useNavigation<any>();
  const { user } = useAuth();
  const { appointment } = useRoute<any>().params as { appointment: Appointment };
  const [now, setNow] = useState(() => new Date());

  const isDoctor = user?.role === 'doctor';
  const counterpartName = isDoctor ? appointment.patient?.name ?? 'Patient' : appointment.doctor?.name ? `Dr. ${appointment.doctor.name}` : 'Doctor';

  // Same look for doctor and patient stacks: our own top bar instead of the stack header.
  useLayoutEffect(() => {
    navigation.setOptions({ headerShown: false, title: 'Video consult' });
  }, [navigation]);

  // Re-evaluate the join window every 20s so the button unlocks on time.
  useEffect(() => {
    const t = setInterval(() => setNow(new Date()), 20000);
    return () => clearInterval(t);
  }, []);

  const { canJoin, opensLabel, state } = useMemo(() => {
    if (!appointment.date || !appointment.start_time) {
      return { canJoin: false, opensLabel: null as string | null, state: 'idle' as const };
    }
    const start = clinicTime(appointment.date, appointment.start_time);
    const end = clinicTime(appointment.date, appointment.end_time || appointment.start_time);
    const windowStart = new Date(start.getTime() - 10 * 60 * 1000);
    const windowEnd = new Date(end.getTime() + 30 * 60 * 1000);

    if (['cancelled', 'no_show'].includes(appointment.status)) {
      return { canJoin: false, opensLabel: 'This consult was cancelled', state: 'closed' as const };
    }
    if (now < windowStart) {
      const mins = Math.max(1, Math.round((windowStart.getTime() - now.getTime()) / 60000));
      return {
        canJoin: false,
        opensLabel: mins > 60 ? `Opens ${start.toLocaleDateString('en-ZA', { weekday: 'short', day: '2-digit', month: 'short' })}` : `Opens in ${mins} minute${mins === 1 ? '' : 's'}`,
        state: 'waiting' as const,
      };
    }
    if (now > windowEnd) {
      return { canJoin: false, opensLabel: 'This consult has ended', state: 'closed' as const };
    }
    return { canJoin: true, opensLabel: 'Room open — ready to join', state: 'open' as const };
  }, [appointment.date, appointment.start_time, appointment.end_time, appointment.status, now]);

  function join(audioOnly = false) {
    navigation.navigate('VideoCall', { appointmentId: appointment.id, audioOnly, name: counterpartName });
  }

  const dateLabel = appointment.date ? new Date(`${appointment.date}T12:00:00+02:00`).toLocaleDateString('en-ZA', { weekday: 'short', day: '2-digit', month: 'short' }) : '—';
  const initials = counterpartName.replace(/^Dr\.\s*/, '').split(/\s+/).map((w) => w[0]).filter(Boolean).slice(0, 2).join('').toUpperCase();

  return (
    <View style={styles.container}>
      <StatusBar style="light" />
      <View style={[styles.topBar, { paddingTop: insets.top + 6, paddingLeft: insets.left + 12, paddingRight: insets.right + 12 }]}>
        <Pressable onPress={() => navigation.goBack()} hitSlop={10} style={styles.backBtn} accessibilityRole="button" accessibilityLabel="Back">
          <Ionicons name="chevron-back" size={22} color="#fff" />
        </Pressable>
        <Text style={styles.topTitle}>Video consult</Text>
        <View style={{ width: 40 }} />
      </View>

      <ScrollView contentContainerStyle={{ paddingHorizontal: 20, paddingTop: 8, paddingBottom: insets.bottom + 32 }}>
        <View style={styles.hero}>
          <View style={styles.avatar}>
            <Text style={styles.avatarText}>{initials || '?'}</Text>
          </View>
          <Text style={styles.name} numberOfLines={2}>
            {counterpartName}
          </Text>
          <Text style={styles.meta}>
            {dateLabel} · {appointment.start_time?.slice(0, 5) ?? '—'}
            {appointment.reason ? ` · ${appointment.reason}` : ''}
          </Text>
          <View style={[styles.statePill, state === 'open' ? styles.stateOpen : state === 'closed' ? styles.stateClosed : null]}>
            <View style={[styles.blip, state === 'open' ? { backgroundColor: '#34C27A' } : state === 'closed' ? { backgroundColor: '#8A857C' } : null]} />
            <Text style={styles.stateText}>{opensLabel ?? 'Video consult'}</Text>
          </View>
        </View>

        <View style={styles.card}>
          <InfoRow icon="lock-closed-outline" label="Private room" value="Created for this appointment only" />
          <InfoRow icon="person-circle-outline" label="Sign-in" value="Automatic — no extra login" />
          <InfoRow icon="mic-outline" label="Camera & microphone" value="Asked the first time you join" />
          <InfoRow icon="time-outline" label="Room opens" value="10 min before · closes 30 min after" last />
        </View>

        <View style={{ marginTop: 20, gap: 10 }}>
          <PaButton title={canJoin ? 'Join video consult' : opensLabel ?? 'Not open yet'} variant="beacon" disabled={!canJoin} onPress={() => join(false)} />
          <PaButton title="Join with audio only" variant="dark-ghost" disabled={!canJoin} onPress={() => join(true)} />
          <PaButton title={isDoctor ? 'Message patient' : 'Message doctor'} variant="dark-ghost" onPress={() => navigation.navigate('Chat', { appointmentId: appointment.id, title: counterpartName })} />
        </View>
        <Text style={styles.footnote}>The call runs inside Prompt Aid — you're signed in automatically.</Text>
      </ScrollView>
    </View>
  );
}

function InfoRow({ icon, label, value, last }: { icon: keyof typeof Ionicons.glyphMap; label: string; value: string; last?: boolean }) {
  return (
    <View style={[styles.infoRow, last && { borderBottomWidth: 0 }]}>
      <Ionicons name={icon} size={18} color="#8A857C" />
      <View style={{ flex: 1 }}>
        <Text style={styles.infoLabel}>{label}</Text>
        <Text style={styles.infoValue}>{value}</Text>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.ink },
  topBar: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingBottom: 8 },
  backBtn: { width: 40, height: 40, borderRadius: 20, alignItems: 'center', justifyContent: 'center', backgroundColor: 'rgba(255,255,255,0.08)' },
  topTitle: { fontFamily: paFonts.bold, fontSize: 16, color: '#fff' },
  hero: { alignItems: 'center', paddingTop: 18, paddingBottom: 22 },
  avatar: { width: 84, height: 84, borderRadius: 42, backgroundColor: '#2A2A2E', alignItems: 'center', justifyContent: 'center', marginBottom: 14 },
  avatarText: { fontFamily: paFonts.bold, fontSize: 28, color: '#fff' },
  name: { ...type.h1, color: '#fff', textAlign: 'center', marginBottom: 4 },
  meta: { fontFamily: paFonts.regular, fontSize: 13, color: '#8A857C', textAlign: 'center', marginBottom: 14 },
  statePill: { flexDirection: 'row', alignItems: 'center', gap: 8, paddingHorizontal: 14, paddingVertical: 7, borderRadius: 16, backgroundColor: 'rgba(242,194,0,0.12)' },
  stateOpen: { backgroundColor: 'rgba(52,194,122,0.14)' },
  stateClosed: { backgroundColor: 'rgba(255,255,255,0.08)' },
  blip: { width: 7, height: 7, borderRadius: 4, backgroundColor: pa.beacon },
  stateText: { ...type.label, color: '#fff' },
  card: { backgroundColor: '#18181B', borderRadius: 16, paddingHorizontal: 16 },
  infoRow: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 12, borderBottomWidth: 1, borderBottomColor: '#2A2A2E' },
  infoLabel: { fontFamily: paFonts.regular, fontSize: 12, color: '#8A857C' },
  infoValue: { fontFamily: paFonts.bold, fontSize: 14, color: '#E5E5EA', marginTop: 1 },
  footnote: { fontFamily: paFonts.regular, fontSize: 12, color: '#8A857C', textAlign: 'center', marginTop: 14 },
});
