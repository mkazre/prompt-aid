import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { FlatList, Image, Pressable, RefreshControl, StyleSheet, Text, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useFocusEffect } from '@react-navigation/native';
import { api } from '../../api/client';
import { Product } from '../../api/types';
import { PaEmptyState, PaEyebrow } from '../../components/pa';
import { PaChips } from '../../components/pa/patient-commerce-extras';
import { pa, paFonts, paRadius, type } from '../../theme';
import { useCart } from '../../context/CartContext';
import WidgetHost from '../../widgets/WidgetHost';

const CATEGORY_OPTIONS = ['All', 'Prescription', 'OTC', 'Devices', 'Vitamins'];

/**
 * Global product catalog browse — matches `shop.html`. Backed by
 * `GET /products` (across all pharmacies), with client-side search +
 * category filtering since the endpoint's `category` filter is exact-match
 * and the mockup's chip set (Prescription / OTC / Devices / Vitamins) is a
 * simplified view over free-text categories seeded on the backend.
 */
export default function ShopScreen({ navigation, route }: any) {
  const insets = useSafeAreaInsets();
  const [products, setProducts] = useState<Product[]>([]);
  const [loading, setLoading] = useState(false);
  const [search, setSearch] = useState('');
  const [category, setCategory] = useState('All');
  // Set by the "Shop by category" widget (or route params from other widgets).
  const [serviceCategory, setServiceCategory] = useState<{ id: number; name: string } | null>(null);
  const [refreshKey, setRefreshKey] = useState(0);
  // The seeded shop banner is the "upload a script" CTA, so the hardcoded row hides while it's on.
  const [bannerOn, setBannerOn] = useState(true);
  const { totalCount } = useCart();

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const params: Record<string, string | number> = {};
      if (search) params.search = search;
      if (serviceCategory) params.service_category_id = serviceCategory.id;
      const { data } = await api.get('/products', { params });
      setProducts(data.data);
    } finally {
      setLoading(false);
    }
  }, [search, serviceCategory]);

  const rp = route?.params ?? {};
  useEffect(() => {
    if (rp.categoryId === undefined && rp.search === undefined) return;
    if (rp.categoryId) setServiceCategory({ id: Number(rp.categoryId), name: rp.categoryName ?? 'Category' });
    if (typeof rp.search === 'string') setSearch(rp.search);
    navigation.setParams({ categoryId: undefined, categoryName: undefined, search: undefined });
  }, [rp.categoryId, rp.search]);

  useFocusEffect(useCallback(() => { load(); }, [load]));

  const filtered = useMemo(() => {
    if (category === 'All') return products;
    if (category === 'Prescription') return products.filter((p) => p.requires_prescription);
    if (category === 'OTC') return products.filter((p) => !p.requires_prescription);
    return products.filter((p) => (p.category ?? '').toLowerCase().includes(category.toLowerCase()));
  }, [products, category]);

  return (
    <View style={[styles.container, { paddingTop: insets.top + 16 }]}>
      <View style={styles.headerRow}>
        <Text style={type.h2}>Pharmacy &amp; tests</Text>
        <Pressable onPress={() => navigation.navigate('Checkout')} style={styles.cartBadge}>
          <Text style={styles.cartBadgeText}>{totalCount}</Text>
        </Pressable>
      </View>

      <TextInput
        value={search}
        onChangeText={setSearch}
        placeholder="Medicine, device or test"
        placeholderTextColor={pa.muted2}
        style={styles.textInput}
      />

      {!bannerOn ? (
      <Pressable style={styles.scriptRow} onPress={() => navigation.navigate('ScriptUpload')}>
        <View>
          <Text style={styles.scriptTitle}>Upload a script</Text>
          <Text style={styles.scriptHint}>A pharmacist reviews it first</Text>
        </View>
        <Text style={styles.scriptArrow}>→</Text>
      </Pressable>
      ) : null}

      <PaChips options={CATEGORY_OPTIONS} value={category} onChange={setCategory} />
      {serviceCategory ? (
        <Pressable onPress={() => setServiceCategory(null)} style={styles.activeCat}>
          <Text style={styles.activeCatText}>{serviceCategory.name}  ✕</Text>
        </Pressable>
      ) : null}

      <FlatList
        data={filtered}
        keyExtractor={(p) => String(p.id)}
        numColumns={2}
        columnWrapperStyle={{ gap: 10 }}
        contentContainerStyle={{ paddingBottom: 40, gap: 10 }}
        ListEmptyComponent={!loading ? <PaEmptyState message="No products found." /> : null}
        refreshControl={<RefreshControl refreshing={loading} onRefresh={() => { load(); setRefreshKey((k) => k + 1); }} />}
        ListHeaderComponent={
          <WidgetHost
            screen="shop"
            refreshKey={refreshKey}
            onLoaded={(keys) => setBannerOn(keys.includes('shop_promo_banner'))}
            style={{ marginBottom: 16 }}
            context={{ onCategory: (c) => { setCategory('All'); setServiceCategory(c); } }}
          />
        }
        renderItem={({ item }) => (
          <Pressable style={styles.tile} onPress={() => navigation.navigate('ProductDetail', { product: item })}>
            {item.image ? (
              <Image source={{ uri: item.image }} style={styles.tileImage} />
            ) : (
              <View style={styles.tileSlot}>
                <Text style={styles.tileSlotText}>{item.name.slice(0, 2).toUpperCase()}</Text>
              </View>
            )}
            <Text style={styles.tileName} numberOfLines={2}>{item.name}</Text>
            <Text style={[type.num, styles.tilePrice]}>R {item.price.toFixed(2)}</Text>
            {item.requires_prescription && <PaEyebrow>Script needed</PaEyebrow>}
          </Pressable>
        )}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper, padding: 16 },
  headerRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 },
  cartBadge: { width: 32, height: 32, borderRadius: paRadius.sm, backgroundColor: pa.signal, alignItems: 'center', justifyContent: 'center' },
  cartBadgeText: { color: '#fff', fontFamily: paFonts.black, fontSize: 14 },
  textInput: {
    borderWidth: 1,
    borderColor: pa.line,
    borderRadius: paRadius.sm,
    paddingHorizontal: 14,
    paddingVertical: 12,
    fontFamily: paFonts.regular,
    fontSize: 15,
    color: pa.ink,
    backgroundColor: pa.surface,
    marginBottom: 10,
  },
  scriptRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    backgroundColor: pa.ink,
    borderRadius: paRadius.sm,
    paddingHorizontal: 16,
    paddingVertical: 14,
    marginBottom: 12,
  },
  scriptTitle: { fontFamily: paFonts.black, fontSize: 14, color: '#fff' },
  scriptHint: { fontFamily: paFonts.regular, fontSize: 12, color: '#8A857C', marginTop: 2 },
  scriptArrow: { color: '#fff', fontFamily: paFonts.black, fontSize: 16 },
  activeCat: { alignSelf: 'flex-start', backgroundColor: pa.ink, borderRadius: 999, paddingHorizontal: 12, paddingVertical: 6, marginBottom: 10 },
  activeCatText: { fontFamily: paFonts.bold, fontSize: 12, color: '#fff' },
  tile: { flex: 1, backgroundColor: pa.surface, borderWidth: 1, borderColor: pa.line, borderRadius: paRadius.sm, padding: 12 },
  tileImage: { width: '100%', height: 70, borderRadius: paRadius.sm, marginBottom: 8 },
  tileSlot: { width: '100%', height: 70, borderRadius: paRadius.sm, marginBottom: 8, backgroundColor: pa.lineSoft, alignItems: 'center', justifyContent: 'center' },
  tileSlotText: { fontFamily: paFonts.black, fontSize: 18, color: pa.muted },
  tileName: { fontFamily: paFonts.bold, fontSize: 13, color: pa.ink, lineHeight: 17 },
  tilePrice: { fontSize: 15, marginTop: 6, color: pa.ink },
});
