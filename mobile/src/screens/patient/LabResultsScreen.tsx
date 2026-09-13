import React, { useCallback, useState } from 'react';
import { FlatList, Linking, RefreshControl, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { api, API_BASE_URL } from '../../api/client';
import { LabRequest } from '../../api/types';
import { Badge, Card, EmptyState } from '../../components/UI';
import { colors, font, spacing } from '../../theme';

const STORAGE_BASE = API_BASE_URL.replace(/\/api\/?$/, '/storage/');

export default function LabResultsScreen() {
  const [requests, setRequests] = useState<LabRequest[]>([]);
  const [loading, setLoading] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const { data } = await api.get('/lab-requests');
      setRequests(data.data);
    } finally {
      setLoading(false);
    }
  }, []);

  useFocusEffect(useCallback(() => { load(); }, [load]));

  return (
    <View style={styles.container}>
      <Text style={styles.title}>🧪 Lab &amp; Diagnostic Results</Text>
      <FlatList
        data={requests}
        keyExtractor={(r) => String(r.id)}
        refreshControl={<RefreshControl refreshing={loading} onRefresh={load} />}
        ListEmptyComponent={!loading ? <EmptyState message="No lab requests yet." /> : null}
        contentContainerStyle={{ paddingBottom: spacing.xxl }}
        renderItem={({ item }) => (
          <Card style={{ marginBottom: spacing.sm }}>
            <View style={styles.rowBetween}>
              <Text style={styles.doctor}>{item.doctor?.name}</Text>
              <Badge status={item.status} />
            </View>
            <Text style={styles.meta}>{item.items?.map((i) => i.test_name).join(', ')}</Text>
            {item.third_party ? <Text style={styles.meta}>Lab partner: {item.third_party.company_name}</Text> : null}
            {item.results?.map((result) => (
              <Text
                key={result.id}
                onPress={() => Linking.openURL(`${STORAGE_BASE}${result.file_path}`)}
                style={styles.resultLink}
              >
                📄 {result.label} — View report
              </Text>
            ))}
          </Card>
        )}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: colors.gray50, padding: spacing.lg },
  title: { fontFamily: font.bold, fontSize: 20, color: colors.secondary, marginBottom: spacing.md },
  rowBetween: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-start' },
  doctor: { fontFamily: font.semibold, fontSize: 15, color: colors.secondary },
  meta: { fontFamily: font.regular, fontSize: 12, color: colors.gray600, marginTop: 4 },
  resultLink: { fontFamily: font.semibold, fontSize: 13, color: colors.primary, marginTop: 8 },
});
