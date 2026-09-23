import React from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { pa, paFonts, paRadius, type } from '../../theme';

/**
 * Extra components for the driver role only, built on top of the shared
 * `pa` component library (mobile/src/components/pa/index.tsx, which is
 * read-only for this work). Kept in a separate file per project convention
 * so the shared library stays untouched.
 */

/** Horizontal scrolling filter chips — matches .m-chips on driver-trips.html. */
export function PaChipRow({
  options,
  value,
  onChange,
}: {
  options: { key: string; label: string }[];
  value: string;
  onChange: (key: string) => void;
}) {
  return (
    <ScrollView horizontal showsHorizontalScrollIndicator={false} style={{ marginBottom: 12 }}>
      <View style={{ flexDirection: 'row', gap: 6 }}>
        {options.map((opt) => {
          const on = opt.key === value;
          return (
            <Pressable
              key={opt.key}
              onPress={() => onChange(opt.key)}
              style={[chipStyles.chip, on && chipStyles.chipOn]}
            >
              <Text style={[chipStyles.text, on && chipStyles.textOn]}>{opt.label}</Text>
            </Pressable>
          );
        })}
      </View>
    </ScrollView>
  );
}

const chipStyles = StyleSheet.create({
  chip: { backgroundColor: pa.surface, borderWidth: 1, borderColor: pa.line, paddingHorizontal: 13, paddingVertical: 9, borderRadius: paRadius.sm },
  chipOn: { backgroundColor: pa.ink, borderColor: pa.ink },
  text: { fontFamily: paFonts.bold, fontSize: 12, color: pa.ink },
  textOn: { color: '#fff' },
});

/** Mon–Sun earnings bar chart — matches the bar chart on driver-earnings.html. */
export function PaWeekBars({ data, height = 120 }: { data: { label: string; value: number; today?: boolean }[]; height?: number }) {
  const max = Math.max(1, ...data.map((d) => d.value));
  return (
    <View style={[barStyles.row, { height }]}>
      {data.map((d, i) => (
        <View key={i} style={barStyles.col}>
          <View
            style={[
              barStyles.bar,
              { height: Math.max(2, (d.value / max) * (height - 20)), backgroundColor: d.today ? pa.signal : pa.beacon },
            ]}
          />
          <Text style={barStyles.label}>{d.label}</Text>
        </View>
      ))}
    </View>
  );
}

const barStyles = StyleSheet.create({
  row: { flexDirection: 'row', alignItems: 'flex-end', gap: 6 },
  col: { flex: 1, alignItems: 'center', justifyContent: 'flex-end', height: '100%' },
  bar: { width: '100%' },
  label: { fontFamily: paFonts.regular, fontSize: 10, color: pa.muted, marginTop: 4 },
});

/** A single fares/fees line item — matches .pa-spread rows on driver-earnings.html. */
export function PaLedgerRow({ label, value, negative }: { label: string; value: string; negative?: boolean }) {
  return (
    <View style={ledgerStyles.row}>
      <Text style={ledgerStyles.label}>{label}</Text>
      <Text style={[ledgerStyles.value, negative && { color: pa.signalInk }]}>{value}</Text>
    </View>
  );
}

const ledgerStyles = StyleSheet.create({
  row: { flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 9, borderBottomWidth: 1, borderBottomColor: pa.lineSoft },
  label: { ...type.body, fontSize: 14, color: pa.muted },
  value: { fontFamily: paFonts.bold, fontSize: 14, color: pa.ink },
});

/** A big dark stat header — matches .pa-dark blocks (earnings summary, home overlay). */
export function PaDarkStat({ label, value, sub }: { label: string; value: string; sub?: string }) {
  return (
    <View style={darkStyles.wrap}>
      <Text style={[type.label, { color: '#8A857C' }]}>{label}</Text>
      <Text style={[type.num, { color: '#fff', fontSize: 36, marginTop: 2 }]}>{value}</Text>
      {sub ? <Text style={darkStyles.sub}>{sub}</Text> : null}
    </View>
  );
}

const darkStyles = StyleSheet.create({
  wrap: { backgroundColor: pa.ink, padding: 18, borderRadius: paRadius.sm, marginBottom: 12 },
  sub: { fontFamily: paFonts.regular, fontSize: 12, color: '#8A857C', marginTop: 4 },
});

export function timeAgo(iso?: string | null): string {
  if (!iso) return '';
  const diffMs = Date.now() - new Date(iso).getTime();
  const mins = Math.round(diffMs / 60000);
  if (mins < 1) return 'just now';
  if (mins < 60) return `${mins} min ago`;
  const hrs = Math.round(mins / 60);
  return `${hrs} hr ago`;
}

export function formatTime(iso?: string | null): string {
  if (!iso) return '';
  const d = new Date(iso);
  return d.toLocaleTimeString('en-ZA', { hour: '2-digit', minute: '2-digit' });
}

export function formatDate(iso?: string | null): string {
  if (!iso) return '';
  const d = new Date(iso);
  return d.toLocaleDateString('en-ZA', { day: 'numeric', month: 'short', year: 'numeric' });
}

export function daysUntil(iso?: string | null): number | null {
  if (!iso) return null;
  const target = new Date(iso).getTime();
  return Math.ceil((target - Date.now()) / 86400000);
}
