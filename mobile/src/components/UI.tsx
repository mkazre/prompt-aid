import React from 'react';
import {
  ActivityIndicator,
  Pressable,
  StyleProp,
  StyleSheet,
  Text,
  TextInput,
  TextInputProps,
  View,
  ViewStyle,
} from 'react-native';
import { colors, font, radius, shadow, spacing, statusColor } from '../theme';

export function Card({ children, style, ...rest }: Omit<React.ComponentProps<typeof View>, 'style'> & { children: React.ReactNode; style?: StyleProp<ViewStyle> }) {
  return <View style={[styles.card, style]} {...rest}>{children}</View>;
}

export function PrimaryButton({
  title,
  onPress,
  loading,
  disabled,
  variant = 'primary',
}: {
  title: string;
  onPress: () => void;
  loading?: boolean;
  disabled?: boolean;
  variant?: 'primary' | 'outline' | 'danger';
}) {
  const isOutline = variant === 'outline';
  const bg = variant === 'danger' ? colors.danger : variant === 'primary' ? colors.primary : 'transparent';
  return (
    <Pressable
      onPress={onPress}
      disabled={disabled || loading}
      style={({ pressed }) => [
        styles.button,
        { backgroundColor: bg, opacity: pressed ? 0.85 : disabled ? 0.5 : 1 },
        isOutline && styles.buttonOutline,
      ]}
    >
      {loading ? (
        <ActivityIndicator color={isOutline ? colors.primary : colors.white} />
      ) : (
        <Text style={[styles.buttonText, isOutline && { color: colors.secondary }]}>{title}</Text>
      )}
    </Pressable>
  );
}

export function Input(props: TextInputProps & { label?: string }) {
  return (
    <View style={{ marginBottom: spacing.md }}>
      {props.label ? <Text style={styles.label}>{props.label}</Text> : null}
      <TextInput
        placeholderTextColor={colors.gray500}
        {...props}
        style={[styles.input, props.style]}
      />
    </View>
  );
}

export function Badge({ status, label }: { status: string; label?: string }) {
  const { fg, bg } = statusColor(status);
  return (
    <View style={[styles.badge, { backgroundColor: bg }]}>
      <Text style={[styles.badgeText, { color: fg }]}>{label ?? headline(status)}</Text>
    </View>
  );
}

export function SectionTitle({ children }: { children: React.ReactNode }) {
  return <Text style={styles.sectionTitle}>{children}</Text>;
}

