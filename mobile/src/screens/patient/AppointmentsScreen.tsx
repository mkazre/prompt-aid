import React, { useCallback, useState } from 'react';
import { FlatList, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { useFocusEffect, useNavigation } from '@react-navigation/native';
import { api } from '../../api/client';
import { Appointment } from '../../api/types';
import { Badge, Card, EmptyState } from '../../components/UI';
import { colors, font, spacing } from '../../theme';

export default function AppointmentsScreen() {
  const [appointments, setAppointments] = useState<Appointment[]>([]);
  const [loading, setLoading] = useState(false);
  const navigation = useNavigation<any>();

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
            {item.doctor && (item.status as string) !== 'matching' ? (
              <View style={styles.actions}>
                <Pressable
                  onPress={() => navigation.navigate('Chat', { appointmentId: item.id, title: `Dr. ${item.doctor?.name ?? ''}` })}
                  style={({ pressed }) => [styles.action, { opacity: pressed ? 0.7 : 1 }]}
                >
                  <Ionicons name="chatbubble-ellipses-outline" size={16} color={colors.secondary} />
                  <Text style={styles.actionText}>Message doctor</Text>
                </Pressable>
                {item.is_video && !['cancelled', 'no_show', 'completed'].includes(item.status) ? (
                  <Pressable
                    onPress={() => navigation.navigate('Home', { screen: 'ConsultRoom', params: { appointment: item } })}
                    style={({ pressed }) => [styles.action, item.can_join_video && styles.actionLive, { opacity: pressed ? 0.7 : 1 }]}
                  >
                    <Ionicons name="videocam-outline" size={16} color={item.can_join_video ? '#fff' : colors.secondary} />
                    <Text style={[styles.actionText, item.can_join_video && { color: '#fff' }]}>{item.can_join_video ? 'Join video' : 'Video room'}</Text>
                  </Pressable>
                ) : null}
              </View>
            ) : null}
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
  actions: { flexDirection: 'row', gap: 8, marginTop: 12, flexWrap: 'wrap' },
  action: { flexDirection: 'row', alignItems: 'center', gap: 6, paddingHorizontal: 12, height: 34, borderRadius: 17, borderWidth: 1, borderColor: colors.gray200, backgroundColor: '#fff' },
  actionLive: { backgroundColor: colors.success, borderColor: colors.success },
  actionText: { fontFamily: font.bold, fontSize: 12, color: colors.secondary },
});
