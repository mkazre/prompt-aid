import React, { useCallback, useState } from 'react';
import { FlatList, RefreshControl, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { api } from '../../api/client';
import { DoctorPatient } from '../../api/types';
import { PaAvatar, PaCard, PaEmptyState, PaInput, PaSectionLabel } from '../../components/pa';
import { pa, paFonts } from '../../theme';

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
      <PaSectionLabel style={{ marginBottom: 10 }}>My patients</PaSectionLabel>
      <PaInput placeholder="Search patients..." value={search} onChangeText={setSearch} />
      <FlatList
        data={filtered}
        keyExtractor={(p) => String(p.id)}
        refreshControl={<RefreshControl refreshing={loading} onRefresh={load} tintColor={pa.signal} />}
        ListEmptyComponent={!loading ? <PaCard><PaEmptyState message="No patients yet." /></PaCard> : null}
        contentContainerStyle={{ paddingBottom: 48 }}
        renderItem={({ item }) => (
          <PaCard style={{ marginBottom: 8 }}>
            <View style={styles.row}>
              <PaAvatar name={item.name} />
              <View style={{ flex: 1 }}>
                <Text style={styles.name}>{item.name}</Text>
                <Text style={styles.meta}>{item.gender ? item.gender + ' · ' : ''}{item.phone ?? 'No phone on file'}</Text>
                <Text style={styles.meta}>{item.visits} visit{item.visits !== 1 ? 's' : ''} · Last: {item.last_visit ?? '—'}</Text>
              </View>
            </View>
          </PaCard>
        )}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper, padding: 16 },
  row: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  name: { fontFamily: paFonts.bold, fontSize: 15, color: pa.ink },
  meta: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted, marginTop: 2 },
});
