import React from 'react';
import {
  ActivityIndicator,
  Pressable,
  StyleProp,
  StyleSheet,
  Text,
  TextInput,
  TextInputProps,
  TextStyle,
  View,
  ViewStyle,
} from 'react-native';
import { pa, paFonts, paRadius, type } from '../../theme';

/**
 * Real component layer for the "clinical dispatch" design system — near-
 * square corners, hairline borders (never shadows for flat surfaces),
 * signal-red as the one loud color. Mirrors the .pa-card/.pa-btn/.pa-badge
 * classes from promptaid.css so the app and the website read as one
 * product. New screens should use these, not the old UI.tsx compat layer.
 */

export function PaCard({ children, style, dark }: { children: React.ReactNode; style?: StyleProp<ViewStyle>; dark?: boolean }) {
  return <View style={[styles.card, dark && styles.cardDark, style]}>{children}</View>;
}

export function PaButton({
  title,
  onPress,
  loading,
  disabled,
  variant = 'primary',
  style,
}: {
  title: string;
  onPress: () => void;
  loading?: boolean;
  disabled?: boolean;
  variant?: 'primary' | 'ghost' | 'beacon' | 'dark-ghost';
  style?: StyleProp<ViewStyle>;
}) {
  const bg = variant === 'primary' ? pa.signal : variant === 'beacon' ? pa.beacon : 'transparent';
  const border = variant === 'ghost' ? pa.line : variant === 'dark-ghost' ? '#4A4A4E' : bg;
  const textColor = variant === 'primary' ? '#fff' : variant === 'beacon' ? pa.ink : variant === 'dark-ghost' ? '#fff' : pa.ink;

  return (
    <Pressable
      onPress={onPress}
      disabled={disabled || loading}
      style={({ pressed }) => [
        btnStyles.btn,
        { backgroundColor: bg, borderColor: border, opacity: pressed ? 0.85 : disabled ? 0.5 : 1 },
        style,
      ]}
    >
      {loading ? <ActivityIndicator color={textColor} /> : <Text style={[btnStyles.text, { color: textColor }]}>{title}</Text>}
    </Pressable>
  );
}

export function PaInput(props: TextInputProps & { label?: string }) {
  return (
    <View style={{ marginBottom: 14 }}>
      {props.label ? <Text style={inputStyles.label}>{props.label}</Text> : null}
      <TextInput placeholderTextColor={pa.muted2} {...props} style={[inputStyles.input, props.style]} />
    </View>
  );
}

const SATS_META: Record<string, { bg: string; label: string }> = {
  red: { bg: pa.sats.red, label: 'Red' },
  orange: { bg: pa.sats.orange, label: 'Orange' },
  yellow: { bg: pa.sats.yellow, label: 'Yellow' },
  green: { bg: pa.sats.green, label: 'Green' },
};

export function PaBadge({ status, label, tone }: { status?: string; label?: string; tone?: 'go' | 'wait' | 'stop' | 'ink' }) {
  const resolved = tone ?? statusTone(status ?? '');
  const { bg, fg } = toneColors(resolved);
  return (
    <View style={[badgeStyles.badge, { backgroundColor: bg }]}>
      <Text style={[badgeStyles.text, { color: fg }]}>{label ?? headline(status ?? '')}</Text>
    </View>
  );
}

function statusTone(status: string): 'go' | 'wait' | 'stop' | 'ink' {
  const go = ['completed', 'confirmed', 'paid', 'active', 'approved', 'available', 'accepted'];
  const stop = ['cancelled', 'no_show', 'failed', 'suspended', 'rejected', 'refunded'];
  if (go.includes(status)) return 'go';
  if (stop.includes(status)) return 'stop';
  return 'wait';
}

function toneColors(tone: 'go' | 'wait' | 'stop' | 'ink') {
  switch (tone) {
    case 'go':
      return { bg: pa.goWash, fg: pa.go };
    case 'stop':
      return { bg: pa.signalWash, fg: pa.signalInk };
    case 'ink':
      return { bg: pa.ink, fg: '#fff' };
    default:
      return { bg: pa.beaconWash, fg: '#7A5C00' };
  }
}

export function PaSatsChip({ level }: { level: 'red' | 'orange' | 'yellow' | 'green' }) {
  const m = SATS_META[level];
  return (
    <View style={[badgeStyles.badge, { backgroundColor: m.bg }]}>
      <Text style={[badgeStyles.text, { color: '#fff' }]}>{m.label}</Text>
    </View>
  );
}

export function PaSectionLabel({ children, style }: { children: React.ReactNode; style?: StyleProp<TextStyle> }) {
  return <Text style={[type.label, style]}>{children}</Text>;
}

