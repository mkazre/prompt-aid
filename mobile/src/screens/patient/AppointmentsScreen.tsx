import React, { useCallback, useMemo, useState } from 'react';
import { Alert, FlatList, Linking, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native';
import { Feather } from '@expo/vector-icons';
import { useFocusEffect, useNavigation } from '@react-navigation/native';
import { api, apiErrorMessage } from '../../api/client';
import { Appointment } from '../../api/types';
import { PaAvatar, PaEmptyState } from '../../components/pa';
import WidgetHost from '../../widgets/WidgetHost';
import { pa, paFonts } from '../../theme';

const ACTIVE = ['pending', 'confirmed', 'checked_in', 'matching'];

const STATUS: Record<string, { label: string; fg: string; bg: string }> = {
  pending: { label: 'Pending', fg: '#B4520F', bg: '#FCEEE2' },
  confirmed: { label: 'Confirmed', fg: '#1F7A4C', bg: '#E7F3EC' },
  checked_in: { label: 'Checked in', fg: '#1F5FBF', bg: '#E6EEFA' },
  matching: { label: 'Finding a doctor', fg: '#6A4C93', bg: '#F0EAF7' },
  completed: { label: 'Completed', fg: '#3A3A3E', bg: '#EFEBE2' },
  cancelled: { label: 'Cancelled', fg: '#C8102E', bg: '#FBEAEA' },
  no_show: { label: 'Missed', fg: '#C8102E', bg: '#FBEAEA' },
};

const CANCEL_REASONS = ['I feel better', 'I can’t make this time', 'I booked another doctor', 'Other'];

/**
 * My appointments — upcoming and past, with Cancel (with a reason), Message,
 * Join video, Add a shuttle and Book again. Cancelling goes through
 * POST /appointments/{id}/cancel, which applies the clinic's cancellation
 * rules and notifies the doctor.
 */
export default function AppointmentsScreen() {
  const navigation = useNavigation<any>();
  const [appointments, setAppointments] = useState<Appointment[]>([]);
  const [tab, setTab] = useState<'upcoming' | 'past'>('upcoming');
  const [loading, setLoading] = useState(false);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [refreshKey, setRefreshKey] = useState(0);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const { data } = await api.get('/appointments');
      setAppointments(data.data);
    } finally {
      setLoading(false);
    }
  }, []);

  useFocusEffect(useCallback(() => { load(); }, [load]));

  const today = new Date().toISOString().slice(0, 10);
  const list = useMemo(() => {
    const upcoming = appointments.filter((a) => ACTIVE.includes(a.status) && a.date >= today).sort((a, b) => (a.date + a.start_time).localeCompare(b.date + b.start_time));
    const past = appointments.filter((a) => !upcoming.includes(a)).sort((a, b) => (b.date + b.start_time).localeCompare(a.date + a.start_time));
    return tab === 'upcoming' ? upcoming : past;
  }, [appointments, tab, today]);

  function cancel(a: Appointment) {
    const run = async (reason: string) => {
      setBusyId(a.id);
      try {
        await api.post(`/appointments/${a.id}/cancel`, { reason });
        await load();
      } catch (e) {
        Alert.alert('Could not cancel', apiErrorMessage(e));
      } finally {
        setBusyId(null);
      }
    };
    Alert.alert(
      'Cancel this appointment?',
      `${a.doctor?.name ?? 'Your doctor'} · ${a.date} ${a.start_time?.slice(0, 5)}\nWhy are you cancelling?`,
      [...CANCEL_REASONS.map((r) => ({ text: r, onPress: () => run(r) })), { text: 'Keep it', style: 'cancel' as const }],
    );
  }

  async function addToCalendar(a: Appointment) {
    try {
      const { data } = await api.get(`/appointments/${a.id}/calendar-link`);
      await Linking.openURL(data.url);
    } catch (e) {
      Alert.alert('Could not add to calendar', apiErrorMessage(e));
    }
  }

  return (
    <View style={styles.container}>
      <FlatList
        data={list}
        keyExtractor={(a) => String(a.id)}
        refreshControl={<RefreshControl refreshing={loading} onRefresh={() => { load(); setRefreshKey((k) => k + 1); }} />}
        contentContainerStyle={{ padding: 16, paddingBottom: 48 }}
        ListHeaderComponent={
          <>
            <WidgetHost screen="appointments" refreshKey={refreshKey} style={{ marginBottom: 14 }} />
            <View style={styles.tabs}>
              {(['upcoming', 'past'] as const).map((t) => (
                <Pressable key={t} onPress={() => setTab(t)} style={[styles.tab, tab === t && styles.tabOn]}>
                  <Text style={[styles.tabText, tab === t && { color: '#fff' }]}>{t === 'upcoming' ? 'Upcoming' : 'Past'}</Text>
                </Pressable>
              ))}
            </View>
          </>
        }
        ListEmptyComponent={!loading ? (
          <View>
            <PaEmptyState message={tab === 'upcoming' ? 'No upcoming appointments.' : 'No past appointments yet.'} />
            {tab === 'upcoming' ? (
              <Pressable style={styles.bookBtn} onPress={() => navigation.navigate('Care', { screen: 'FindCare' })}>
                <Text style={styles.bookBtnText}>Book a consult</Text>
              </Pressable>
            ) : null}
          </View>
        ) : null}
        renderItem={({ item: a }) => {
          const st = STATUS[a.status] ?? STATUS.pending;
          const active = ACTIVE.includes(a.status);
          return (
            <View style={styles.card}>
              <View style={[styles.stripe, { backgroundColor: st.fg }]} />
              <View style={{ flex: 1, padding: 14 }}>
                <View style={styles.top}>
                  <PaAvatar name={a.doctor?.name ?? '?'} size={42} />
                  <View style={{ flex: 1 }}>
                    <Text style={styles.doctor} numberOfLines={1}>{a.doctor?.name ?? 'Finding your doctor…'}</Text>
                    <Text style={styles.meta} numberOfLines={1}>{a.is_video ? 'Video consult' : a.clinic?.name ?? 'In person'}</Text>
                  </View>
                  <View style={[styles.pill, { backgroundColor: st.bg }]}><Text style={[styles.pillText, { color: st.fg }]}>{st.label}</Text></View>
                </View>
                <View style={styles.when}>
                  <Feather name="calendar" size={14} color={pa.muted} />
                  <Text style={styles.whenText}>{new Date(a.date + 'T00:00:00').toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' })} · {a.start_time?.slice(0, 5)}</Text>
                  {a.booking_ref ? <Text style={styles.ref}>{a.booking_ref}</Text> : null}
                </View>
                <View style={styles.actions}>
                  {a.is_video && active ? (
                    <Chip icon="video" label={a.can_join_video ? 'Join video' : 'Video room'} live={!!a.can_join_video} onPress={() => navigation.navigate('Home', { screen: 'ConsultRoom', params: { appointment: a } })} />
                  ) : null}
                  {a.doctor && a.status !== 'matching' ? (
                    <Chip icon="message-circle" label="Message" onPress={() => navigation.navigate('Chat', { appointmentId: a.id, title: a.doctor?.name ?? 'Doctor' })} />
                  ) : null}
                  {active && !a.is_video ? (
                    <Chip icon="navigation" label="Add shuttle" onPress={() => navigation.navigate('Rides', { screen: 'ShuttleRequest', params: { to: a.clinic?.name } })} />
                  ) : null}
                  {active && a.status !== 'matching' && a.doctor ? (
                    <Chip icon="calendar" label="Add to calendar" onPress={() => addToCalendar(a)} />
                  ) : null}
                  {['pending', 'confirmed'].includes(a.status) && a.doctor && a.clinic ? (
                    <Chip icon="clock" label="Reschedule" onPress={() => navigation.navigate('Reschedule', { appointment: a })} />
                  ) : null}
                  {active && a.status !== 'checked_in' ? (
                    <Chip icon="x-circle" label={busyId === a.id ? 'Cancelling…' : 'Cancel'} danger onPress={() => cancel(a)} />
                  ) : null}
                  {!active && a.doctor ? (
                    <Chip icon="repeat" label="Book again" onPress={() => navigation.navigate('Care', { screen: 'DoctorDetail', params: { doctorId: a.doctor!.id } })} />
                  ) : null}
                </View>
              </View>
            </View>
          );
        }}
      />
    </View>
  );
}

