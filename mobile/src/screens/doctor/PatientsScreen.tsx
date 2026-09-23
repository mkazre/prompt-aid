import React, { useCallback, useState } from 'react';
import { FlatList, RefreshControl, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { api } from '../../api/client';
import { DoctorPatient } from '../../api/types';
import { Avatar, Card, EmptyState, Input, SectionTitle } from '../../components/UI';
import { colors, font, spacing } from '../../theme';

export default function DoctorPatientsScreen() {
  const [patients, setPatients] = useState<DoctorPatient[]>([]);
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const { data } = await api.get('/doctor/patients');
      setPatients(data.data);
    } finally {
      setLoading(false);
    }
  }, []);

  useFocusEffect(
    useCallback(() => {
      load();
    }, [load])
  );

  const filtered = patients.filter((p) => p.name?.toLowerCase().includes(search.toLowerCase()));

  return (
    <View style={styles.container}>
      <SectionTitle>My Patients</SectionTitle>
      <Input placeholder="Search patients..." value={search} onChangeText={setSearch} />
      <FlatList
        data={filtered}
        keyExtractor={(p) => String(p.id)}
        refreshControl={<RefreshControl refreshing={loading} onRefresh={load} />}
        ListEmptyComponent={!loading ? <EmptyState message="No patients yet." /> : null}
        contentContainerStyle={{ paddingBottom: spacing.xxl }}
        renderItem={({ item }) => (
          <Card style={{ marginBottom: spacing.sm }}>
            <View style={styles.row}>
              <Avatar name={item.name} seed={item.id} />
              <View style={{ flex: 1 }}>
                <Text style={styles.name}>{item.name}</Text>
                <Text style={styles.meta}>{item.gender ? item.gender + ' · ' : ''}{item.phone ?? 'No phone on file'}</Text>
                <Text style={styles.meta}>{item.visits} visit{item.visits !== 1 ? 's' : ''} · Last: {item.last_visit ?? '—'}</Text>
              </View>
            </View>
          </Card>
        )}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: colors.gray50, padding: spacing.lg },
  row: { flexDirection: 'row', alignItems: 'center', gap: spacing.md },
  name: { fontFamily: font.semibold, fontSize: 15, color: colors.secondary },
  meta: { fontFamily: font.regular, fontSize: 12, color: colors.gray500, marginTop: 2 },
});
