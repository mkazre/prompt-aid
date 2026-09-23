import React from 'react';
import { Modal, Pressable, StyleSheet, Text, View } from 'react-native';
import { pa, paFonts, paRadius, type } from '../../theme';
import type { TriageLevel } from '../../services/triage';

/**
 * Extra components for the patient triage flow, built on top of the shared
 * `pa` component library (mobile/src/components/pa/index.tsx, which is
 * read-only for this work). Kept in a separate file per project convention
 * so the shared library stays untouched.
 *
 * `TriagePromptModal` mirrors the "South African Triage Scale" sheet on
 * new-ui/promptaid-mobile/home.html (#m-triage) — the home screen can show
 * it directly (e.g. on cold start / AppState "active") without needing any
 * change to this file.
 */

const SATS_SIGNALS: { level: TriageLevel; letter: string; title: string; desc: string; bg: string; wash: string }[] = [
  { level: 'red', letter: 'R', title: 'Help right now', desc: 'Not breathing properly, chest pain, heavy bleeding', bg: pa.sats.red, wash: '#FBEAEA' },
  { level: 'orange', letter: 'O', title: 'Very urgent', desc: 'Burn, deep cut, head knock, severe pain', bg: pa.sats.orange, wash: '#FCEEE2' },
  { level: 'yellow', letter: 'Y', title: 'Seen today', desc: 'Fever, vomiting, a wound, sick child', bg: pa.sats.yellow, wash: pa.beaconWash },
  { level: 'green', letter: 'G', title: 'It can wait', desc: 'Repeat meds, routine check', bg: pa.sats.green, wash: pa.goWash },
];

/**
 * The triage entry sheet. Renders as a bottom-sheet-style modal. The caller
 * (home screen) controls visibility with `visible` and is responsible for
 * navigating to the `TriageStart` route (see mobile/src/screens/patient/
 * TriageStartScreen.tsx) with `{ selfReported: level }` when a colour is
 * tapped — this component does not import navigation itself so it stays
 * reusable from any screen.
 */
export function TriagePromptModal({
  visible,
  onDismiss,
  onSelectLevel,
}: {
  visible: boolean;
  onDismiss: () => void;
  onSelectLevel: (level: TriageLevel) => void;
}) {
  return (
    <Modal visible={visible} transparent animationType="slide" onRequestClose={onDismiss}>
      <View style={sheetStyles.scrim}>
        <View style={sheetStyles.sheet}>
          <View style={sheetStyles.headRow}>
            <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
              <View style={{ width: 6, height: 6, backgroundColor: pa.signal }} />
              <Text style={[type.label, { color: pa.signal }]}>South African Triage Scale</Text>
            </View>
            <Pressable onPress={onDismiss} hitSlop={12}>
              <Text style={sheetStyles.close}>×</Text>
            </Pressable>
          </View>
          <Text style={sheetStyles.title}>How serious is it?</Text>
          <Text style={sheetStyles.subtitle}>Answer a few quick questions. We will find the nearest place that can treat it.</Text>

          {SATS_SIGNALS.map((s) => (
            <Pressable
              key={s.level}
              onPress={() => onSelectLevel(s.level)}
              style={({ pressed }) => [sheetStyles.signalRow, { backgroundColor: s.wash, opacity: pressed ? 0.85 : 1 }]}
            >
              <View style={[sheetStyles.signalBadge, { backgroundColor: s.bg }]}>
                <Text style={sheetStyles.signalLetter}>{s.letter}</Text>
              </View>
              <View style={{ flex: 1 }}>
                <Text style={sheetStyles.signalTitle}>{s.title}</Text>
                <Text style={sheetStyles.signalDesc}>{s.desc}</Text>
              </View>
            </Pressable>
          ))}

          <Pressable onPress={onDismiss} style={sheetStyles.ghostBtn}>
            <Text style={sheetStyles.ghostBtnText}>No emergency</Text>
          </Pressable>
        </View>
      </View>
    </Modal>
  );
}

