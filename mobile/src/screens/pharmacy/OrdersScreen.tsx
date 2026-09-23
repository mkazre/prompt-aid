import React, { useCallback, useState } from 'react';
import { FlatList, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { api } from '../../api/client';
import { Order } from '../../api/types';
import { PaBadge, PaCard, PaEmptyState, PaRow } from '../../components/pa';
import { PaFilterChip } from '../../components/pa/pharmacy-extras';
import { pa, type } from '../../theme';

type NavProp = { navigate: (screen: string, params?: { orderId: number }) => void };

const FILTERS: { key: string; label: string }[] = [
  { key: '', label: 'All' },
  { key: 'awaiting_prescription_review', label: 'Review' },
  { key: 'confirmed', label: 'Confirmed' },
  { key: 'preparing', label: 'Preparing' },
  { key: 'out_for_delivery', label: 'Out for delivery' },
  { key: 'delivered', label: 'Delivered' },
  { key: 'cancelled', label: 'Cancelled' },
];

export default function PharmacyOrdersScreen({ navigation }: { navigation: NavProp }) {
  const [orders, setOrders] = useState<Order[]>([]);
  const [loading, setLoading] = useState(false);
  const [filter, setFilter] = useState('');

  const load = useCallback(async (status: string) => {
    setLoading(true);
    try {
      const { data } = await api.get('/vendor/orders', { params: status ? { status } : undefined });
      setOrders(data.data ?? []);
    } finally {
      setLoading(false);
    }
  }, []);

  useFocusEffect(useCallback(() => { load(filter); }, [load, filter]));

  return (
    <View style={styles.container}>
      <Text style={type.h2}>Orders</Text>
      <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.filterRow} contentContainerStyle={{ paddingRight: 16 }}>
        {FILTERS.map((f) => (
          <PaFilterChip key={f.key} label={f.label} active={filter === f.key} onPress={() => setFilter(f.key)} />
        ))}
      </ScrollView>
      <FlatList
        data={orders}
        keyExtractor={(o) => String(o.id)}
        refreshControl={<RefreshControl refreshing={loading} onRefresh={() => load(filter)} tintColor={pa.signal} />}
        ListEmptyComponent={!loading ? <PaEmptyState message="No orders here." /> : null}
        contentContainerStyle={{ paddingBottom: 48 }}
        renderItem={({ item }) => (
          <PaCard style={{ marginBottom: 10 }}>
            <PaRow
              title={`${item.order_no}${item.patient?.name ? ' · ' + item.patient.name : ''}`}
              subtitle={`${item.items?.length ?? 0} item${item.items?.length === 1 ? '' : 's'} · R${item.total.toFixed(2)}`}
              right={<PaBadge status={item.status} />}
              onPress={() => navigation.navigate('PharmacyOrderDetail', { orderId: item.id })}
            />
          </PaCard>
        )}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper, padding: 16 },
  filterRow: { marginTop: 12, marginBottom: 4, flexGrow: 0 },
});
