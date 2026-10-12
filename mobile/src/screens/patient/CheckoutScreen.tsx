import React, { useEffect, useState } from 'react';
import { Alert, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import * as DocumentPicker from 'expo-document-picker';
import { api, apiErrorMessage } from '../../api/client';
import { PaButton, PaEmptyState } from '../../components/pa';
import { PaPayMethodPicker, PaQtyStepper, PaSpreadRow, PayMethod } from '../../components/pa/patient-commerce-extras';
import { pa, paFonts, paRadius, type } from '../../theme';
import { useAuth } from '../../context/AuthContext';
import { useCart } from '../../context/CartContext';
import SuccessModal from '../../components/SuccessModal';

const needsPrescriptionMessage = 'Your order was placed. Payment is still pending — you can pay from My Orders once the pharmacy has reviewed it.';

/**
 * Cart / checkout — matches `checkout.html`, simplified to what the real
 * backend supports: a single-pharmacy cart (`POST /orders/checkout`)
 * followed by paying that order (`POST /orders/{order}/pay`). The
 * mockup's multi-line "split payment" (consult + pharmacy + shuttle in one
 * charge) has no backend endpoint — this screen only settles the pharmacy
 * order, matching PharmacyController::checkout / payOrder.
 */
export default function CheckoutScreen({ navigation }: any) {
  const { user } = useAuth();
  const { lines, subtotal, setQty, removeItem, clear } = useCart();
  const [deliveryAddress, setDeliveryAddress] = useState(user?.patient_profile?.address ?? '');
  const [prescriptionId, setPrescriptionId] = useState<number | null>(null);
  const [prescriptionName, setPrescriptionName] = useState<string | null>(null);
  const [uploading, setUploading] = useState(false);
  const [method, setMethod] = useState<PayMethod>('card');
  const [placing, setPlacing] = useState(false);
  const [done, setDone] = useState<{ paid: boolean; orderNo: string | null; total: number } | null>(null);
  const [promoInput, setPromoInput] = useState('');
  const [promo, setPromo] = useState<{ code: string; discount: number; message: string } | null>(null);
  const [promoError, setPromoError] = useState<string | null>(null);
  const [checkingPromo, setCheckingPromo] = useState(false);

  const successModal = (
    <SuccessModal
      visible={!!done}
      title={done?.paid ? 'Payment Success' : 'Order placed'}
      message={
        done?.paid
          ? 'Your payment has been successful. The pharmacy is preparing your order.'
          : needsPrescriptionMessage
      }
      details={[
        ...(done?.orderNo ? [{ label: 'Order', value: done.orderNo }] : []),
        ...(done ? [{ label: done.paid ? 'Paid' : 'Total', value: `R ${done.total.toFixed(2)}` }] : []),
      ]}
      primaryLabel="Track my order"
      onPrimary={() => {
        setDone(null);
        navigation.getParent()?.navigate('Profile', { screen: 'Orders' });
      }}
      secondaryLabel="Keep shopping"
      onSecondary={() => {
        setDone(null);
        navigation.navigate('Shop');
      }}
    />
  );

  const discount = promo ? Math.min(promo.discount, subtotal) : 0;
  const total = subtotal - discount + (lines.length ? 45 : 0);
  const cartKey = lines.map((l) => `${l.product.id}x${l.qty}`).join(',');

  async function applyPromo(code: string = promoInput) {
    const trimmed = code.trim();
    if (!trimmed || !lines.length) return;
    setCheckingPromo(true);
    setPromoError(null);
    try {
      const { data } = await api.post('/promo-codes/validate', {
        code: trimmed,
        context: 'pharmacy',
        pharmacy_id: lines[0].product.pharmacy_id,
        items: lines.map((l) => ({ product_id: l.product.id, qty: l.qty })),
      });
      setPromo({ code: data.code, discount: Number(data.discount) || 0, message: data.message });
      setPromoInput(data.code);
    } catch (e) {
      setPromo(null);
      setPromoError(apiErrorMessage(e));
    } finally {
      setCheckingPromo(false);
    }
  }

  // Basket changed: re-check the applied code so the discount stays accurate.
  useEffect(() => {
    if (promo) applyPromo(promo.code);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [cartKey]);
  const needsPrescription = lines.some((l) => l.product.requires_prescription);

  async function attachPrescription() {
    const result = await DocumentPicker.getDocumentAsync({ type: ['image/*', 'application/pdf'], copyToCacheDirectory: true });
    if (result.canceled || !result.assets?.[0]) return;

    const file = result.assets[0];
    setUploading(true);
    try {
      const form = new FormData();
      form.append('file', { uri: file.uri, name: file.name, type: file.mimeType ?? 'application/octet-stream' } as any);
      const { data } = await api.post('/prescriptions', form, { headers: { 'Content-Type': 'multipart/form-data' } });
      setPrescriptionId(data.id);
      setPrescriptionName(file.name);
    } catch (e) {
      Alert.alert('Upload failed', apiErrorMessage(e));
    } finally {
      setUploading(false);
    }
  }

  async function placeOrder() {
    if (!lines.length) return;
    if (needsPrescription && !prescriptionId) {
      Alert.alert('Prescription required', 'One or more items require a prescription — please attach one.');
      return;
    }
    setPlacing(true);
    try {
      const { data } = await api.post('/orders/checkout', {
        pharmacy_id: lines[0].product.pharmacy_id,
        items: lines.map((l) => ({ product_id: l.product.id, qty: l.qty })),
        delivery_address: deliveryAddress,
        prescription_upload_id: prescriptionId,
        promo_code: promo?.code,
      });
      const orderId = data?.data?.id ?? data?.id;
      const orderNo = data?.data?.order_no ?? data?.order_no ?? null;
      const paidTotal = Number(data?.data?.total ?? data?.total ?? total);
      let paid = false;
      try {
        await api.post(`/orders/${orderId}/pay`, { method });
        paid = true;
      } catch (payError) {
        // Order placed but payment failed/deferred (e.g. awaiting script review) — the modal says so.
      }
      clear();
      setPromo(null);
      setPromoInput('');
      setDone({ paid, orderNo, total: paidTotal });
    } catch (e) {
      Alert.alert('Checkout failed', apiErrorMessage(e));
    } finally {
      setPlacing(false);
    }
  }

  if (!lines.length) {
    return (
      <View style={styles.container}>
        <Text style={type.h2}>Checkout</Text>
        <PaEmptyState message="Your basket is empty. Add something from the pharmacy shop first." />
        <PaButton title="Browse pharmacy" onPress={() => navigation.navigate('Shop')} />
        {successModal}
      </View>
    );
  }

  return (
    <ScrollView style={styles.container} contentContainerStyle={{ padding: 16, paddingBottom: 40 }}>
      <Text style={[type.h2, { marginBottom: 12 }]}>Checkout</Text>

      {lines.map((l) => (
        <View key={l.product.id} style={styles.lineRow}>
          <View style={{ flex: 1 }}>
            <Text style={styles.lineName} numberOfLines={1}>{l.product.name}</Text>
            <Text style={styles.linePrice}>R {(l.product.price * l.qty).toFixed(2)}</Text>
          </View>
          <PaQtyStepper qty={l.qty} onChange={(q) => (q <= 0 ? removeItem(l.product.id) : setQty(l.product.id, q))} max={l.product.stock} />
        </View>
      ))}

      <View style={styles.summaryBox}>
        <PaSpreadRow label="Subtotal" value={`R ${subtotal.toFixed(2)}`} />
        {discount > 0 && <PaSpreadRow label={`Discount (${promo?.code})`} value={`− R ${discount.toFixed(2)}`} />}
        <PaSpreadRow label="Delivery" value="R 45.00" />
        <View style={styles.divider} />
        <PaSpreadRow label="You pay" value={`R ${total.toFixed(2)}`} bold />
      </View>

      <Text style={styles.label}>Promo code</Text>
      <View style={styles.promoRow}>
        <TextInput
          value={promoInput}
          onChangeText={(t) => {
            setPromoInput(t);
            if (promo && t.trim().toUpperCase() !== promo.code) setPromo(null);
            setPromoError(null);
          }}
          placeholder="Have a code?"
          placeholderTextColor={pa.muted2}
          autoCapitalize="characters"
          autoCorrect={false}
          style={[styles.input, { flex: 1 }]}
        />
        {promo ? (
          <PaButton title="Remove" variant="ghost" onPress={() => { setPromo(null); setPromoInput(''); }} style={{ minWidth: 96 }} />
        ) : (
          <PaButton title="Apply" variant="ghost" onPress={() => applyPromo()} loading={checkingPromo} disabled={!promoInput.trim()} style={{ minWidth: 96 }} />
        )}
      </View>
      {promo ? <Text style={[styles.promoMsg, { color: pa.go }]}>{promo.message}</Text> : null}
      {promoError ? <Text style={[styles.promoMsg, { color: pa.signal }]}>{promoError}</Text> : null}

      <Text style={styles.label}>Delivery address</Text>
      <TextInput
        value={deliveryAddress}
        onChangeText={setDeliveryAddress}
        placeholder="Your delivery address"
        placeholderTextColor={pa.muted2}
        style={styles.input}
      />

      {needsPrescription && (
        <PaButton
          title={prescriptionName ? `✓ ${prescriptionName}` : 'Attach prescription'}
          variant="ghost"
          onPress={attachPrescription}
          loading={uploading}
          style={{ marginTop: 12 }}
        />
      )}

      <Text style={styles.label}>Pay with</Text>
      <PaPayMethodPicker value={method} onChange={setMethod} />

      <PaButton
        title={`Place order — R ${total.toFixed(2)}`}
        onPress={placeOrder}
        loading={placing}
        disabled={!deliveryAddress}
        style={{ marginTop: 12 }}
      />
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper, padding: 16 },
  lineRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    backgroundColor: pa.surface,
    borderWidth: 1,
    borderColor: pa.line,
    borderRadius: paRadius.sm,
    padding: 12,
    marginBottom: 8,
    gap: 10,
  },
  lineName: { fontFamily: paFonts.bold, fontSize: 13, color: pa.ink },
  linePrice: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted, marginTop: 2 },
  summaryBox: { marginTop: 12, marginBottom: 4 },
  promoRow: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  promoMsg: { fontFamily: paFonts.regular, fontSize: 12, marginTop: 6 },
  divider: { borderTopWidth: 1, borderTopColor: pa.line, marginVertical: 6 },
  label: { fontFamily: paFonts.black, fontSize: 11, letterSpacing: 1.2, textTransform: 'uppercase', color: pa.muted, marginTop: 18, marginBottom: 8 },
  input: {
    borderWidth: 1,
    borderColor: pa.line,
    borderRadius: paRadius.sm,
    paddingHorizontal: 14,
    paddingVertical: 12,
    fontFamily: paFonts.regular,
    fontSize: 15,
    color: pa.ink,
    backgroundColor: pa.surface,
  },
});
