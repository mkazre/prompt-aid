import React, { useCallback, useState } from 'react';
import { FlatList, RefreshControl, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { api } from '../../api/client';
import { Appointment } from '../../api/types';
import { Badge, Card, EmptyState } from '../../components/UI';
import { colors, font, spacing } from '../../theme';

export default function AppointmentsScreen() {
  const [appointments, setAppointments] = useState<Appointment[]>([]);
  const [loading, setLoading] = useState(false);

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

  return (
    <View style={styles.container}>
      <Text style={styles.title}>My Appointments</Text>
      <FlatList
        data={appointments}
        keyExtractor={(a) => String(a.id)}
        refreshControl={<RefreshControl refreshing={loading} onRefresh={load} />}
        ListEmptyComponent={!loading ? <EmptyState message="No appointments yet." /> : null}
        contentContainerStyle={{ paddingBottom: spacing.xxl }}
        renderItem={({ item }) => (
          <Card style={{ marginBottom: spacing.sm }}>
            <View style={styles.rowBetween}>
              <View>
                <Text style={styles.doctor}>{item.doctor?.name}</Text>
                <Text style={styles.meta}>{item.clinic?.name}</Text>
                <Text style={styles.meta}>{item.date} at {item.start_time}</Text>
              </View>
              <Badge status={item.status} />
            </View>
          </Card>
        )}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: colors.gray50, padding: spacing.lg },
  title: { fontFamily: font.bold, fontSize: 22, color: colors.secondary, marginBottom: spacing.md },
  rowBetween: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-start' },
  doctor: { fontFamily: font.semibold, fontSize: 15, color: colors.secondary },
  meta: { fontFamily: font.regular, fontSize: 12, color: colors.gray600, marginTop: 2 },
});
