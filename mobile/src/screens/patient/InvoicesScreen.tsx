import React, { useCallback, useMemo, useState } from 'react';
import { Alert, FlatList, Modal, Pressable, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { api, apiErrorMessage } from '../../api/client';
import { Invoice } from '../../api/types';
import { PaBadge, PaButton, PaEmptyState, PaRow } from '../../components/pa';
import { PaPayMethodPicker, PayMethod } from '../../components/pa/patient-commerce-extras';
import { pa, paFonts, paRadius, type } from '../../theme';

/** Invoices & claims list — matches `invoices.html`: an outstanding-total
 * summary tile, then a row per invoice with a "Pay now" action that opens a
 * payment-method sheet backed by `POST /invoices/{id}/pay`. */
export default function InvoicesScreen() {
  const [invoices, setInvoices] = useState<Invoice[]>([]);
  const [loading, setLoading] = useState(false);
  const [payTarget, setPayTarget] = useState<Invoice | null>(null);
  const [method, setMethod] = useState<PayMethod>('card');
  const [paying, setPaying] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const { data } = await api.get('/invoices');
      setInvoices(data.data);
    } finally {
      setLoading(false);
    }
  }, []);

  useFocusEffect(useCallback(() => { load(); }, [load]));

  const outstanding = useMemo(
    () => invoices.filter((i) => i.status !== 'paid').reduce((sum, i) => sum + i.total, 0),
    [invoices]
  );

  async function pay() {
    if (!payTarget) return;
    setPaying(true);
    try {
      await api.post(`/invoices/${payTarget.id}/pay`, { method });
      setPayTarget(null);
      await load();
    } catch (e) {
      Alert.alert('Payment failed', apiErrorMessage(e));
    } finally {
      setPaying(false);
    }
  }

  return (
    <View style={styles.container}>
      <Text style={[type.h2, { marginBottom: 12 }]}>Invoices</Text>

      <View style={styles.summaryRow}>
        <View style={styles.summaryTile}>
          <Text style={styles.summaryLabel}>Outstanding</Text>
          <Text style={[type.num, styles.summaryValueSignal]}>R {outstanding.toFixed(0)}</Text>
        </View>
      </View>

      <FlatList
        data={invoices}
        keyExtractor={(i) => String(i.id)}
        contentContainerStyle={{ paddingBottom: 40 }}
        ListEmptyComponent={!loading ? <PaEmptyState message="No invoices yet." /> : null}
        renderItem={({ item }) => (
          <PaRow
            title={`${item.invoice_no}${item.clinic?.name ? ' · ' + item.clinic.name : ''}`}
            subtitle={`${item.due_date ? item.due_date + ' · ' : ''}R ${item.total.toFixed(2)}`}
            right={
              item.status === 'paid' ? (
                <PaBadge tone="go" label="Paid" />
              ) : (
                <Pressable onPress={() => setPayTarget(item)}>
                  <PaBadge tone="stop" label="Pay now" />
                </Pressable>
              )
            }
          />
        )}
      />

      <Modal visible={!!payTarget} transparent animationType="slide" onRequestClose={() => setPayTarget(null)}>
        <View style={styles.sheetOverlay}>
          <View style={styles.sheet}>
            <Text style={styles.sheetTitle}>Pay {payTarget?.invoice_no}</Text>
            <Text style={styles.sheetAmount}>R {payTarget?.total.toFixed(2)}</Text>
            <PaPayMethodPicker value={method} onChange={setMethod} />
            <PaButton title="Confirm payment" onPress={pay} loading={paying} style={{ marginTop: 8 }} />
            <PaButton title="Cancel" variant="ghost" onPress={() => setPayTarget(null)} style={{ marginTop: 8 }} />
          </View>
        </View>
      </Modal>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper, padding: 16 },
  summaryRow: { flexDirection: 'row', gap: 10, marginBottom: 16 },
  summaryTile: { flex: 1, backgroundColor: pa.surface, borderWidth: 1, borderColor: pa.line, borderRadius: paRadius.sm, padding: 14 },
  summaryLabel: { fontFamily: paFonts.black, fontSize: 11, letterSpacing: 1.2, textTransform: 'uppercase', color: pa.muted, marginBottom: 4 },
  summaryValueSignal: { fontSize: 24, color: pa.signal },
  sheetOverlay: { flex: 1, backgroundColor: 'rgba(16,16,18,0.45)', justifyContent: 'flex-end' },
  sheet: { backgroundColor: pa.surface, borderTopWidth: 1, borderTopColor: pa.ink, padding: 18, paddingBottom: 28 },
  sheetTitle: { fontFamily: paFonts.black, fontSize: 16, color: pa.ink },
  sheetAmount: { fontFamily: paFonts.black, fontSize: 26, color: pa.ink, marginBottom: 14 },
});
