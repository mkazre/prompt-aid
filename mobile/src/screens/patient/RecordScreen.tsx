import React, { useCallback, useMemo, useState } from 'react';
import { ScrollView, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { api } from '../../api/client';
import { LabRequest, Order } from '../../api/types';
import { PaBadge, PaRow } from '../../components/pa';
import { useAuth } from '../../context/AuthContext';
import { pa, paFonts, type } from '../../theme';

interface TimelineEntry {
  key: string;
  title: string;
  date: string;
  sortAt: string;
}

/**
 * Patient record home — matches `record.html`. Aggregates lab requests
 * (`GET /lab-requests`) and pharmacy orders (`GET /orders`) into a single
 * timeline, and surfaces allergies / chronic conditions already captured
 * on the patient profile as alert chips.
 */
export default function RecordScreen({ navigation }: any) {
  const { user } = useAuth();
  const [labRequests, setLabRequests] = useState<LabRequest[]>([]);
  const [orders, setOrders] = useState<Order[]>([]);
  const [loading, setLoading] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const [labRes, orderRes] = await Promise.all([api.get('/lab-requests'), api.get('/orders')]);
      setLabRequests(labRes.data.data);
      setOrders(orderRes.data.data);
    } finally {
      setLoading(false);
    }
  }, []);

  useFocusEffect(useCallback(() => { load(); }, [load]));

  const newResultsCount = labRequests.filter((r) => r.status === 'completed' && (r.results?.length ?? 0) > 0).length;
  const activeOrders = orders.filter((o) => !['delivered', 'cancelled'].includes(o.status)).length;
  const outstandingLabel = activeOrders > 0 ? `${activeOrders} active order${activeOrders === 1 ? '' : 's'}` : 'No active orders';

  const profile = user?.patient_profile;
  const alerts = useMemo(() => {
    const list: string[] = [];
    if (profile?.allergies) list.push(...profile.allergies.split(',').map((s) => s.trim()).filter(Boolean));
    if (profile?.chronic_conditions) list.push(...profile.chronic_conditions.split(',').map((s) => s.trim()).filter(Boolean));
    return list;
  }, [profile]);

  const timeline = useMemo<TimelineEntry[]>(() => {
    const entries: TimelineEntry[] = [];
    labRequests.forEach((r) => {
      entries.push({
        key: `lab-${r.id}`,
        title: `Lab request · ${r.doctor?.name ?? r.request_ref}`,
        date: formatDate(r.requested_at),
        sortAt: r.requested_at,
      });
    });
    orders.forEach((o) => {
      entries.push({
        key: `order-${o.id}`,
        title: `Order ${o.order_no} · ${o.pharmacy?.name ?? 'Pharmacy'}`,
        date: formatDate(o.created_at),
        sortAt: o.created_at,
      });
    });
    return entries.sort((a, b) => (a.sortAt < b.sortAt ? 1 : -1)).slice(0, 10);
  }, [labRequests, orders]);

  return (
    <ScrollView style={styles.container} contentContainerStyle={{ padding: 16, paddingBottom: 40 }}>
      <Text style={[type.h2, { marginBottom: 12 }]}>My record</Text>

      {alerts.length > 0 && (
        <View style={styles.alertCard}>
          <Text style={styles.alertLabel}>Alerts</Text>
          <View style={styles.alertRow}>
            {alerts.map((a) => (
              <PaBadge key={a} tone="stop" label={a} />
            ))}
          </View>
        </View>
      )}

      <PaRow
        title="Results"
        subtitle={newResultsCount ? `${newResultsCount} new` : 'No new results'}
        right={newResultsCount ? <PaBadge tone="go" label={`${newResultsCount} new`} /> : undefined}
        onPress={() => navigation.navigate('LabResults')}
      />
      <PaRow
        title="Prescriptions & orders"
        subtitle={outstandingLabel}
        right={<PaBadge tone="wait" label="Order" />}
        onPress={() => navigation.navigate('Orders')}
      />
      <PaRow
        title="Invoices & claims"
        subtitle="View balances and pay"
        right={<PaBadge tone="stop" label="Pay" />}
        onPress={() => navigation.navigate('Invoices')}
      />
      <PaRow title="Profile & sharing" subtitle="Personal details, scheme, sharing" onPress={() => navigation.navigate('ProfileHome')} />

      <Text style={styles.sectionLabel}>Timeline</Text>
      {timeline.length === 0 && !loading ? (
        <Text style={styles.empty}>Nothing here yet — your consultations, results and orders will show up here.</Text>
      ) : (
        timeline.map((entry) => <PaRow key={entry.key} title={entry.title} subtitle={entry.date} />)
      )}
    </ScrollView>
  );
}

function formatDate(iso?: string | null) {
  if (!iso) return '—';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return d.toLocaleDateString('en-ZA', { day: '2-digit', month: 'short' });
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper },
  alertCard: { backgroundColor: pa.surface, borderWidth: 1, borderColor: pa.line, borderRadius: 2, padding: 16, marginBottom: 8 },
  alertLabel: { fontFamily: paFonts.black, fontSize: 11, letterSpacing: 1.2, textTransform: 'uppercase', color: pa.muted, marginBottom: 8 },
  alertRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 6 },
  sectionLabel: { fontFamily: paFonts.black, fontSize: 11, letterSpacing: 1.4, textTransform: 'uppercase', color: pa.muted, marginTop: 18, marginBottom: 8 },
  empty: { fontFamily: paFonts.regular, fontSize: 13, color: pa.muted, paddingVertical: 12 },
});
