import React, { useCallback, useState } from 'react';
import { Alert, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect, useNavigation } from '@react-navigation/native';
import { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { api, apiErrorMessage } from '../../api/client';
import { Appointment, DoctorStats } from '../../api/types';
import { PaAvatar, PaBadge, PaCard, PaEmptyState, PaRow, PaSectionLabel } from '../../components/pa';
import { PaChipButton, PaStatCell, PaStatGrid } from '../../components/pa/doctor-extras';
import { pa, paFonts, type } from '../../theme';
import { useAuth } from '../../context/AuthContext';
import { DoctorStackParamList } from '../../navigation/DoctorTabs';

type Nav = NativeStackNavigationProp<DoctorStackParamList>;

export default function DoctorDashboardScreen() {
  const { user } = useAuth();
  const navigation = useNavigation<Nav>();
  const [stats, setStats] = useState<DoctorStats | null>(null);
  const [today, setToday] = useState<Appointment[]>([]);
  const [loading, setLoading] = useState(false);
  const [updatingId, setUpdatingId] = useState<number | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const [statsRes, apptRes] = await Promise.all([
        api.get('/doctor/stats'),
        api.get('/doctor/appointments', { params: { date: new Date().toISOString().slice(0, 10) } }),
      ]);
      setStats(statsRes.data);
      setToday(apptRes.data.data);
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
      contentContainerStyle={{ padding: 16, paddingBottom: 48 }}
      refreshControl={<RefreshControl refreshing={loading} onRefresh={load} tintColor={pa.signal} />}
    >
      <View style={styles.header}>
        <View>
          <Text style={styles.greeting}>{greeting},</Text>
          <Text style={styles.name}>Dr. {user?.name?.replace(/^Dr\.?\s*/i, '')}</Text>
        </View>
        <PaAvatar name={user?.name} size={48} />
      </View>

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
  actions: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, paddingBottom: 14, paddingTop: 2 },
});
