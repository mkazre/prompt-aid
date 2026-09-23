import React, { useCallback, useMemo, useState } from 'react';
import { Alert, FlatList, RefreshControl, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { api, apiErrorMessage } from '../../api/client';
import { Product } from '../../api/types';
import { PaBadge, PaButton, PaCard, PaEmptyState, PaInput } from '../../components/pa';
import { PaStepper } from '../../components/pa/pharmacy-extras';
import { pa, paFonts, type } from '../../theme';

const LOW_STOCK_THRESHOLD = 15;

export default function PharmacyStockScreen() {
  const [products, setProducts] = useState<Product[]>([]);
  const [loading, setLoading] = useState(false);
  const [search, setSearch] = useState('');
  const [pendingStock, setPendingStock] = useState<Record<number, number>>({});
  const [savingId, setSavingId] = useState<number | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const { data } = await api.get('/vendor/products');
      setProducts(data.data ?? []);
      setPendingStock({});
    } finally {
      setLoading(false);
    }
  }, []);

  useFocusEffect(useCallback(() => { load(); }, [load]));

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return products;
    return products.filter((p) => p.name.toLowerCase().includes(q) || (p.category ?? '').toLowerCase().includes(q));
  }, [products, search]);

  function stockFor(product: Product) {
    return pendingStock[product.id] ?? product.stock;
  }

  function setQty(product: Product, next: number) {
    setPendingStock((prev) => ({ ...prev, [product.id]: Math.max(0, next) }));
  }

  async function saveStock(product: Product) {
    const stock = stockFor(product);
    if (stock === product.stock) return;
    setSavingId(product.id);
    try {
      const { data } = await api.post(`/vendor/products/${product.id}/stock`, { stock });
      const updated: Product = data.data ?? data;
      setProducts((prev) => prev.map((p) => (p.id === product.id ? updated : p)));
      setPendingStock((prev) => {
        const next = { ...prev };
        delete next[product.id];
        return next;
      });
    } catch (e) {
      Alert.alert('Could not update stock', apiErrorMessage(e));
    } finally {
      setSavingId(null);
    }
  }

  async function toggleActive(product: Product) {
    setSavingId(product.id);
    try {
      const { data } = await api.post(`/vendor/products/${product.id}/stock`, {
        stock: product.stock,
        is_active: !product.is_active,
      });
      const updated: Product = data.data ?? data;
      setProducts((prev) => prev.map((p) => (p.id === product.id ? updated : p)));
    } catch (e) {
      Alert.alert('Could not update product', apiErrorMessage(e));
    } finally {
      setSavingId(null);
    }
  }

  return (
    <View style={styles.container}>
      <Text style={type.h2}>Stock</Text>
      <PaInput placeholder="Search products" value={search} onChangeText={setSearch} style={{ marginTop: 12 }} />
      <FlatList
        data={filtered}
        keyExtractor={(p) => String(p.id)}
        refreshControl={<RefreshControl refreshing={loading} onRefresh={load} tintColor={pa.signal} />}
        ListEmptyComponent={!loading ? <PaEmptyState message="No products found." /> : null}
        contentContainerStyle={{ paddingBottom: 48 }}
        renderItem={({ item }) => {
          const qty = stockFor(item);
          const dirty = qty !== item.stock;
          const low = item.stock <= LOW_STOCK_THRESHOLD;
          return (
            <PaCard style={{ marginBottom: 10 }}>
              <View style={styles.row}>
                <View style={{ flex: 1 }}>
                  <Text style={styles.name}>{item.name}</Text>
                  <Text style={styles.meta}>{item.category ?? 'Uncategorised'} · R{item.price.toFixed(2)}</Text>
                </View>
                <PaBadge
                  label={item.stock === 0 ? 'Out of stock' : low ? `${item.stock} left` : `${item.stock} in stock`}
                  tone={item.stock === 0 ? 'stop' : low ? 'wait' : 'go'}
                />
              </View>

              <View style={styles.actionsRow}>
                <PaStepper value={qty} onChange={(next) => setQty(item, next)} min={0} />
                <View style={{ flexDirection: 'row', gap: 8 }}>
                  <PaButton
                    title={item.is_active ? 'Deactivate' : 'Activate'}
                    variant="ghost"
                    onPress={() => toggleActive(item)}
                    loading={savingId === item.id}
                    style={styles.smallBtn}
                  />
                  <PaButton
                    title="Save"
                    onPress={() => saveStock(item)}
                    disabled={!dirty}
                    loading={savingId === item.id}
                    style={styles.smallBtn}
                  />
                </View>
              </View>
            </PaCard>
          );
        }}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper, padding: 16 },
  row: { flexDirection: 'row', alignItems: 'flex-start', justifyContent: 'space-between', gap: 10 },
  name: { fontFamily: paFonts.bold, fontSize: 14, color: pa.ink },
  meta: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted, marginTop: 2 },
  actionsRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginTop: 12 },
  smallBtn: { paddingVertical: 8, paddingHorizontal: 14 },
});
