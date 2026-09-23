import React from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { pa, paFonts, paRadius } from '../../theme';
import type { ThirdPartyCategory } from '../../api/types';

/**
 * Shared bits for the "partner" role (third_party) screens — labs, imaging,
 * physio and other diagnostic/specialist providers all share one workflow
 * underneath (still literally the LabRequest model), so this file keeps the
 * copy generic per the provider's own `category` instead of hardcoding lab
 * language into every screen.
 */

const CATEGORY_LABEL: Record<ThirdPartyCategory, string> = {
  lab: 'Lab',
  imaging: 'Imaging',
  physio: 'Physio',
  optometry: 'Optometry',
  dental: 'Dental',
  dietetics: 'Dietetics',
  audiology: 'Audiology',
  home_nursing: 'Home Nursing',
  other: 'Specialist',
};

const CATEGORY_EMOJI: Record<ThirdPartyCategory, string> = {
  lab: '🧪',
  imaging: '🩻',
  physio: '🏃',
  optometry: '👓',
  dental: '🦷',
  dietetics: '🥗',
  audiology: '👂',
  home_nursing: '🏠',
  other: '⚕️',
};

/** The generic noun for a single requested item — "test", "scan", "session"... */
const CATEGORY_NOUN: Record<ThirdPartyCategory, string> = {
  lab: 'test',
  imaging: 'scan',
  physio: 'session',
  optometry: 'exam',
  dental: 'procedure',
  dietetics: 'consult',
  audiology: 'assessment',
  home_nursing: 'visit',
  other: 'request',
};

export function categoryLabel(category?: ThirdPartyCategory | string | null): string {
  if (!category) return 'Partner';
  return CATEGORY_LABEL[category as ThirdPartyCategory] ?? 'Partner';
}

export function categoryEmoji(category?: ThirdPartyCategory | string | null): string {
  if (!category) return '⚕️';
  return CATEGORY_EMOJI[category as ThirdPartyCategory] ?? '⚕️';
}

export function categoryNoun(category?: ThirdPartyCategory | string | null, plural = false): string {
  const noun = (category && CATEGORY_NOUN[category as ThirdPartyCategory]) || 'request';
  return plural ? `${noun}s` : noun;
}

/** "24 min" / "3 hrs" — how long since an ISO timestamp. */
export function elapsedLabel(iso?: string | null): string {
  if (!iso) return '';
  const ms = Date.now() - new Date(iso).getTime();
  const minutes = Math.max(0, Math.round(ms / 60000));
  if (minutes < 60) return `${minutes} min`;
  const hours = Math.round(minutes / 60);
  return `${hours} hr${hours === 1 ? '' : 's'}`;
}

export interface PaChip {
  label: string;
  value: string;
}

/** Horizontal filter chip row — matches the mockup's .m-chips. */
export function PaChipRow({ chips, active, onChange }: { chips: PaChip[]; active: string; onChange: (value: string) => void }) {
  return (
    <ScrollView horizontal showsHorizontalScrollIndicator={false} style={chipStyles.row} contentContainerStyle={{ gap: 6 }}>
      {chips.map((chip) => {
        const isOn = chip.value === active;
        return (
          <Pressable key={chip.value} onPress={() => onChange(chip.value)} style={[chipStyles.chip, isOn && chipStyles.chipOn]}>
            <Text style={[chipStyles.text, isOn && chipStyles.textOn]}>{chip.label}</Text>
          </Pressable>
        );
      })}
    </ScrollView>
  );
}

/** A label/value line with a bottom hairline — matches .pa-spread rows. */
export function PaSpreadRow({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <View style={spreadStyles.row}>
      <Text style={spreadStyles.label}>{label}</Text>
      <Text style={spreadStyles.value}>{value}</Text>
    </View>
  );
}

/** Dashed attachment slot — matches .pa-slot, tap to trigger a file picker. */
export function PaFileSlot({ label, fileName, onPress }: { label: string; fileName?: string | null; onPress: () => void }) {
  return (
    <Pressable onPress={onPress} style={slotStyles.slot}>
      <Text style={slotStyles.text}>{fileName ? `📎 ${fileName}` : label}</Text>
    </Pressable>
  );
}

/** A checkbox row — matches .pa-check. */
export function PaCheckbox({ label, checked, onToggle }: { label: string; checked: boolean; onToggle: () => void }) {
  return (
    <Pressable onPress={onToggle} style={checkStyles.row}>
      <View style={[checkStyles.box, checked && checkStyles.boxOn]}>{checked ? <Text style={checkStyles.mark}>✓</Text> : null}</View>
      <Text style={checkStyles.label}>{label}</Text>
    </Pressable>
  );
}

const chipStyles = StyleSheet.create({
  row: { marginBottom: 12 },
  chip: { backgroundColor: pa.surface, borderWidth: 1, borderColor: pa.line, borderRadius: paRadius.sm, paddingHorizontal: 13, paddingVertical: 9 },
  chipOn: { backgroundColor: pa.ink, borderColor: pa.ink },
  text: { fontFamily: paFonts.bold, fontSize: 12, color: pa.ink },
  textOn: { color: '#fff' },
});

const spreadStyles = StyleSheet.create({
  row: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    paddingVertical: 8,
    borderBottomWidth: 1,
    borderBottomColor: pa.lineSoft,
  },
  label: { fontFamily: paFonts.regular, fontSize: 13, color: pa.muted },
  value: { fontFamily: paFonts.bold, fontSize: 13, color: pa.ink },
});

const slotStyles = StyleSheet.create({
  slot: {
    height: 120,
    borderWidth: 1,
    borderStyle: 'dashed',
    borderColor: pa.line,
    borderRadius: paRadius.sm,
    alignItems: 'center',
    justifyContent: 'center',
    marginVertical: 12,
    paddingHorizontal: 16,
  },
  text: { fontFamily: paFonts.regular, fontSize: 13, color: pa.muted, textAlign: 'center' },
});

const checkStyles = StyleSheet.create({
  row: { flexDirection: 'row', alignItems: 'center', gap: 10, paddingVertical: 6 },
  box: { width: 20, height: 20, borderWidth: 1, borderColor: pa.line, borderRadius: paRadius.sm, alignItems: 'center', justifyContent: 'center', backgroundColor: pa.surface },
  boxOn: { backgroundColor: pa.ink, borderColor: pa.ink },
  mark: { color: '#fff', fontSize: 12, fontFamily: paFonts.bold },
  label: { fontFamily: paFonts.regular, fontSize: 13, color: pa.ink, flexShrink: 1 },
});
