import React from 'react';
import { Pressable, StyleProp, StyleSheet, Text, View, ViewStyle } from 'react-native';
import { Feather } from '@expo/vector-icons';
import { pa, paFonts } from '../theme';

export type FeatherName = keyof typeof Feather.glyphMap;

/** Colourful round Feather icon bubble used across the health screens. */
export function IconCircle({ icon, color, size = 40 }: { icon: FeatherName; color: string; size?: number }) {
  return (
    <View style={{ width: size, height: size, borderRadius: size / 2, backgroundColor: color + '1F', alignItems: 'center', justifyContent: 'center' }}>
      <Feather name={icon} size={Math.round(size * 0.48)} color={color} />
    </View>
  );
}

/** Tappable row: icon circle, title, subtitle, chevron (Record tab links). */
export function HealthLinkRow({ icon, color, title, subtitle, onPress, right }: { icon: FeatherName; color: string; title: string; subtitle?: string; onPress: () => void; right?: React.ReactNode }) {
  return (
    <Pressable onPress={onPress} style={({ pressed }) => [hs.linkRow, pressed && { opacity: 0.7 }]} accessibilityRole="button" accessibilityLabel={title}>
      <IconCircle icon={icon} color={color} />
      <View style={{ flex: 1 }}>
        <Text style={hs.linkTitle}>{title}</Text>
        {subtitle ? <Text style={hs.linkSub} numberOfLines={1}>{subtitle}</Text> : null}
      </View>
      {right}
      <Feather name="chevron-right" size={18} color={pa.muted2} />
    </Pressable>
  );
}

/** Small action chip (icon + label). */
export function ActionChip({ icon, label, onPress, tone, style }: { icon: FeatherName; label: string; onPress: () => void; tone?: 'danger' | 'go' | 'ink'; style?: StyleProp<ViewStyle> }) {
  const fg = tone === 'danger' ? pa.sats.red : tone === 'go' ? '#fff' : tone === 'ink' ? '#fff' : pa.ink;
  const bg = tone === 'go' ? pa.go : tone === 'ink' ? pa.ink : pa.surface;
  const border = tone === 'danger' ? '#F2C4C4' : tone === 'go' ? pa.go : tone === 'ink' ? pa.ink : pa.line;
  return (
    <Pressable onPress={onPress} style={({ pressed }) => [hs.chip, { backgroundColor: bg, borderColor: border }, pressed && { opacity: 0.7 }, style]}>
      <Feather name={icon} size={14} color={fg} />
      <Text style={[hs.chipText, { color: fg }]}>{label}</Text>
    </Pressable>
  );
}

/** Coloured status pill. */
export function Pill({ label, fg, bg }: { label: string; fg: string; bg: string }) {
  return (
    <View style={[hs.pill, { backgroundColor: bg }]}>
      <Text style={[hs.pillText, { color: fg }]}>{label}</Text>
    </View>
  );
}

/** Option pills for a small fixed choice (relationship, gender, blood group). */
export function OptionPills({ options, value, onChange }: { options: { value: string; label: string }[]; value: string | null; onChange: (v: string) => void }) {
  return (
    <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginBottom: 14 }}>
      {options.map((o) => {
        const on = o.value === value;
        return (
          <Pressable key={o.value} onPress={() => onChange(o.value)} style={[hs.opt, on && { backgroundColor: pa.ink, borderColor: pa.ink }]}>
            <Text style={[hs.optText, on && { color: '#fff' }]}>{o.label}</Text>
          </Pressable>
        );
      })}
    </View>
  );
}

export const hs = StyleSheet.create({
  screen: { flex: 1, backgroundColor: pa.paper },
  card: { backgroundColor: pa.surface, borderRadius: 14, borderWidth: 1, borderColor: pa.line, padding: 14, marginBottom: 12 },
  linkRow: { flexDirection: 'row', alignItems: 'center', gap: 12, backgroundColor: pa.surface, borderRadius: 14, borderWidth: 1, borderColor: pa.line, padding: 12, marginBottom: 8 },
  linkTitle: { fontFamily: paFonts.bold, fontSize: 15, color: pa.ink },
  linkSub: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted, marginTop: 2 },
  chip: { flexDirection: 'row', alignItems: 'center', gap: 6, borderWidth: 1, borderRadius: 999, paddingHorizontal: 12, paddingVertical: 7 },
  chipText: { fontFamily: paFonts.bold, fontSize: 12 },
  pill: { borderRadius: 999, paddingHorizontal: 10, paddingVertical: 4, alignSelf: 'flex-start' },
  pillText: { fontFamily: paFonts.black, fontSize: 11 },
  opt: { borderWidth: 1, borderColor: pa.line, borderRadius: 999, paddingHorizontal: 12, paddingVertical: 7, backgroundColor: pa.surface },
  optText: { fontFamily: paFonts.bold, fontSize: 12, color: pa.ink },
  title: { fontFamily: paFonts.bold, fontSize: 15, color: pa.ink },
  sub: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted, marginTop: 2 },
  label: { fontFamily: paFonts.black, fontSize: 11, letterSpacing: 1.2, textTransform: 'uppercase', color: pa.muted, marginTop: 14, marginBottom: 8 },
  row: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  actions: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginTop: 12 },
  note: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted, lineHeight: 17 },
});
