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