const sheetStyles = StyleSheet.create({
  scrim: { flex: 1, backgroundColor: 'rgba(16,16,18,0.55)', justifyContent: 'flex-end' },
  sheet: { backgroundColor: pa.surface, borderTopLeftRadius: 12, borderTopRightRadius: 12, padding: 20, paddingBottom: 32 },
  headRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 6 },
  close: { fontSize: 22, color: pa.muted },
  title: { ...type.h2, color: pa.ink, marginBottom: 4 },
  subtitle: { ...type.body, fontSize: 13, color: pa.muted, marginBottom: 16 },
  signalRow: { flexDirection: 'row', alignItems: 'center', gap: 12, borderRadius: paRadius.sm, padding: 12, marginBottom: 10 },
  signalBadge: { width: 44, height: 44, borderRadius: paRadius.sm, alignItems: 'center', justifyContent: 'center' },
  signalLetter: { fontFamily: paFonts.black, fontSize: 16, color: '#fff' },
  signalTitle: { fontFamily: paFonts.bold, fontSize: 15, color: pa.ink },
  signalDesc: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted, marginTop: 2 },
  ghostBtn: { marginTop: 8, borderWidth: 1, borderColor: pa.line, borderRadius: paRadius.sm, paddingVertical: 14, alignItems: 'center' },
  ghostBtnText: { fontFamily: paFonts.bold, fontSize: 14, color: pa.ink },
});

/** The 4-segment red/orange/yellow/green progress bar shown across all triage steps — matches .pa-satsbar. */
export function PaSatsBar({ active }: { active?: TriageLevel }) {
  const segments: { level: TriageLevel; label: string; bg: string }[] = [
    { level: 'red', label: 'Red', bg: pa.sats.red },
    { level: 'orange', label: 'Orange', bg: pa.sats.orange },
    { level: 'yellow', label: 'Yellow', bg: pa.sats.yellow },
    { level: 'green', label: 'Green', bg: pa.sats.green },
  ];
  return (
    <View style={barStyles.row}>
      {segments.map((s) => {
        const on = s.level === active;
        return (
          <View key={s.level} style={[barStyles.seg, { backgroundColor: on ? s.bg : pa.lineSoft }]}>
            <Text style={[barStyles.text, { color: on ? '#fff' : pa.muted }]}>{s.label}</Text>
          </View>
        );
      })}
    </View>
  );
}

const barStyles = StyleSheet.create({
  row: { flexDirection: 'row', gap: 4 },
  seg: { flex: 1, paddingVertical: 8, alignItems: 'center', borderRadius: paRadius.sm },
  text: { fontFamily: paFonts.bold, fontSize: 11 },
});

/** The big colour banner on the result screen — matches .pa-verdict. */
export function PaVerdictBanner({
  level,
  eyebrow,
  title,
  message,
}: {
  level: TriageLevel;
  eyebrow: string;
  title: string;
  message: string;
}) {
  const bg = pa.sats[level];
  return (
    <View style={[verdictStyles.wrap, { backgroundColor: bg }]}>
      <Text style={verdictStyles.eyebrow}>{eyebrow}</Text>
      <Text style={verdictStyles.title}>{title}</Text>
      <Text style={verdictStyles.message}>{message}</Text>
    </View>
  );
}

const verdictStyles = StyleSheet.create({
  wrap: { padding: 20 },
  eyebrow: { fontFamily: paFonts.bold, fontSize: 12, color: 'rgba(255,255,255,0.85)', marginBottom: 6 },
  title: { fontFamily: paFonts.black, fontSize: 26, color: '#fff', marginBottom: 8 },
  message: { fontFamily: paFonts.regular, fontSize: 14, color: '#fff', lineHeight: 20 },
});

/** A 0–10 pain scale picker (no native slider dependency in this project). */
export function PaPainScale({ value, onChange }: { value: number; onChange: (v: number) => void }) {
  return (
    <View>
      <Text style={paintStyles.label}>Pain · {value} of 10</Text>
      <View style={paintStyles.row}>
        {Array.from({ length: 11 }, (_, i) => i).map((n) => {
          const on = n === value;
          return (
            <Pressable key={n} onPress={() => onChange(n)} style={[paintStyles.dot, on && paintStyles.dotOn]}>
              <Text style={[paintStyles.dotText, on && paintStyles.dotTextOn]}>{n}</Text>
            </Pressable>
          );
        })}
      </View>
    </View>
  );
}

