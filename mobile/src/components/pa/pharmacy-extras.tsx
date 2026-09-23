import React from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { pa, paFonts, paRadius } from '../../theme';

/**
 * Small additions to the shared `pa` component layer, scoped to the
 * pharmacy vendor screens. Kept in a separate file per project convention
 * (the main `pa` index is read-only).
 */

/** A horizontal filter pill — used for the order-status tab row. */
export function PaFilterChip({
  label,
  active,
  onPress,
}: {
  label: string;
  active?: boolean;
  onPress: () => void;
}) {
  return (
    <Pressable
      onPress={onPress}
      style={[chipStyles.chip, active ? chipStyles.chipActive : chipStyles.chipInactive]}
    >
      <Text style={[chipStyles.text, active ? chipStyles.textActive : chipStyles.textInactive]}>{label}</Text>
    </Pressable>
  );
}

/** A compact +/- quantity stepper with a numeric readout, used on Stock. */
export function PaStepper({
  value,
  onChange,
  min = 0,
  max,
}: {
  value: number;
  onChange: (next: number) => void;
  min?: number;
  max?: number;
}) {
  const dec = () => onChange(Math.max(min, value - 1));
  const inc = () => onChange(max != null ? Math.min(max, value + 1) : value + 1);

  return (
    <View style={stepperStyles.wrap}>
      <Pressable onPress={dec} style={stepperStyles.btn} hitSlop={8}>
        <Text style={stepperStyles.btnText}>−</Text>
      </Pressable>
      <Text style={stepperStyles.value}>{value}</Text>
      <Pressable onPress={inc} style={stepperStyles.btn} hitSlop={8}>
        <Text style={stepperStyles.btnText}>+</Text>
      </Pressable>
    </View>
  );
}

/** A two-column key/value spread row, matching .pa-spread in the mockups. */
export function PaSpreadRow({ label, value }: { label: string; value: string }) {
  return (
    <View style={spreadStyles.row}>
      <Text style={spreadStyles.label}>{label}</Text>
      <Text style={spreadStyles.value}>{value}</Text>
    </View>
  );
}

const chipStyles = StyleSheet.create({
  chip: {
    paddingHorizontal: 14,
    paddingVertical: 8,
    borderRadius: paRadius.pill,
    borderWidth: 1,
    marginRight: 8,
  },
  chipActive: { backgroundColor: pa.ink, borderColor: pa.ink },
  chipInactive: { backgroundColor: pa.surface, borderColor: pa.line },
  text: { fontFamily: paFonts.bold, fontSize: 12 },
  textActive: { color: '#fff' },
  textInactive: { color: pa.inkSoft },
});

const stepperStyles = StyleSheet.create({
  wrap: { flexDirection: 'row', alignItems: 'center', gap: 4 },
  btn: {
    width: 32,
    height: 32,
    borderRadius: paRadius.sm,
    borderWidth: 1,
    borderColor: pa.line,
    alignItems: 'center',
    justifyContent: 'center',
  },
  btnText: { fontFamily: paFonts.bold, fontSize: 18, color: pa.ink, marginTop: -2 },
  value: { fontFamily: paFonts.black, fontSize: 15, color: pa.ink, minWidth: 30, textAlign: 'center' },
});

const spreadStyles = StyleSheet.create({
  row: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    paddingVertical: 8,
    borderBottomWidth: 1,
    borderBottomColor: pa.lineSoft,
  },
  label: { fontFamily: paFonts.regular, fontSize: 13, color: pa.muted },
  value: { fontFamily: paFonts.bold, fontSize: 13, color: pa.ink },
});
