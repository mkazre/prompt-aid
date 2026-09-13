import React, { useCallback, useState } from 'react';
import { FlatList, RefreshControl, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { api } from '../../api/client';
import { Doctor } from '../../api/types';
import { Card, EmptyState, Input, SectionTitle } from '../../components/UI';
import { colors, font, spacing } from '../../theme';
import { useAuth } from '../../context/AuthContext';

export default function HomeScreen({ navigation }: any) {
  const { user } = useAuth();
  const [doctors, setDoctors] = useState<Doctor[]>([]);
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(false);

  const load = useCallback(async (q = '') => {
    setLoading(true);
    try {
      const { data } = await api.get('/doctors', { params: q ? { search: q } : {} });
      setDoctors(data.data);
    } finally {
      setLoading(false);
    }
  }, []);

  useFocusEffect(
    useCallback(() => {
      load(search);
    }, [load])
  );

  return (
    <View style={styles.container}>
      <Text style={styles.greeting}>Hi {user?.name?.split(' ')[0]} 👋</Text>
      <Text style={styles.tagline}>Find a doctor and book in seconds.</Text>

      <Input
        placeholder="Search doctors or specialty..."
        value={search}
        onChangeText={setSearch}
        onSubmitEditing={() => load(search)}
      />

      <SectionTitle>Available doctors</SectionTitle>

      <FlatList
        data={doctors}
        keyExtractor={(d) => String(d.id)}
        refreshControl={<RefreshControl refreshing={loading} onRefresh={() => load(search)} />}
        ListEmptyComponent={!loading ? <EmptyState message="No doctors found." /> : null}
        contentContainerStyle={{ paddingBottom: spacing.xxl }}
        renderItem={({ item }) => (
          <Card style={{ marginBottom: spacing.sm }}>
            <View style={styles.row} onTouchEnd={() => navigation.navigate('DoctorDetail', { doctorId: item.id })}>
              <View style={styles.avatar}>
                <Text style={styles.avatarText}>{initials(item.name)}</Text>
              </View>
              <View style={{ flex: 1 }}>
                <Text style={styles.name}>{item.name}</Text>
                <Text style={styles.spec}>{item.specialization}</Text>
                <Text style={styles.meta}>★ {item.rating_avg.toFixed(1)} ({item.rating_count}) · R{item.consultation_fee}</Text>
              </View>
            </View>
          </Card>
        )}
      />
    </View>
  );
}

function initials(name: string) {
  return name.split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase();
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: colors.gray50, padding: spacing.lg },
  greeting: { fontFamily: font.bold, fontSize: 22, color: colors.secondary },
  tagline: { fontFamily: font.regular, color: colors.gray600, marginTop: 2, marginBottom: spacing.md },
  row: { flexDirection: 'row', alignItems: 'center', gap: spacing.md },
  avatar: { width: 52, height: 52, borderRadius: 26, backgroundColor: colors.primaryLight, alignItems: 'center', justifyContent: 'center' },
  avatarText: { fontFamily: font.bold, color: colors.primary },
  name: { fontFamily: font.semibold, fontSize: 15, color: colors.secondary },
  spec: { fontFamily: font.regular, fontSize: 13, color: colors.gray600 },
  meta: { fontFamily: font.medium, fontSize: 12, color: colors.accent, marginTop: 2 },
});
