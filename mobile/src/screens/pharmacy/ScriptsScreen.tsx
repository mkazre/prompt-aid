import React, { useCallback, useState } from 'react';
import { Alert, Image, Pressable, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { api, apiErrorMessage } from '../../api/client';
import { Order } from '../../api/types';
import { PaBadge, PaButton, PaCard, PaEmptyState, PaEyebrow, PaInput } from '../../components/pa';
import { PaSpreadRow } from '../../components/pa/pharmacy-extras';
import { pa, paFonts, type } from '../../theme';

export default function PharmacyScriptsScreen() {
  const [orders, setOrders] = useState<Order[]>([]);
  const [loading, setLoading] = useState(false);
  const [expandedId, setExpandedId] = useState<number | null>(null);
  const [notes, setNotes] = useState('');
  const [busyId, setBusyId] = useState<number | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const { data } = await api.get('/vendor/prescriptions/pending');
      setOrders(data.data ?? []);
    } finally {
      setLoading(false);
    }
  }, []);

  useFocusEffect(useCallback(() => { load(); }, [load]));

  function toggle(order: Order) {
    setNotes('');
    setExpandedId((id) => (id === order.id ? null : order.id));
  }

  async function approve(order: Order) {
    const uploadId = order.prescription_upload?.id;
    if (!uploadId) return;
    setBusyId(order.id);
    try {
      await api.post(`/vendor/prescriptions/${uploadId}/approve`, notes ? { notes } : undefined);
      setExpandedId(null);
      await load();
    } catch (e) {
      Alert.alert('Could not approve', apiErrorMessage(e));
    } finally {
      setBusyId(null);
    }
  }

  async function reject(order: Order) {
    const uploadId = order.prescription_upload?.id;
    if (!uploadId) return;
    if (!notes.trim()) {
      Alert.alert('Reason required', 'Add a note explaining why this script is being rejected.');
      return;
    }
    setBusyId(order.id);
    try {
      await api.post(`/vendor/prescriptions/${uploadId}/reject`, { notes });
      setExpandedId(null);
      await load();
    } catch (e) {
      Alert.alert('Could not reject', apiErrorMessage(e));
    } finally {
      setBusyId(null);
    }
  }

  return (
    <View style={styles.container}>
      <Text style={type.h2}>Scripts</Text>
      <ScrollView
        refreshControl={<RefreshControl refreshing={loading} onRefresh={load} tintColor={pa.signal} />}
        contentContainerStyle={{ paddingTop: 12, paddingBottom: 48 }}
      >
        {!loading && orders.length === 0 ? <PaEmptyState message="No scripts waiting for review." /> : null}
        {orders.map((order) => {
          const expanded = expandedId === order.id;
          const upload = order.prescription_upload;
          return (
            <PaCard key={order.id} style={{ marginBottom: 10 }}>
              <Pressable onPress={() => toggle(order)} style={styles.headRow}>
                <View style={{ flex: 1 }}>
                  <Text style={styles.orderNo}>{order.order_no}{order.patient?.name ? ` · ${order.patient.name}` : ''}</Text>
                  <Text style={styles.meta}>{order.items?.length ?? 0} item{order.items?.length === 1 ? '' : 's'}</Text>
                </View>
                <PaBadge label="Review" tone="stop" />
              </Pressable>

              {expanded ? (
                <View style={{ marginTop: 12 }}>
                  {upload?.file_url ? (
                    <Image source={{ uri: upload.file_url }} style={styles.scriptImage} resizeMode="contain" />
                  ) : (
                    <View style={[styles.scriptImage, styles.scriptImagePlaceholder]}>
                      <Text style={styles.meta}>No scanned script attached</Text>
                    </View>
                  )}

                  <PaEyebrow>Patient</PaEyebrow>
                  <PaSpreadRow label="Name" value={order.patient?.name ?? '—'} />
                  {order.patient?.phone ? <PaSpreadRow label="Phone" value={order.patient.phone} /> : null}

                  <View style={{ marginTop: 12 }}>
                    <PaEyebrow>Items</PaEyebrow>
                    {(order.items ?? []).map((item) => (
                      <PaSpreadRow key={item.id} label={`${item.product_name} × ${item.qty}`} value={`R${item.amount.toFixed(2)}`} />
                    ))}
                  </View>

                  <PaInput
                    label="Notes (required to reject)"
                    value={notes}
                    onChangeText={setNotes}
                    placeholder="e.g. penicillin allergy on file"
                    multiline
                  />

                  <View style={{ gap: 8 }}>
                    <PaButton title="Approve & unlock payment" onPress={() => approve(order)} loading={busyId === order.id} />
                    <PaButton title="Reject" variant="ghost" onPress={() => reject(order)} loading={busyId === order.id} />
                  </View>
                </View>
              ) : null}
            </PaCard>
          );
        })}
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper, padding: 16 },
  headRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 10 },
  orderNo: { fontFamily: paFonts.bold, fontSize: 14, color: pa.ink },
  meta: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted, marginTop: 2 },
  scriptImage: { width: '100%', height: 200, borderRadius: 2, backgroundColor: pa.lineSoft, marginBottom: 12 },
  scriptImagePlaceholder: { alignItems: 'center', justifyContent: 'center' },
});
