import React, { useCallback, useState } from 'react';
import { FlatList, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { api } from '../../api/client';
import { Order } from '../../api/types';
import { PaBadge, PaEmptyState, PaRow } from '../../components/pa';
import { PaSteps, StepItem } from '../../components/pa/patient-commerce-extras';
import { pa, paFonts, paRadius, type } from '../../theme';

/** Pharmacy order history — matches `orders.html`: the most recent order gets
 * an expanded step tracker, the rest are a flat status list. */
export default function OrdersScreen() {
  const [orders, setOrders] = useState<Order[]>([]);
  const [loading, setLoading] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const { data } = await api.get('/orders');
      setOrders(data.data);
    } finally {
      setLoading(false);
    }
  }, []);

  useFocusEffect(useCallback(() => { load(); }, [load]));

  const [latest, ...rest] = orders;

  return (
    <View style={styles.container}>
      <Text style={[type.h2, { marginBottom: 12 }]}>Orders</Text>
      <FlatList
        data={rest}
        keyExtractor={(o) => String(o.id)}
        contentContainerStyle={{ paddingBottom: 40 }}
        ListHeaderComponent={
          latest ? (
            <View style={styles.card}>
              <View style={styles.cardHeader}>
                <Text style={styles.orderNo}>{latest.order_no}</Text>
                <PaBadge status={latest.status} />
              </View>
              <PaSteps steps={buildSteps(latest)} />
            </View>
          ) : null
        }
        ListEmptyComponent={!loading && !latest ? <PaEmptyState message="No orders yet." /> : null}
        renderItem={({ item }) => (
          <PaRow
            title={item.order_no}
            subtitle={`${item.pharmacy?.name ?? 'Pharmacy'} · R${item.total.toFixed(2)}`}
            right={<PaBadge status={item.status} />}
          />
        )}
      />
    </View>
  );
}

// Mirrors the `orders.status` enum in
// backend/database/migrations/2026_09_13_111435_create_orders_table.php.
const ORDER_STAGES = ['pending_payment', 'awaiting_prescription_review', 'confirmed', 'preparing', 'out_for_delivery', 'delivered'];

const STAGE_LABELS: Record<string, string> = {
  pending_payment: 'Placed',
  awaiting_prescription_review: 'Pharmacist reviewing',
  confirmed: 'Confirmed',
  preparing: 'Preparing',
  out_for_delivery: 'Out for delivery',
  delivered: 'Delivered',
};

function buildSteps(order: Order): StepItem[] {
  if (order.status === 'cancelled') {
    return [
      { label: 'Placed', time: '', state: 'done' },
      { label: 'Cancelled', time: 'now', state: 'now' },
    ];
  }
  const currentIdx = Math.max(0, ORDER_STAGES.indexOf(order.status));
  return ORDER_STAGES.map((stage, i) => ({
    label: STAGE_LABELS[stage],
    time: i < currentIdx ? '' : i === currentIdx ? 'now' : '—',
    state: i < currentIdx ? 'done' : i === currentIdx ? 'now' : 'todo',
  }));
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper, padding: 16 },
  card: { backgroundColor: pa.surface, borderWidth: 1, borderColor: pa.line, borderRadius: paRadius.sm, padding: 16, marginBottom: 8 },
  cardHeader: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 10 },
  orderNo: { fontFamily: paFonts.black, fontSize: 15, color: pa.ink },
});