export function PaEyebrow({ children }: { children: React.ReactNode }) {
  return (
    <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8, marginBottom: 8 }}>
      <View style={{ width: 6, height: 6, backgroundColor: pa.signal }} />
      <Text style={[type.label, { color: pa.signal }]}>{children}</Text>
    </View>
  );
}

export function PaEmptyState({ message }: { message: string }) {
  return (
    <View style={{ paddingVertical: 40, alignItems: 'center' }}>
      <Text style={{ color: pa.muted, fontFamily: paFonts.regular, fontSize: 13 }}>{message}</Text>
    </View>
  );
}

export function headline(s: string) {
  return s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

export function initials(name?: string | null) {
  if (!name) return '?';
  return name.split(' ').map((p) => p[0]).filter(Boolean).slice(0, 2).join('').toUpperCase();
}

export function PaAvatar({ name, size = 44, round = true }: { name?: string | null; size?: number; round?: boolean }) {
  return (
    <View
      style={{
        width: size,
        height: size,
        borderRadius: round ? size / 2 : paRadius.sm,
        backgroundColor: pa.ink,
        alignItems: 'center',
        justifyContent: 'center',
      }}
    >
      <Text style={{ color: pa.beacon, fontFamily: paFonts.black, fontSize: size * 0.36 }}>{initials(name)}</Text>
    </View>
  );
}

/** The 4-tile quick action grid used on every role's home screen. */
export function PaQuickAction({ number, label, onPress }: { number: string; label: string; onPress: () => void }) {
  return (
    <Pressable onPress={onPress} style={({ pressed }) => [quickStyles.tile, { opacity: pressed ? 0.7 : 1 }]}>
      <Text style={[type.num, { color: pa.signal, fontSize: 20, marginBottom: 8 }]}>{number}</Text>
      <Text style={quickStyles.label}>{label}</Text>
    </Pressable>
  );
}

/** A tappable list row with a title, subtitle and trailing badge/chevron — matches .m-row. */
export function PaRow({
  title,
  subtitle,
  right,
  onPress,
}: {
  title: string;
  subtitle?: string;
  right?: React.ReactNode;
  onPress?: () => void;
}) {
  return (
    <Pressable onPress={onPress} style={rowStyles.row}>
      <View style={{ flex: 1 }}>
        <Text style={rowStyles.title}>{title}</Text>
        {subtitle ? <Text style={rowStyles.subtitle}>{subtitle}</Text> : null}
      </View>
      {right}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  card: {
    backgroundColor: pa.surface,
    borderRadius: paRadius.sm,
    borderWidth: 1,
    borderColor: pa.line,
    padding: 16,
  },
  cardDark: {
    backgroundColor: pa.ink,
    borderColor: '#2A2A2E',
  },
});

const btnStyles = StyleSheet.create({
  btn: {
    borderRadius: paRadius.sm,
    borderWidth: 1,
    paddingVertical: 14,
    alignItems: 'center',
    justifyContent: 'center',
  },
  text: { fontFamily: paFonts.bold, fontSize: 15 },
});

const inputStyles = StyleSheet.create({
  label: { fontFamily: paFonts.black, fontSize: 11, letterSpacing: 1.2, textTransform: 'uppercase', color: pa.muted, marginBottom: 6 },
  input: {
    borderWidth: 1,
    borderColor: pa.line,
    borderRadius: paRadius.sm,
    paddingHorizontal: 14,
    paddingVertical: 12,
    fontFamily: paFonts.regular,
    fontSize: 15,
    color: pa.ink,
    backgroundColor: pa.surface,
  },
});

const badgeStyles = StyleSheet.create({
  badge: { paddingHorizontal: 10, paddingVertical: 4, borderRadius: paRadius.sm, alignSelf: 'flex-start' },
  text: { fontFamily: paFonts.bold, fontSize: 11 },
});

const quickStyles = StyleSheet.create({
  tile: {
    width: '48%',
    backgroundColor: pa.surface,
    borderWidth: 1,
    borderColor: pa.line,
    borderRadius: paRadius.sm,
    padding: 16,
    marginBottom: 10,
  },
  label: { fontFamily: paFonts.bold, fontSize: 14, color: pa.ink },
});

const rowStyles = StyleSheet.create({
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingVertical: 14,
    borderBottomWidth: 1,
    borderBottomColor: pa.lineSoft,
    gap: 10,
  },
  title: { fontFamily: paFonts.bold, fontSize: 14, color: pa.ink },
  subtitle: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted, marginTop: 2 },
});