const paintStyles = StyleSheet.create({
  label: { fontFamily: paFonts.black, fontSize: 11, letterSpacing: 1.2, textTransform: 'uppercase', color: pa.muted, marginBottom: 8 },
  row: { flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginBottom: 14 },
  dot: { width: 30, height: 30, borderRadius: paRadius.sm, borderWidth: 1, borderColor: pa.line, alignItems: 'center', justifyContent: 'center' },
  dotOn: { backgroundColor: pa.ink, borderColor: pa.ink },
  dotText: { fontFamily: paFonts.bold, fontSize: 12, color: pa.ink },
  dotTextOn: { color: '#fff' },
});

/** A single tappable chip — matches .m-chips span, used for who/age/mobility/breathing choices. */
export function PaChoiceChip({ label, on, onPress }: { label: string; on: boolean; onPress: () => void }) {
  return (
    <Pressable onPress={onPress} style={[chipStyles.chip, on && chipStyles.chipOn]}>
      <Text style={[chipStyles.text, on && chipStyles.textOn]}>{label}</Text>
    </Pressable>
  );
}

const chipStyles = StyleSheet.create({
  chip: { backgroundColor: pa.surface, borderWidth: 1, borderColor: pa.line, paddingHorizontal: 14, paddingVertical: 10, borderRadius: paRadius.sm },
  chipOn: { backgroundColor: pa.ink, borderColor: pa.ink },
  text: { fontFamily: paFonts.bold, fontSize: 13, color: pa.ink },
  textOn: { color: '#fff' },
});

/** A single symptom tile — matches .pa-symptom, with a "critical" tint for symptoms with a red/orange floor. */
export function PaSymptomTile({
  label,
  on,
  critical,
  onPress,
}: {
  label: string;
  on: boolean;
  critical?: boolean;
  onPress: () => void;
}) {
  return (
    <Pressable
      onPress={onPress}
      style={[
        tileStyles.tile,
        critical && tileStyles.tileCritical,
        on && tileStyles.tileOn,
      ]}
    >
      <Text style={[tileStyles.text, on && tileStyles.textOn]}>{label}</Text>
    </Pressable>
  );
}

const tileStyles = StyleSheet.create({
  tile: { width: '48%', borderWidth: 1, borderColor: pa.line, borderRadius: paRadius.sm, paddingVertical: 14, paddingHorizontal: 10, marginBottom: 8, alignItems: 'center' },
  tileCritical: { borderColor: pa.signal },
  tileOn: { backgroundColor: pa.ink, borderColor: pa.ink },
  text: { fontFamily: paFonts.bold, fontSize: 13, color: pa.ink, textAlign: 'center' },
  textOn: { color: '#fff' },
});

/** A discriminator checklist row grouped under a colour badge — matches .pa-check inside triage-flags.html. */
export function PaCheckRow({ label, on, onPress }: { label: string; on: boolean; onPress: () => void }) {
  return (
    <Pressable onPress={onPress} style={checkStyles.row}>
      <View style={[checkStyles.box, on && checkStyles.boxOn]}>{on ? <Text style={checkStyles.tick}>✓</Text> : null}</View>
      <Text style={checkStyles.label}>{label}</Text>
    </Pressable>
  );
}

const checkStyles = StyleSheet.create({
  row: { flexDirection: 'row', alignItems: 'center', gap: 12, minHeight: 44, borderBottomWidth: 1, borderBottomColor: pa.lineSoft },
  box: { width: 20, height: 20, borderRadius: 4, borderWidth: 1, borderColor: pa.line, alignItems: 'center', justifyContent: 'center' },
  boxOn: { backgroundColor: pa.ink, borderColor: pa.ink },
  tick: { color: '#fff', fontSize: 12, fontFamily: paFonts.bold },
  label: { flex: 1, fontFamily: paFonts.regular, fontSize: 14, color: pa.ink },
});
