import React, { useCallback, useState } from 'react';
import { Alert, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { api, apiErrorMessage } from '../../api/client';
import { Order } from '../../api/types';
import { PaBadge, PaButton, PaCard, PaEyebrow, headline } from '../../components/pa';
import { PaSpreadRow } from '../../components/pa/pharmacy-extras';
import { pa, paFonts, type } from '../../theme';

const NEXT_STEP: Record<string, { status: 'preparing' | 'out_for_delivery' | 'delivered'; label: string } | undefined> = {
  confirmed: { status: 'preparing', label: 'Start preparing' },
  preparing: { status: 'out_for_delivery', label: 'Send for delivery' },
  out_for_delivery: { status: 'delivered', label: 'Mark delivered' },
};

const CANCELLABLE = ['pending_payment', 'confirmed', 'preparing'];

export default function PharmacyOrderDetailScreen({ route }: any) {
  const orderId: number = route.params.orderId;
  const [order, setOrder] = useState<Order | null>(null);
  const [loading, setLoading] = useState(false);
  const [advancing, setAdvancing] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const { data } = await api.get(`/vendor/orders/${orderId}`);
      setOrder(data.data ?? data);
    } catch (e) {
      Alert.alert('Could not load order', apiErrorMessage(e));
    } finally {
      setLoading(false);
    }
  }, [orderId]);

  useFocusEffect(useCallback(() => { load(); }, [load]));

  async function advance(status: 'preparing' | 'out_for_delivery' | 'delivered' | 'cancelled') {
    setAdvancing(true);
    try {
      const { data } = await api.post(`/vendor/orders/${orderId}/advance`, { status });
      setOrder(data.data ?? data);
    } catch (e) {
      Alert.alert('Could not update order', apiErrorMessage(e));
    } finally {
      setAdvancing(false);
    }
  }

  function confirmCancel() {
    Alert.alert('Cancel this order?', 'The patient will be notified.', [
      { text: 'Keep order', style: 'cancel' },
      { text: 'Cancel order', style: 'destructive', onPress: () => advance('cancelled') },
    ]);
  }

  if (!order) {
    return (
      <View style={styles.container}>
        {!loading && <Text style={type.body}>Order not found.</Text>}
      </View>
    );
  }

  const nextStep = NEXT_STEP[order.status];

  return (
    <ScrollView style={styles.container} contentContainerStyle={{ padding: 16, paddingBottom: 48 }}>
      <View style={styles.headerRow}>
        <Text style={type.h2}>{order.order_no}</Text>
        <PaBadge status={order.status} />
      </View>

      {order.patient?.name ? (
        <PaCard style={{ marginTop: 14 }}>
          <PaEyebrow>Patient</PaEyebrow>
          <Text style={styles.patientName}>{order.patient.name}</Text>
          {order.patient.phone ? <Text style={styles.muted}>{order.patient.phone}</Text> : null}
        </PaCard>
      ) : null}

      <PaCard style={{ marginTop: 12 }}>
        <PaEyebrow>Items</PaEyebrow>
        {(order.items ?? []).map((item) => (
          <PaSpreadRow key={item.id} label={`${item.product_name} × ${item.qty}`} value={`R${item.amount.toFixed(2)}`} />
        ))}
        <PaSpreadRow label="Subtotal" value={`R${order.subtotal.toFixed(2)}`} />
        <PaSpreadRow label="Delivery" value={`R${order.delivery_fee.toFixed(2)}`} />
        <View style={styles.totalRow}>
          <Text style={styles.totalLabel}>Total</Text>
          <Text style={styles.totalValue}>R{order.total.toFixed(2)}</Text>
        </View>
      </PaCard>

      <PaCard style={{ marginTop: 12 }}>
        <PaEyebrow>Delivery</PaEyebrow>
        <Text style={type.body}>{order.delivery_address}</Text>
      </PaCard>

      {order.prescription_upload ? (
        <PaCard style={{ marginTop: 12 }}>
          <PaEyebrow>Prescription</PaEyebrow>
          <Text style={type.body}>{headline(order.prescription_upload.status)}</Text>
          {order.prescription_upload.notes ? <Text style={styles.muted}>{order.prescription_upload.notes}</Text> : null}
        </PaCard>
      ) : null}

      <View style={{ marginTop: 20, gap: 10 }}>
        {nextStep ? (
          <PaButton title={nextStep.label} onPress={() => advance(nextStep.status)} loading={advancing} />
        ) : null}
        {CANCELLABLE.includes(order.status) ? (
          <PaButton title="Cancel order" variant="ghost" onPress={confirmCancel} loading={advancing} />
        ) : null}
      </View>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper },
  headerRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  patientName: { fontFamily: paFonts.bold, fontSize: 15, color: pa.ink },
  muted: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted, marginTop: 2 },
  totalRow: { flexDirection: 'row', justifyContent: 'space-between', paddingTop: 10, marginTop: 4 },
  totalLabel: { fontFamily: paFonts.black, fontSize: 14, color: pa.ink },
  totalValue: { fontFamily: paFonts.black, fontSize: 14, color: pa.ink },
});
