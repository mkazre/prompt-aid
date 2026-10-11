import React, { useCallback, useState } from 'react';
import { Alert, Pressable, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useFocusEffect, useNavigation } from '@react-navigation/native';
import { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { api, apiErrorMessage } from '../../api/client';
import { Appointment, DoctorStats } from '../../api/types';
import { PaAvatar, PaBadge, PaCard, PaEmptyState, PaRow, PaSectionLabel } from '../../components/pa';
import { PaChipButton, PaStatCell, PaStatGrid } from '../../components/pa/doctor-extras';
import { pa, paFonts, type } from '../../theme';
import { useAuth } from '../../context/AuthContext';
import { DoctorStackParamList } from '../../navigation/DoctorTabs';
import { STATUS_COLOURS, useSiteContent } from '../../services/siteContent';

type Availability = 'available' | 'busy' | 'offline';
const STATUS_OPTIONS: { value: Availability; colour: 'green' | 'orange' | 'red' }[] = [
  { value: 'available', colour: 'green' },
  { value: 'busy', colour: 'orange' },
  { value: 'offline', colour: 'red' },
];

type Nav = NativeStackNavigationProp<DoctorStackParamList>;

export default function DoctorDashboardScreen() {
  const insets = useSafeAreaInsets();
  const { user } = useAuth();
  const navigation = useNavigation<Nav>();
  const [stats, setStats] = useState<DoctorStats | null>(null);
  const [today, setToday] = useState<Appointment[]>([]);
  const [loading, setLoading] = useState(false);
  const [updatingId, setUpdatingId] = useState<number | null>(null);
  const [availability, setAvailability] = useState<Availability | null>(null);
  const [requests, setRequests] = useState<Appointment[]>([]);
  const content = useSiteContent();

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const [statsRes, apptRes, availRes, reqRes] = await Promise.all([
        api.get('/doctor/stats'),
        api.get('/doctor/appointments', { params: { date: new Date().toISOString().slice(0, 10) } }),
        api.get('/doctor/availability').catch(() => ({ data: { availability: null } })),
        api.get('/doctor/requests/available').catch(() => ({ data: { data: [] } })),
      ]);
      setStats(statsRes.data);
      setToday(apptRes.data.data);
      setAvailability(availRes.data.availability);
      setRequests(reqRes.data.data ?? []);
    } finally {
      setLoading(false);
    }
  }, []);

  useFocusEffect(
    useCallback(() => {
      load();
    }, [load])
  );

  async function updateStatus(id: number, status: string) {
    setUpdatingId(id);
    try {
      await api.post(`/doctor/appointments/${id}/status`, { status });
      await load();
    } catch (e) {
      Alert.alert('Could not update', apiErrorMessage(e));
    } finally {
      setUpdatingId(null);
    }
  }

  async function changeAvailability(value: Availability) {
    const previous = availability;
    setAvailability(value);
    try {
      await api.post('/doctor/availability', { availability: value });
    } catch (e) {
      setAvailability(previous);
      Alert.alert('Could not update status', apiErrorMessage(e));
    }
  }

  async function acceptRequest(id: number) {
    setUpdatingId(id);
    try {
      const { data } = await api.post(`/doctor/requests/${id}/accept`);
      Alert.alert('Booked', `${data.data.patient?.name ?? 'Patient'} at ${data.data.start_time?.slice(0, 5)}.`);
      await load();
    } catch (e) {
      Alert.alert('Could not accept', apiErrorMessage(e));
      await load();
    } finally {
      setUpdatingId(null);
    }
  }

  function openAppointment(appointment: Appointment) {
    if (appointment.mode === 'video' && appointment.meet_url) {
      navigation.navigate('ConsultRoom', { appointment });
    } else {
      navigation.navigate('DoctorEncounter', { appointment });
    }
  }

  const greeting = (() => {
    const h = new Date().getHours();
    if (h < 12) return 'Good morning';
    if (h < 17) return 'Good afternoon';
    return 'Good evening';
  })();

  const waiting = today.filter((a) => a.status === 'checked_in').length;

  return (
    <ScrollView
      style={styles.container}
      contentContainerStyle={{ padding: 16, paddingTop: insets.top + 16, paddingBottom: 48 }}
      refreshControl={<RefreshControl refreshing={loading} onRefresh={load} tintColor={pa.signal} />}
    >
      <View style={styles.header}>
        <View>
          <Text style={styles.greeting}>{greeting},</Text>
          <Text style={styles.name}>Dr. {user?.name?.replace(/^Dr\.?\s*/i, '')}</Text>
        </View>
        <PaAvatar name={user?.name} size={48} />
      </View>

      <PaSectionLabel style={{ marginBottom: 8 }}>My status · shown to patients</PaSectionLabel>
      <View style={styles.statusRow}>
        {STATUS_OPTIONS.map((o) => {
          const on = availability === o.value;
          const c = STATUS_COLOURS[o.colour];
          return (
            <Pressable
              key={o.value}
              onPress={() => changeAvailability(o.value)}
              style={[styles.statusBtn, { borderColor: on ? c.fg : pa.line, backgroundColor: on ? c.bg : pa.surface, borderWidth: on ? 2 : 1 }]}
            >
              <View style={[styles.statusDot, { backgroundColor: c.fg }]} />
              <Text style={[styles.statusLabel, { color: c.fg }]}>{content.status[o.colour].label}</Text>
            </Pressable>
          );
        })}
      </View>
      {availability ? (
        <Text style={styles.statusHelp}>
          {content.status[STATUS_OPTIONS.find((o) => o.value === availability)!.colour].description}
        </Text>
      ) : null}

      {requests.length ? (
        <>
          <PaSectionLabel style={{ marginTop: 6, marginBottom: 8 }}>Patients waiting for a doctor ({requests.length})</PaSectionLabel>
          {requests.map((r) => (
            <PaCard key={r.id} style={{ marginBottom: 10, padding: 0, paddingHorizontal: 16 }}>
              <PaRow
                title={r.patient?.name ?? 'Patient'}
                subtitle={`${r.mode === 'video' ? 'Video' : 'In person'}${r.reason ? ` · ${r.reason}` : ''}`}
                right={<PaBadge tone="stop" label="30 min" />}
              />
              <View style={styles.actions}>
                <PaChipButton title="Accept · book in 30 min" tone="go" loading={updatingId === r.id} onPress={() => acceptRequest(r.id)} />
              </View>
            </PaCard>
          ))}
        </>
      ) : null}

      <PaStatGrid>
        <PaStatCell label="Booked" value={stats?.total_appointments ?? today.length} />
        <PaStatCell label="Waiting" value={waiting} valueColor={waiting > 0 ? pa.signal : pa.ink} />
      </PaStatGrid>

      <PaSectionLabel style={{ marginBottom: 8 }}>Today's appointments</PaSectionLabel>
      {today.length === 0 ? (
        <PaCard><PaEmptyState message="No appointments today." /></PaCard>
      ) : (
        today.map((a) => (
          <PaCard key={a.id} style={{ marginBottom: 10, padding: 0, paddingHorizontal: 16 }}>
            <PaRow
              title={`${a.start_time?.slice(0, 5)} · ${a.patient?.name ?? 'Patient'}`}
              subtitle={a.reason ?? (a.visit_type === 'telemed' ? 'Video' : a.visit_type === 'home' ? 'Home visit' : 'Clinic')}
              right={<PaBadge status={a.status} />}
              onPress={() => openAppointment(a)}
            />
            <View style={styles.actions}>
              {a.status === 'pending' && (
                <PaChipButton title="Confirm" tone="go" loading={updatingId === a.id} onPress={() => updateStatus(a.id, 'confirmed')} />
              )}
              {a.status === 'confirmed' && (
                <PaChipButton title="Check in" loading={updatingId === a.id} onPress={() => updateStatus(a.id, 'checked_in')} />
              )}
              {a.status === 'checked_in' && (
                <PaChipButton title="Complete" tone="go" loading={updatingId === a.id} onPress={() => updateStatus(a.id, 'completed')} />
              )}
              {!['completed', 'cancelled', 'no_show'].includes(a.status) && (
                <PaChipButton title="Cancel" tone="stop" loading={updatingId === a.id} onPress={() => updateStatus(a.id, 'cancelled')} />
              )}
            </View>
          </PaCard>
        ))
      )}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper },
  header: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16 },
  greeting: { fontFamily: paFonts.regular, fontSize: 14, color: pa.muted },
  name: { ...type.h2, color: pa.ink, marginTop: 2 },
  statusRow: { flexDirection: 'row', gap: 6, marginBottom: 6 },
  statusBtn: { flex: 1, borderRadius: 2, paddingVertical: 10, paddingHorizontal: 6, alignItems: 'center', gap: 5 },
  statusDot: { width: 10, height: 10, borderRadius: 5 },
  statusLabel: { fontFamily: paFonts.black, fontSize: 11, textAlign: 'center' },
  statusHelp: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted, marginBottom: 14 },
  actions: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, paddingBottom: 14, paddingTop: 2 },
});
