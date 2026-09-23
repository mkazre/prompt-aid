import React from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { pa, paFonts, paRadius, type } from '../../theme';

/**
 * Extra components for the patient commerce/record slice (shop, checkout,
 * record, invoices, profile) — additive to `mobile/src/components/pa/index.tsx`,
 * which is read-only. Mirrors `.m-chips` / `.pa-badge` / step-tracker patterns
 * from the promptaid-mobile mockups.
 */

/** Horizontal scrolling filter chip row — matches `.m-chips`. */
export function PaChips({
  options,
  value,
  onChange,
}: {
  options: string[];
  value: string;
  onChange: (v: string) => void;
}) {
  return (
    <ScrollView horizontal showsHorizontalScrollIndicator={false} style={{ marginBottom: 12 }}>
      <View style={{ flexDirection: 'row', gap: 6 }}>
        {options.map((opt) => {
          const on = opt === value;
          return (
            <Pressable key={opt} onPress={() => onChange(opt)} style={[chipStyles.chip, on && chipStyles.chipOn]}>
              <Text style={[chipStyles.text, on && chipStyles.textOn]}>{opt}</Text>
            </Pressable>
          );
        })}
      </View>
    </ScrollView>
  );
}

const chipStyles = StyleSheet.create({
  chip: { backgroundColor: pa.surface, borderWidth: 1, borderColor: pa.line, borderRadius: paRadius.sm, paddingHorizontal: 13, paddingVertical: 9 },
  chipOn: { backgroundColor: pa.ink, borderColor: pa.ink },
  text: { fontFamily: paFonts.bold, fontSize: 12, color: pa.ink },
  textOn: { color: '#fff' },
});

/** Quantity +/- stepper used on product detail and cart rows. */
export function PaQtyStepper({
  qty,
  onChange,
  max,
}: {
  qty: number;
  onChange: (qty: number) => void;
  max?: number;
}) {
  return (
    <View style={stepperStyles.row}>
      <Pressable onPress={() => onChange(Math.max(0, qty - 1))} style={stepperStyles.btn}>
        <Text style={stepperStyles.btnText}>−</Text>
      </Pressable>
      <Text style={stepperStyles.value}>{qty}</Text>
      <Pressable
        onPress={() => onChange(max !== undefined ? Math.min(max, qty + 1) : qty + 1)}
        style={stepperStyles.btn}
      >
        <Text style={stepperStyles.btnText}>+</Text>
      </Pressable>
    </View>
  );
}

const stepperStyles = StyleSheet.create({
  row: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  btn: { width: 30, height: 30, borderWidth: 1, borderColor: pa.line, alignItems: 'center', justifyContent: 'center', borderRadius: paRadius.sm },
  btnText: { fontFamily: paFonts.black, fontSize: 16, color: pa.ink },
  value: { fontFamily: paFonts.black, fontSize: 15, color: pa.ink, minWidth: 20, textAlign: 'center' },
});

export type PayMethod = 'cash' | 'card' | 'mobile_money' | 'insurance';

const PAY_METHODS: { value: PayMethod; label: string; hint: string }[] = [
  { value: 'card', label: 'Card', hint: 'Visa, Mastercard' },
  { value: 'mobile_money', label: 'Mobile wallet', hint: 'SnapScan, Zapper' },
  { value: 'insurance', label: 'Medical scheme', hint: 'Claim against my scheme' },
  { value: 'cash', label: 'Cash / on collection', hint: 'Pay at the pharmacy' },
];

/** Radio-style payment method picker — matches the `.m-row` selectable list in checkout.html. */
export function PaPayMethodPicker({ value, onChange }: { value: PayMethod; onChange: (m: PayMethod) => void }) {
  return (
    <View>
      {PAY_METHODS.map((m) => {
        const on = m.value === value;
        return (
          <Pressable key={m.value} onPress={() => onChange(m.value)} style={[payStyles.row, on && payStyles.rowOn]}>
            <View>
              <Text style={payStyles.label}>{m.label}</Text>
              <Text style={payStyles.hint}>{m.hint}</Text>
            </View>
            <View style={[payStyles.dot, on && payStyles.dotOn]} />
          </Pressable>
        );
      })}
    </View>
  );
}

const payStyles = StyleSheet.create({
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    backgroundColor: pa.surface,
    borderWidth: 1,
    borderColor: pa.line,
    borderRadius: paRadius.sm,
    paddingHorizontal: 16,
    paddingVertical: 14,
    marginBottom: 8,
    minHeight: 56,
  },
  rowOn: { borderColor: pa.ink, backgroundColor: pa.beaconWash },
  label: { fontFamily: paFonts.bold, fontSize: 14, color: pa.ink },
  hint: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted, marginTop: 2 },
  dot: { width: 16, height: 16, borderWidth: 1, borderColor: pa.ink, backgroundColor: '#fff' },
  dotOn: { backgroundColor: pa.ink },
});

export interface StepItem {
  label: string;
  time: string;
  state: 'done' | 'now' | 'todo';
}

/** Order-tracking step list — matches `.pa-steps` on orders.html. */
export function PaSteps({ steps }: { steps: StepItem[] }) {
  return (
    <View style={{ marginTop: 4 }}>
      {steps.map((s, i) => (
        <View key={s.label} style={stepsStyles.row}>
          <View
            style={[
              stepsStyles.dot,
              s.state === 'done' && stepsStyles.dotDone,
              s.state === 'now' && stepsStyles.dotNow,
            ]}
          />
          <Text style={[stepsStyles.label, s.state === 'todo' && stepsStyles.labelTodo]}>{s.label}</Text>
          <Text style={stepsStyles.time}>{s.time}</Text>
        </View>
      ))}
    </View>
  );
}

const stepsStyles = StyleSheet.create({
  row: { flexDirection: 'row', alignItems: 'center', paddingVertical: 6, gap: 10 },
  dot: { width: 8, height: 8, borderRadius: 4, backgroundColor: pa.line },
  dotDone: { backgroundColor: pa.go },
  dotNow: { backgroundColor: pa.signal },
  label: { flex: 1, fontFamily: paFonts.bold, fontSize: 13, color: pa.ink },
  labelTodo: { color: pa.muted2 },
  time: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted },
});

/** A labelled figure-value spread row — matches `.pa-spread` summary lines. */
export function PaSpreadRow({ label, value, bold, tone }: { label: string; value: string; bold?: boolean; tone?: 'go' | 'signal' }) {
  const color = tone === 'go' ? pa.go : tone === 'signal' ? pa.signal : pa.ink;
  return (
    <View style={spreadStyles.row}>
      <Text style={spreadStyles.label}>{label}</Text>
      <Text style={[spreadStyles.value, { color }, bold && spreadStyles.valueBold]}>{value}</Text>
    </View>
  );
}

const spreadStyles = StyleSheet.create({
  row: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', paddingVertical: 5 },
  label: { fontFamily: paFonts.regular, fontSize: 13, color: pa.muted },
  value: { fontFamily: paFonts.bold, fontSize: 13, color: pa.ink },
  valueBold: { fontSize: 15, fontFamily: paFonts.black },
});

/** Section label matching `.pa-label` spacing used between record.html blocks. */
export function PaSection({ children }: { children: React.ReactNode }) {
  return <Text style={[type.label, { marginTop: 18, marginBottom: 8 }]}>{children}</Text>;
}
