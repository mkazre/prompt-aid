import React, { useState } from 'react';
import { Image, ScrollView, StyleSheet, Text, View } from 'react-native';
import { Product } from '../../api/types';
import { PaBadge, PaButton, PaEyebrow } from '../../components/pa';
import { PaQtyStepper } from '../../components/pa/patient-commerce-extras';
import { pa, paFonts, paRadius, type } from '../../theme';
import { useCart } from '../../context/CartContext';

/**
 * Single product detail — matches `product.html`. There is no
 * `GET /products/{id}` endpoint on the backend (only the list endpoint), so
 * the full `Product` object is passed as a navigation param from
 * ShopScreen rather than re-fetched here.
 */
export default function ProductDetailScreen({ route, navigation }: any) {
  const product: Product = route.params.product;
  const { addItem } = useCart();
  const [qty, setQty] = useState(1);

  function addToBasket() {
    addItem(product, qty);
    navigation.navigate('Checkout');
  }

  return (
    <ScrollView style={styles.container} contentContainerStyle={{ padding: 16, paddingBottom: 40 }}>
      {product.image ? (
        <Image source={{ uri: product.image }} style={styles.image} />
      ) : (
        <View style={styles.imageSlot}>
          <Text style={styles.imageSlotText}>Product image</Text>
        </View>
      )}

      {product.pharmacy_name ? <PaEyebrow>{product.pharmacy_name}</PaEyebrow> : null}
      <Text style={type.h2}>{product.name}</Text>
      <Text style={[type.num, styles.price]}>R {product.price.toFixed(2)}</Text>

      <View style={styles.infoRow}>
        <Text style={styles.infoLabel}>Category</Text>
        <Text style={styles.infoValue}>{product.category ?? '—'}</Text>
      </View>
      <View style={styles.infoRow}>
        <Text style={styles.infoLabel}>In stock</Text>
        <Text style={styles.infoValue}>{product.stock}</Text>
      </View>
      <View style={styles.infoRowLast}>
        <Text style={styles.infoLabel}>Prescription</Text>
        {product.requires_prescription ? <PaBadge tone="wait" label="Required" /> : <PaBadge tone="go" label="Not required" />}
      </View>

      {product.description ? <Text style={styles.description}>{product.description}</Text> : null}

      <View style={styles.qtySection}>
        <Text style={styles.qtyLabel}>Quantity</Text>
        <PaQtyStepper qty={qty} onChange={(v) => setQty(Math.max(1, v))} max={product.stock} />
      </View>

      <View style={{ gap: 8, marginTop: 16 }}>
        <PaButton title="Add to basket" onPress={addToBasket} disabled={product.stock <= 0} />
        {product.requires_prescription && (
          <PaButton title="Upload a script instead" variant="ghost" onPress={() => navigation.navigate('ScriptUpload')} />
        )}
      </View>
      {product.stock <= 0 && <Text style={styles.outOfStock}>Out of stock at this pharmacy.</Text>}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper },
  image: { width: '100%', height: 190, borderRadius: paRadius.sm, marginBottom: 14, backgroundColor: pa.lineSoft },
  imageSlot: { width: '100%', height: 190, borderRadius: paRadius.sm, marginBottom: 14, backgroundColor: pa.lineSoft, alignItems: 'center', justifyContent: 'center' },
  imageSlotText: { fontFamily: paFonts.bold, color: pa.muted },
  price: { fontSize: 26, marginTop: 6, marginBottom: 8, color: pa.ink },
  infoRow: { flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 8, borderBottomWidth: 1, borderBottomColor: pa.lineSoft },
  infoRowLast: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', paddingVertical: 8 },
  infoLabel: { fontFamily: paFonts.regular, fontSize: 13, color: pa.muted },
  infoValue: { fontFamily: paFonts.bold, fontSize: 13, color: pa.ink },
  description: { fontFamily: paFonts.regular, fontSize: 14, color: pa.inkSoft, marginTop: 14, lineHeight: 20 },
  qtySection: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginTop: 20 },
  qtyLabel: { fontFamily: paFonts.black, fontSize: 11, letterSpacing: 1.2, textTransform: 'uppercase', color: pa.muted },
  outOfStock: { fontFamily: paFonts.regular, fontSize: 12, color: pa.signalInk, marginTop: 8, textAlign: 'center' },
});
