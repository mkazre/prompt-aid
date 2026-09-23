import React from 'react';
import { ActivityIndicator, Pressable, StyleProp, StyleSheet, Text, View, ViewStyle } from 'react-native';
import { pa, paFonts, paRadius, type } from '../../theme';

/**
 * Doctor-scoped additions to the shared `pa` component library — kept in a
 * separate file per project convention so the shared index.tsx stays
 * untouched. Mirrors the .m-grid2 / .pa-badge chip-row / compact action
 * patterns from the doctor-today / doctor-encounter mockups.
 */

/** The 2-up (or N-up, cells wrap) stat grid used at the top of "My day" and the vitals row on the encounter screen — mirrors .m-grid2. */
export function PaStatGrid({ children }: { children: React.ReactNode }) {
  return <View style={gridStyles.grid}>{children}</View>;
}

export function PaStatCell({ label, value, valueColor, size = 24 }: { label: string; value: string | number; valueColor?: string; size?: number }) {
  return (
    <View style={gridStyles.cell}>
      <Text style={type.label}>{label}</Text>
      <Text style={[type.num, { fontSize: size, color: valueColor ?? pa.ink, marginTop: 6 }]}>{value}</Text>
    </View>
  );
}

/** Compact inline action button for status transitions (Confirm / Check in / Complete / Cancel) — smaller than the block-level PaButton. */
export function PaChipButton({
  title,
  onPress,
  loading,
  tone = 'ink',
}: {
  title: string;
  onPress: () => void;
  loading?: boolean;
  tone?: 'ink' | 'go' | 'stop';
}) {
  const bg = tone === 'go' ? pa.go : tone === 'stop' ? pa.signalInk : pa.ink;
  return (
    <Pressable
      onPress={onPress}
      disabled={loading}
      style={({ pressed }) => [chipStyles.chip, { backgroundColor: bg, opacity: pressed ? 0.8 : 1 }]}
    >
      {loading ? <ActivityIndicator color="#fff" size="small" /> : <Text style={chipStyles.text}>{title}</Text>}
    </Pressable>
  );
}

/** Wrapping row of small badges — allergy / triage / chronic-condition chips on the encounter screen. */
export function PaChipRow({ children, style }: { children: React.ReactNode; style?: StyleProp<ViewStyle> }) {
  return <View style={[{ flexDirection: 'row', flexWrap: 'wrap', gap: 6 }, style]}>{children}</View>;
}

const gridStyles = StyleSheet.create({
  grid: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    borderWidth: 1,
    borderColor: pa.line,
    marginBottom: 14,
  },
  cell: {
    flexBasis: '50%',
    flexGrow: 1,
    padding: 16,
    borderWidth: StyleSheet.hairlineWidth,
    borderColor: pa.line,
  },
});

const chipStyles = StyleSheet.create({
  chip: {
    borderRadius: paRadius.sm,
    paddingHorizontal: 14,
    paddingVertical: 9,
    alignItems: 'center',
    justifyContent: 'center',
    minHeight: 36,
  },
  text: { fontFamily: paFonts.bold, fontSize: 12, color: '#fff' },
});
