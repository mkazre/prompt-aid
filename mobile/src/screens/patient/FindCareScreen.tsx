import React, { useCallback, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { api } from '../../api/client';
import { Doctor } from '../../api/types';
import { PaAvatar, PaBadge, PaEmptyState, PaInput } from '../../components/pa';
import { pa, paFonts, paRadius } from '../../theme';

export default function FindCareScreen({ navigation }: any) {
  const [search, setSearch] = useState('');
  const [doctors, setDoctors] = useState<Doctor[]>([]);
  const [loading, setLoading] = useState(false);

  const load = useCallback(async (q: string) => {
    setLoading(true);
    try {
      const { data } = await api.get('/doctors', { params: q ? { search: q } : {} });
      setDoctors(data.data);
    } finally {
      setLoading(false);
    }
  }, []);

  useFocusEffect(useCallback(() => { load(search); }, [load]));

  return (
    <View style={styles.container}>
      <View style={styles.head}>
        <Text style={styles.title}>Find care</Text>
      </View>
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }}>
        <PaInput placeholder="Symptom, speciality or name" value={search} onChangeText={setSearch} onSubmitEditing={() => load(search)} />
        <Text style={styles.count}><Text style={styles.countStrong}>{doctors.length}</Text> providers · {loading ? 'loading…' : 'soonest first'}</Text>

        {doctors.length === 0 && !loading ? (
          <PaEmptyState message="No providers found." />
        ) : (
          doctors.map((d) => (
            <Pressable key={d.id} onPress={() => navigation.navigate('DoctorDetail', { doctorId: d.id })} style={styles.card}>
              <View style={styles.cardTop}>
                <PaAvatar name={d.name} size={44} />
                <View style={{ flex: 1 }}>
                  <Text style={styles.name}>{d.name}</Text>
                  <Text style={styles.spec}>{d.specialization}</Text>
                </View>
              </View>
              <View style={styles.cardFooter}>
                <PaBadge tone={d.is_accepting_appointments ? 'go' : 'wait'} label={d.is_accepting_appointments ? 'Accepting bookings' : 'Not accepting'} />
                <Text style={styles.fee}>R {Math.round(d.consultation_fee)}</Text>
              </View>
            </Pressable>
          ))
        )}
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper },
  head: { paddingTop: 56, paddingBottom: 14, paddingHorizontal: 16, backgroundColor: pa.surface, borderBottomWidth: 1, borderBottomColor: pa.line },
  title: { fontFamily: paFonts.black, fontSize: 20, color: pa.ink, textAlign: 'center' },
  count: { fontSize: 12, color: pa.muted, marginTop: 4, marginBottom: 10 },
  countStrong: { color: pa.ink, fontFamily: paFonts.bold },
  card: { backgroundColor: pa.surface, borderWidth: 1, borderColor: pa.line, borderRadius: paRadius.sm, padding: 14, marginBottom: 10 },
  cardTop: { flexDirection: 'row', gap: 12, alignItems: 'center', marginBottom: 10 },
  name: { fontFamily: paFonts.bold, fontSize: 15, color: pa.ink },
  spec: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted, marginTop: 2 },
  cardFooter: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', borderTopWidth: 1, borderTopColor: pa.lineSoft, paddingTop: 10 },
  fee: { fontFamily: paFonts.black, fontSize: 17, color: pa.ink },
});
