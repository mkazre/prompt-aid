import React, { useCallback, useState } from 'react';
import { Alert, FlatList, RefreshControl, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { api, apiErrorMessage } from '../../api/client';
import { LabRequest } from '../../api/types';
import { useAuth } from '../../context/AuthContext';
import { Badge, Card, EmptyState, PrimaryButton } from '../../components/UI';
import { colors, font, spacing } from '../../theme';

export default function ThirdPartyHomeScreen({ navigation }: any) {
  const { user } = useAuth();
  const [available, setAvailable] = useState<LabRequest[]>([]);
  const [mine, setMine] = useState<LabRequest[]>([]);
  const [loading, setLoading] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const [avRes, mineRes] = await Promise.all([
        api.get('/third-party/lab-requests/available'),
        api.get('/third-party/lab-requests'),
      ]);
      setAvailable(avRes.data.data);
      setMine((mineRes.data.data as LabRequest[]).filter((r) => !['completed', 'cancelled'].includes(r.status)));
    } finally {
      setLoading(false);
    }
  }, []);

  useFocusEffect(useCallback(() => { load(); }, [load]));

  async function accept(request: LabRequest) {
    try {
      await api.post(`/third-party/lab-requests/${request.id}/accept`);
      await load();
      navigation.navigate('RequestDetail', { requestId: request.id });
    } catch (e) {
      Alert.alert('Could not accept', apiErrorMessage(e));
    }
  }

  return (
    <View style={styles.container}>
      <Text style={styles.title}>Hi {user?.name?.split(' ')[0]} 🧪</Text>
      <Text style={styles.subtitle}>Diagnostic requests from Prompt Aid doctors.</Text>

      {mine.length > 0 && (
        <>
          <Text style={styles.sectionTitle}>In progress</Text>
          {mine.map((req) => (
            <Card key={req.id} style={{ marginBottom: spacing.sm }} onTouchEnd={() => navigation.navigate('RequestDetail', { requestId: req.id })}>
              <View style={styles.rowBetween}>
                <Text style={styles.patientName}>{req.patient?.user?.name}</Text>
                <Badge status={req.status} />
              </View>
              <Text style={styles.meta}>{req.items?.map((i) => i.test_name).join(', ')}</Text>
            </Card>
          ))}
        </>
      )}

      <Text style={styles.sectionTitle}>Available requests</Text>
      <FlatList
        data={available}
        keyExtractor={(r) => String(r.id)}
        refreshControl={<RefreshControl refreshing={loading} onRefresh={load} />}
        ListEmptyComponent={!loading ? <EmptyState message="No open requests right now." /> : null}
        renderItem={({ item }) => (
          <Card style={{ marginBottom: spacing.sm }}>
            <View style={styles.rowBetween}>
              <Text style={styles.patientName}>{item.patient?.user?.name}</Text>
              {item.priority === 'urgent' && <Badge status="urgent" />}
            </View>
            <Text style={styles.meta}>Dr. {item.doctor?.name} · {item.items?.map((i) => i.test_name).join(', ')}</Text>
            <Text style={styles.meta}>📍 {item.collection_address}</Text>
            <View style={{ marginTop: spacing.sm }}>
              <PrimaryButton title="Accept request" onPress={() => accept(item)} />
            </View>
          </Card>
        )}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: colors.gray50, padding: spacing.lg },
  title: { fontFamily: font.bold, fontSize: 20, color: colors.secondary },
  subtitle: { fontFamily: font.regular, fontSize: 12, color: colors.gray600, marginTop: 2, marginBottom: spacing.md },
  sectionTitle: { fontFamily: font.bold, fontSize: 15, color: colors.secondary, marginTop: spacing.sm, marginBottom: spacing.sm },
  rowBetween: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  patientName: { fontFamily: font.semibold, color: colors.secondary },
  meta: { fontFamily: font.regular, fontSize: 12, color: colors.gray600, marginTop: 4 },
});