export function EmptyState({ message }: { message: string }) {
  return (
    <View style={{ paddingVertical: spacing.xl, alignItems: 'center' }}>
      <Text style={{ color: colors.gray500, fontFamily: font.regular }}>{message}</Text>
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

const AVATAR_COLORS = [colors.danger, colors.primary, colors.accent, colors.success, colors.warning];

export function avatarColorFor(seed: number | string) {
  const n = typeof seed === 'number' ? seed : seed.split('').reduce((a, c) => a + c.charCodeAt(0), 0);
  return AVATAR_COLORS[n % AVATAR_COLORS.length];
}

export function Avatar({ name, size = 44, seed }: { name?: string | null; size?: number; seed?: number | string }) {
  const bg = avatarColorFor(seed ?? name ?? '?');
  return (
    <View style={{ width: size, height: size, borderRadius: size / 2, backgroundColor: bg, alignItems: 'center', justifyContent: 'center' }}>
      <Text style={{ color: colors.white, fontFamily: font.bold, fontSize: size * 0.38 }}>{initials(name)}</Text>
    </View>
  );
}

export function StatCard({
  label,
  value,
  sublabel,
  icon,
  iconBg,
}: {
  label: string;
  value: string | number;
  sublabel?: string;
  icon: string;
  iconBg: string;
}) {
  return (
    <Card style={statStyles.card}>
      <Text style={statStyles.label} numberOfLines={1}>{label}</Text>
      <Text style={statStyles.value}>{value}</Text>
      {sublabel ? <Text style={statStyles.sub} numberOfLines={1}>{sublabel}</Text> : null}
      <View style={[statStyles.iconCircle, { backgroundColor: iconBg }]}>
        <Text style={{ fontSize: 22 }}>{icon}</Text>
      </View>
    </Card>
  );
}

export function WeeklyBarChart({ data }: { data: { day: string; count: number }[] }) {
  const max = Math.max(20, ...data.map((d) => d.count));
  return (
    <View style={statStyles.chartRow}>
      {data.map((d, i) => {
        const h = Math.max(6, (d.count / max) * 160);
        return (
          <View key={d.day + i} style={statStyles.chartCol}>
            <View style={statStyles.chartTrack}>
              <View style={[statStyles.chartBar, { height: h, backgroundColor: d.count > 0 ? colors.primary : colors.primaryLight }]} />
            </View>
            <Text style={statStyles.chartLabel}>{d.day[0]}</Text>
          </View>
        );
      })}
    </View>
  );
}

export function IconAction({ icon, label, onPress, color }: { icon: string; label?: string; onPress: () => void; color?: string }) {
  return (
    <Pressable onPress={onPress} style={({ pressed }) => [statStyles.iconAction, { opacity: pressed ? 0.6 : 1 }]}>
      <Text style={{ fontSize: 16 }}>{icon}</Text>
      {label ? <Text style={[statStyles.iconActionLabel, color ? { color } : null]}>{label}</Text> : null}
    </Pressable>
  );
}

const statStyles = StyleSheet.create({
  card: {
    flex: 1,
    position: 'relative',
    overflow: 'hidden',
    minHeight: 118,
  },
  label: {
    fontFamily: font.medium,
    fontSize: 11,
    color: colors.gray500,
    textTransform: 'uppercase',
    letterSpacing: 0.4,
    maxWidth: '70%',
  },
  value: {
    fontFamily: font.bold,
    fontSize: 28,
    color: colors.gray900,
    marginTop: 6,
  },
  sub: {
    fontFamily: font.regular,
    fontSize: 11,
    color: colors.primary,
    marginTop: 4,
    maxWidth: '75%',
  },
  iconCircle: {
    position: 'absolute',
    top: spacing.md,
    right: spacing.md,
    width: 44,
    height: 44,
    borderRadius: 22,
    alignItems: 'center',
    justifyContent: 'center',
  },
  chartRow: { flexDirection: 'row', alignItems: 'flex-end', justifyContent: 'space-between' },
  chartCol: { alignItems: 'center', flex: 1 },
  chartTrack: {
    width: 22,
    height: 160,
    backgroundColor: colors.primaryLight,
    borderRadius: 11,
    justifyContent: 'flex-end',
    overflow: 'hidden',
  },
  chartBar: { width: '100%', borderRadius: 11 },
  chartLabel: { marginTop: 8, fontSize: 11, fontFamily: font.medium, color: colors.gray600 },
  iconAction: { flexDirection: 'row', alignItems: 'center', gap: 4, paddingVertical: 4, paddingHorizontal: 8 },
  iconActionLabel: { fontFamily: font.medium, fontSize: 12, color: colors.gray600 },
});

const styles = StyleSheet.create({
  card: {
    backgroundColor: colors.white,
    borderRadius: radius.lg,
    padding: spacing.md,
    ...shadow.card,
  },
  button: {
    borderRadius: radius.full,
    paddingVertical: 14,
    alignItems: 'center',
    justifyContent: 'center',
  },
  buttonOutline: {
    borderWidth: 1,
    borderColor: colors.gray300,
  },
  buttonText: {
    color: colors.white,
    fontFamily: font.semibold,
    fontSize: 15,
  },
  label: {
    fontFamily: font.medium,
    fontSize: 12,
    color: colors.gray600,
    marginBottom: 6,
  },
  input: {
    borderWidth: 1,
    borderColor: colors.gray300,
    borderRadius: radius.md,
    paddingHorizontal: spacing.md,
    paddingVertical: 12,
    fontFamily: font.regular,
    fontSize: 15,
    color: colors.gray900,
    backgroundColor: colors.white,
  },
  badge: {
    paddingHorizontal: 10,
    paddingVertical: 4,
    borderRadius: radius.full,
    alignSelf: 'flex-start',
  },
  badgeText: {
    fontFamily: font.semibold,
    fontSize: 11,
  },
  sectionTitle: {
    fontFamily: font.bold,
    fontSize: 18,
    color: colors.secondary,
    marginBottom: spacing.sm,
  },
});
