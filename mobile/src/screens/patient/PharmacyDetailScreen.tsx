import React, { useEffect, useState } from 'react';
import { Alert, Image, ScrollView, StyleSheet, Text, View } from 'react-native';
import * as DocumentPicker from 'expo-document-picker';
import { api, apiErrorMessage } from '../../api/client';
import { Pharmacy, Product } from '../../api/types';
import { Input, PrimaryButton, SectionTitle } from '../../components/UI';
import { colors, font, radius, spacing } from '../../theme';
import { useAuth } from '../../context/AuthContext';

export default function PharmacyDetailScreen({ route, navigation }: any) {
  const { pharmacyId } = route.params;
  const { user } = useAuth();
  const [pharmacy, setPharmacy] = useState<Pharmacy | null>(null);
  const [cart, setCart] = useState<Record<number, number>>({});
  const [deliveryAddress, setDeliveryAddress] = useState(user?.patient_profile?.address ?? '');
  const [prescriptionId, setPrescriptionId] = useState<number | null>(null);
  const [prescriptionName, setPrescriptionName] = useState<string | null>(null);
  const [uploading, setUploading] = useState(false);
  const [checkingOut, setCheckingOut] = useState(false);

  useEffect(() => {
    api.get(`/pharmacies/${pharmacyId}`).then(({ data }) => setPharmacy(data.data));
  }, [pharmacyId]);

  const needsPrescription = pharmacy?.products?.some((p) => cart[p.id] > 0 && p.requires_prescription) ?? false;
  const subtotal = pharmacy?.products?.reduce((sum, p) => sum + (cart[p.id] ?? 0) * p.price, 0) ?? 0;
  const total = subtotal + (pharmacy?.delivery_fee ?? 0);

  function setQty(product: Product, qty: number) {
    setCart((prev) => ({ ...prev, [product.id]: Math.max(0, Math.min(qty, product.stock)) }));
  }

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

  async function checkout() {
    const items = Object.entries(cart).filter(([, qty]) => qty > 0).map(([productId, qty]) => ({ product_id: Number(productId), qty }));
    if (!items.length) {
      Alert.alert('Your cart is empty', 'Add at least one item before checking out.');
      return;
    }
    if (needsPrescription && !prescriptionId) {
      Alert.alert('Prescription required', 'One or more items require a prescription — please attach one.');
      return;
    }

    setCheckingOut(true);
    try {
      await api.post('/orders/checkout', {
        pharmacy_id: pharmacyId,
        items,
        delivery_address: deliveryAddress,
        prescription_upload_id: prescriptionId,
      });
      Alert.alert('Order placed!', 'Track it from the Orders tab.', [
        { text: 'OK', onPress: () => navigation.navigate('Orders') },
      ]);
    } catch (e) {
      Alert.alert('Checkout failed', apiErrorMessage(e));
    } finally {
      setCheckingOut(false);
    }
  }

  if (!pharmacy) return null;

  return (
    <ScrollView style={styles.container} contentContainerStyle={{ padding: spacing.lg }}>
      <Text style={styles.title}>{pharmacy.name}</Text>
      <Text style={styles.meta}>{pharmacy.address}, {pharmacy.city}</Text>

      <SectionTitle>Products</SectionTitle>
      {pharmacy.products?.map((product) => (
        <View key={product.id} style={styles.productRow}>
          <Image source={{ uri: `https://picsum.photos/seed/product${product.id}/120/120` }} style={styles.productImage} />
          <View style={{ flex: 1, marginLeft: spacing.sm }}>
            <Text style={styles.productName}>{product.name}</Text>
            <Text style={styles.productPrice}>R{product.price.toFixed(2)}</Text>
            {product.requires_prescription && <Text style={styles.rxLabel}>Prescription required</Text>}
          </View>
          <View style={styles.qtyControl}>
            <Text onPress={() => setQty(product, (cart[product.id] ?? 0) - 1)} style={styles.qtyBtn}>−</Text>
            <Text style={styles.qtyValue}>{cart[product.id] ?? 0}</Text>
            <Text onPress={() => setQty(product, (cart[product.id] ?? 0) + 1)} style={styles.qtyBtn}>+</Text>
          </View>
        </View>
      ))}

      <SectionTitle>Checkout</SectionTitle>
      <Input label="Delivery address" value={deliveryAddress} onChangeText={setDeliveryAddress} placeholder="Your address" />

      {needsPrescription && (
        <View style={{ marginBottom: spacing.md }}>
          <PrimaryButton
            title={prescriptionName ? `✓ ${prescriptionName}` : '📎 Attach prescription'}
            variant="outline"
            onPress={attachPrescription}
            loading={uploading}
          />
        </View>
      )}

      <View style={styles.summary}>
        <View style={styles.summaryRow}><Text style={styles.summaryLabel}>Subtotal</Text><Text style={styles.summaryValue}>R{subtotal.toFixed(2)}</Text></View>
        <View style={styles.summaryRow}><Text style={styles.summaryLabel}>Delivery</Text><Text style={styles.summaryValue}>R{pharmacy.delivery_fee.toFixed(2)}</Text></View>
        <View style={styles.summaryRow}><Text style={styles.summaryLabelBold}>Total</Text><Text style={styles.summaryValueBold}>R{total.toFixed(2)}</Text></View>
      </View>

      <PrimaryButton title="Place order" onPress={checkout} loading={checkingOut} disabled={!deliveryAddress} />
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: colors.gray50 },
  title: { fontFamily: font.bold, fontSize: 20, color: colors.secondary },
  meta: { fontFamily: font.regular, color: colors.gray600, marginTop: 2 },
  productRow: { flexDirection: 'row', alignItems: 'center', backgroundColor: colors.white, borderRadius: radius.md, padding: spacing.sm, marginBottom: spacing.sm },
  productImage: { width: 56, height: 56, borderRadius: radius.sm },
  productName: { fontFamily: font.semibold, fontSize: 13, color: colors.secondary },
  productPrice: { fontFamily: font.medium, fontSize: 12, color: colors.gray600, marginTop: 2 },
  rxLabel: { fontFamily: font.medium, fontSize: 10, color: colors.warning, marginTop: 2 },
  qtyControl: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm },
  qtyBtn: { fontFamily: font.bold, fontSize: 18, color: colors.primary, width: 28, textAlign: 'center' },
  qtyValue: { fontFamily: font.semibold, fontSize: 14, color: colors.gray900, minWidth: 20, textAlign: 'center' },
  summary: { backgroundColor: colors.white, borderRadius: radius.md, padding: spacing.md, marginBottom: spacing.md },
  summaryRow: { flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 4 },
  summaryLabel: { fontFamily: font.regular, color: colors.gray600, fontSize: 13 },
  summaryValue: { fontFamily: font.medium, color: colors.gray900, fontSize: 13 },
  summaryLabelBold: { fontFamily: font.bold, color: colors.secondary, fontSize: 14 },
  summaryValueBold: { fontFamily: font.bold, color: colors.secondary, fontSize: 14 },
});
