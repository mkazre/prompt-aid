import React, { useCallback, useState } from 'react';
import { FlatList, Image, RefreshControl, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { api } from '../../api/client';
import { Pharmacy } from '../../api/types';
import { EmptyState } from '../../components/UI';
import { colors, font, radius, shadow, spacing } from '../../theme';

export default function PharmacyListScreen({ navigation }: any) {
  const [pharmacies, setPharmacies] = useState<Pharmacy[]>([]);
  const [loading, setLoading] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const { data } = await api.get('/pharmacies');
      setPharmacies(data.data);
    } finally {
      setLoading(false);
    }
  }, []);

  useFocusEffect(useCallback(() => { load(); }, [load]));

  return (
    <View style={styles.container}>
      <Text style={styles.title}>💊 Pharmacy Marketplace</Text>
      <Text style={styles.subtitle}>Order medication for delivery from partner pharmacies.</Text>
      <FlatList
        data={pharmacies}
        keyExtractor={(p) => String(p.id)}
        refreshControl={<RefreshControl refreshing={loading} onRefresh={load} />}
        ListEmptyComponent={!loading ? <EmptyState message="No pharmacies available." /> : null}
        contentContainerStyle={{ paddingBottom: spacing.xxl, paddingTop: spacing.md }}
        renderItem={({ item }) => (
          <View style={styles.card} onTouchEnd={() => navigation.navigate('PharmacyDetail', { pharmacyId: item.id, pharmacyName: item.name })}>
            <Image source={{ uri: `https://picsum.photos/seed/pharmacy${item.id}/300/160` }} style={styles.image} />
            <View style={{ padding: spacing.md }}>
              <Text style={styles.name}>{item.name}</Text>
              <Text style={styles.meta}>{item.city} · ★ {item.rating_avg.toFixed(1)} · Delivery R{item.delivery_fee.toFixed(0)}</Text>
            </View>
          </View>
        )}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: colors.gray50, padding: spacing.lg },
  title: { fontFamily: font.bold, fontSize: 22, color: colors.secondary },
  subtitle: { fontFamily: font.regular, color: colors.gray600, marginTop: 2 },
  card: { backgroundColor: colors.white, borderRadius: radius.lg, marginBottom: spacing.md, overflow: 'hidden', ...shadow.card },
  image: { width: '100%', height: 120 },
  name: { fontFamily: font.semibold, fontSize: 15, color: colors.secondary },
  meta: { fontFamily: font.regular, fontSize: 12, color: colors.gray600, marginTop: 4 },
});
