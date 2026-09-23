import React, { useCallback, useState } from 'react';
import { Alert, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { api, apiErrorMessage } from '../../api/client';
import { Appointment, DoctorStats } from '../../api/types';
import { Avatar, Badge, Card, EmptyState, IconAction, SectionTitle, StatCard, WeeklyBarChart } from '../../components/UI';
import { colors, font, spacing } from '../../theme';
import { useAuth } from '../../context/AuthContext';

export default function DoctorDashboardScreen() {
  const { user } = useAuth();
  const [stats, setStats] = useState<DoctorStats | null>(null);
  const [today, setToday] = useState<Appointment[]>([]);
  const [loading, setLoading] = useState(false);

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
    try {
      await api.post(`/doctor/appointments/${id}/status`, { status });
      load();
    } catch (e) {
      Alert.alert('Could not update', apiErrorMessage(e));
    }
  }

  const greeting = (() => {
    const h = new Date().getHours();
    if (h < 12) return 'Good morning';
    if (h < 17) return 'Good afternoon';
    return 'Good evening';
  })();

  return (
    <ScrollView
      style={styles.container}
      contentContainerStyle={{ padding: spacing.lg, paddingBottom: spacing.xxl }}
      refreshControl={<RefreshControl refreshing={loading} onRefresh={load} />}
    >
      <View style={styles.header}>
        <View>
          <Text style={styles.greeting}>{greeting},</Text>
          <Text style={styles.name}>Dr. {user?.name?.replace(/^Dr\.?\s*/i, '')}</Text>
        </View>
        <Avatar name={user?.name} seed={user?.id} size={48} />
      </View>

      <View style={styles.statsRow}>
        <StatCard
          label="Total Patients"
          value={stats?.total_patients ?? 0}
          sublabel="Total visited patients"
          icon="🧑‍⚕️"
          iconBg={colors.dangerLight}
        />
        <StatCard
          label="Total Appointments"
          value={stats?.total_appointments ?? 0}
          sublabel="Total visited appointment"
          icon="📅"
          iconBg={colors.successLight}
        />
      </View>

      <SectionTitle>Weekly total appointments</SectionTitle>
      <Card>{stats ? <WeeklyBarChart data={stats.weekly_appointments} /> : null}</Card>

      <View style={{ marginTop: spacing.lg }}>
        <SectionTitle>Today's Appointments</SectionTitle>
        {today.length === 0 ? (
          <Card><EmptyState message="No appointments today." /></Card>
        ) : (
          today.map((a) => (
            <Card key={a.id} style={{ marginBottom: spacing.sm }}>
              <View style={styles.apptRow}>
                <Text style={styles.apptPatient}>{a.patient?.name ?? 'Patient'}</Text>
                <Text style={styles.apptTime}>({a.start_time?.slice(0, 5)} - {a.end_time?.slice(0, 5)})</Text>
                <View style={{ flex: 1 }} />
                <Badge status={a.status} />
              </View>
              {a.reason ? <Text style={styles.apptReason}>{a.reason}</Text> : null}
              <View style={styles.apptActions}>
                {a.status === 'pending' && (
                  <IconAction icon="✅" label="Confirm" color={colors.success} onPress={() => updateStatus(a.id, 'confirmed')} />
                )}
                {a.status === 'confirmed' && (
                  <IconAction icon="🟢" label="Check In" color={colors.primary} onPress={() => updateStatus(a.id, 'checked_in')} />
                )}
                {a.status === 'checked_in' && (
                  <IconAction icon="🏁" label="Complete" color={colors.success} onPress={() => updateStatus(a.id, 'completed')} />
                )}
                {!['completed', 'cancelled', 'no_show'].includes(a.status) && (
                  <IconAction icon="✖️" label="Cancel" color={colors.danger} onPress={() => updateStatus(a.id, 'cancelled')} />
                )}
              </View>
            </Card>
          ))
        )}
      </View>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: colors.gray50 },
  header: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: spacing.lg },
  greeting: { fontFamily: font.regular, fontSize: 14, color: colors.gray600 },
  name: { fontFamily: font.bold, fontSize: 22, color: colors.secondary, marginTop: 2 },
  statsRow: { flexDirection: 'row', gap: spacing.md, marginBottom: spacing.lg },
  apptRow: { flexDirection: 'row', alignItems: 'center', flexWrap: 'wrap', gap: 6 },
  apptPatient: { fontFamily: font.semibold, fontSize: 14, color: colors.primary },
  apptTime: { fontFamily: font.regular, fontSize: 12, color: colors.gray500 },
  apptReason: { fontFamily: font.regular, fontSize: 12, color: colors.gray600, marginTop: 4 },
  apptActions: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.sm, marginTop: spacing.sm, borderTopWidth: 1, borderTopColor: colors.gray100, paddingTop: spacing.sm },
});
