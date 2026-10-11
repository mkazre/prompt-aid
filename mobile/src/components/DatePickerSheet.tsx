import React, { useMemo, useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { pa, paFonts } from '../theme';
import { MONTHS, parseYmd, sameDay, startOfToday, ymd } from '../services/dates';
import { Sheet, s as ui } from './settings-ui';

const WD = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];

/** Month-grid date picker in a bottom sheet (no native dependency). */
export function DatePickerSheet({
  visible,
  value,
  min,
  title = 'Choose a date',
  onClose,
  onPick,
}: {
  visible: boolean;
  value: string | null;
  min?: string;
  title?: string;
  onClose: () => void;
  onPick: (date: string) => void;
}) {
  const minDate = min ? parseYmd(min) : startOfToday();
  const initial = value ? parseYmd(value) : minDate;
  const [cursor, setCursor] = useState(new Date(initial.getFullYear(), initial.getMonth(), 1));

  const cells = useMemo(() => {
    const first = new Date(cursor.getFullYear(), cursor.getMonth(), 1);
    const offset = (first.getDay() + 6) % 7;
    const days = new Date(cursor.getFullYear(), cursor.getMonth() + 1, 0).getDate();
    const out: (Date | null)[] = [];
    for (let i = 0; i < offset; i++) out.push(null);
    for (let d = 1; d <= days; d++) out.push(new Date(cursor.getFullYear(), cursor.getMonth(), d));
    while (out.length % 7) out.push(null);
    return out;
  }, [cursor]);

  const selected = value ? parseYmd(value) : null;

  return (
    <Sheet visible={visible} onClose={onClose} title={title}>
      <View style={st.head}>
        <Pressable onPress={() => setCursor(new Date(cursor.getFullYear(), cursor.getMonth() - 1, 1))} hitSlop={12} accessibilityLabel="Previous month">
          <Ionicons name="chevron-back" size={22} color={pa.ink} />
        </Pressable>
        <Text style={st.month}>{MONTHS[cursor.getMonth()]} {cursor.getFullYear()}</Text>
        <Pressable onPress={() => setCursor(new Date(cursor.getFullYear(), cursor.getMonth() + 1, 1))} hitSlop={12} accessibilityLabel="Next month">
          <Ionicons name="chevron-forward" size={22} color={pa.ink} />
        </Pressable>
      </View>
      <View style={st.row}>
        {WD.map((w) => <Text key={w} style={st.wd}>{w}</Text>)}
      </View>
      <View style={st.grid}>
        {cells.map((d, i) => {
          const disabled = !d || d < minDate;
          const active = d && selected && sameDay(d, selected);
          return (
            <Pressable key={i} disabled={disabled} onPress={() => d && (onPick(ymd(d)), onClose())} style={st.cell}>
              {d ? (
                <View style={[st.dayWrap, active && { backgroundColor: pa.signal }]}>
                  <Text style={[st.day, disabled && { color: pa.line }, active && { color: '#fff', fontFamily: paFonts.black }]}>{d.getDate()}</Text>
                </View>
              ) : null}
            </Pressable>
          );
        })}
      </View>
    </Sheet>
  );
}

/** Tappable field showing a date and opening DatePickerSheet. */
export function DateField({ label, value, placeholder = 'Select date', onPress }: { label: string; value: string | null; placeholder?: string; onPress: () => void }) {
  return (
    <View style={{ marginBottom: 14, flex: 1 }}>
      <Text style={ui.label}>{label}</Text>
      <Pressable onPress={onPress} style={[ui.input, { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' }]}>
        <Text style={{ fontFamily: paFonts.regular, fontSize: 15, color: value ? pa.ink : pa.muted2 }}>{value ?? placeholder}</Text>
        <Ionicons name="calendar-outline" size={18} color={pa.muted} />
      </Pressable>
    </View>
  );
}

const st = StyleSheet.create({
  head: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 10 },
  month: { fontFamily: paFonts.black, fontSize: 16, color: pa.ink },
  row: { flexDirection: 'row' },
  wd: { flex: 1, textAlign: 'center', fontFamily: paFonts.bold, fontSize: 11, color: pa.muted, marginBottom: 6 },
  grid: { flexDirection: 'row', flexWrap: 'wrap' },
  cell: { width: `${100 / 7}%`, aspectRatio: 1, alignItems: 'center', justifyContent: 'center' },
  dayWrap: { width: 36, height: 36, borderRadius: 18, alignItems: 'center', justifyContent: 'center' },
  day: { fontFamily: paFonts.regular, fontSize: 14, color: pa.ink },
});
