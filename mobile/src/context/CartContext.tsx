import React, { createContext, useContext, useMemo, useState } from 'react';
import { Product } from '../api/types';

/**
 * Pharmacy cart — kept in memory for the lifetime of the app. The real
 * checkout endpoint (`POST /orders/checkout`) only accepts items from a
 * single pharmacy at a time, so adding a product from a different pharmacy
 * replaces the cart rather than mixing pharmacies in one order.
 */
export interface CartLine {
  product: Product;
  qty: number;
}

interface CartContextValue {
  pharmacyId: number | null;
  lines: CartLine[];
  totalCount: number;
  subtotal: number;
  addItem: (product: Product, qty?: number) => void;
  setQty: (productId: number, qty: number) => void;
  removeItem: (productId: number) => void;
  clear: () => void;
}

const CartContext = createContext<CartContextValue | undefined>(undefined);

export function CartProvider({ children }: { children: React.ReactNode }) {
  const [pharmacyId, setPharmacyId] = useState<number | null>(null);
  const [items, setItems] = useState<Record<number, CartLine>>({});

  function addItem(product: Product, qty = 1) {
    setItems((prev) => {
      const next = pharmacyId !== null && pharmacyId !== product.pharmacy_id ? {} : { ...prev };
      const existingQty = next[product.id]?.qty ?? 0;
      next[product.id] = { product, qty: Math.max(1, Math.min(existingQty + qty, product.stock)) };
      return next;
    });
    setPharmacyId(product.pharmacy_id);
  }

  function setQty(productId: number, qty: number) {
    setItems((prev) => {
      const line = prev[productId];
      if (!line) return prev;
      if (qty <= 0) {
        const next = { ...prev };
        delete next[productId];
        return next;
      }
      return { ...prev, [productId]: { ...line, qty: Math.min(qty, line.product.stock) } };
    });
  }

  function removeItem(productId: number) {
    setItems((prev) => {
      const next = { ...prev };
      delete next[productId];
      return next;
    });
  }

  function clear() {
    setItems({});
    setPharmacyId(null);
  }

  const lines = useMemo(() => Object.values(items), [items]);
  const totalCount = useMemo(() => lines.reduce((sum, l) => sum + l.qty, 0), [lines]);
  const subtotal = useMemo(() => lines.reduce((sum, l) => sum + l.qty * l.product.price, 0), [lines]);

  const value = useMemo(
    () => ({ pharmacyId, lines, totalCount, subtotal, addItem, setQty, removeItem, clear }),
    [pharmacyId, lines, totalCount, subtotal]
  );

  return <CartContext.Provider value={value}>{children}</CartContext.Provider>;
}

export function useCart() {
  const ctx = useContext(CartContext);
  if (!ctx) throw new Error('useCart must be used within CartProvider');
  return ctx;
}