function Chip({ icon, label, onPress, danger, live }: { icon: keyof typeof Feather.glyphMap; label: string; onPress: () => void; danger?: boolean; live?: boolean }) {
  const color = live ? '#fff' : danger ? pa.sats.red : pa.ink;
  return (
    <Pressable onPress={onPress} style={({ pressed }) => [styles.chip, danger && { borderColor: '#F2C4C4' }, live && { backgroundColor: pa.go, borderColor: pa.go }, pressed && { opacity: 0.7 }]}>
      <Feather name={icon} size={14} color={color} />
      <Text style={[styles.chipText, { color }]}>{label}</Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper },
  tabs: { flexDirection: 'row', gap: 8, marginBottom: 12 },
  tab: { flex: 1, borderWidth: 1, borderColor: pa.line, borderRadius: 10, paddingVertical: 10, alignItems: 'center', backgroundColor: pa.surface },
  tabOn: { backgroundColor: pa.ink, borderColor: pa.ink },
  tabText: { fontFamily: paFonts.bold, fontSize: 14, color: pa.ink },
  card: { flexDirection: 'row', backgroundColor: pa.surface, borderRadius: 14, borderWidth: 1, borderColor: pa.line, marginBottom: 12, overflow: 'hidden' },
  stripe: { width: 5 },
  top: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  doctor: { fontFamily: paFonts.bold, fontSize: 15, color: pa.ink },
  meta: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted, marginTop: 2 },
  pill: { borderRadius: 999, paddingHorizontal: 10, paddingVertical: 4 },
  pillText: { fontFamily: paFonts.black, fontSize: 11 },
  when: { flexDirection: 'row', alignItems: 'center', gap: 6, marginTop: 10 },
  whenText: { fontFamily: paFonts.bold, fontSize: 13, color: pa.ink },
  ref: { marginLeft: 'auto', fontFamily: paFonts.regular, fontSize: 11, color: pa.muted },
  actions: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginTop: 12 },
  chip: { flexDirection: 'row', alignItems: 'center', gap: 6, borderWidth: 1, borderColor: pa.line, borderRadius: 999, paddingHorizontal: 12, paddingVertical: 7 },
  chipText: { fontFamily: paFonts.bold, fontSize: 12 },
  bookBtn: { alignSelf: 'center', backgroundColor: pa.signal, borderRadius: 10, paddingHorizontal: 18, paddingVertical: 12, marginTop: 8 },
  bookBtnText: { fontFamily: paFonts.black, color: '#fff', fontSize: 14 },
});
