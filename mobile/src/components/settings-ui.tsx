import React from 'react';
import { ActivityIndicator, Modal, Pressable, ScrollView, StyleProp, StyleSheet, Text, TextInput, TextInputProps, View, ViewStyle } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { pa, paFonts } from '../theme';

/**
 * Soft, rounded "modern health app" surfaces used by the settings hubs,
 * search, calendar and booking flow: white cards, soft shadows, rounded
 * corners, Ionicons outline glyphs. Colours come from theme tokens.
 */
export type IconName = React.ComponentProps<typeof Ionicons>['name'];

export const softShadow = {
  shadowColor: '#101012',
  shadowOpacity: 0.06,
  shadowRadius: 10,
  shadowOffset: { width: 0, height: 4 },
  elevation: 2,
};

export const R = { card: 14, input: 12, pill: 999 };

export function SoftCard({ children, style }: { children: React.ReactNode; style?: StyleProp<ViewStyle> }) {
  return <View style={[s.card, style]}>{children}</View>;
}

/** Grid tile: icon bubble, title, subtitle — e.g. "Services / Clinic services". */
export function SettingTile({
  icon,
  title,
  subtitle,
  onPress,
  danger,
  right,
}: {
  icon: IconName;
  title: string;
  subtitle?: string;
  onPress?: () => void;
  danger?: boolean;
  right?: React.ReactNode;
}) {
  const tint = danger ? pa.signal : pa.info;
  return (
    <Pressable
      onPress={onPress}
      disabled={!onPress}
      style={({ pressed }) => [s.tile, { opacity: pressed ? 0.75 : 1 }]}
      accessibilityRole={onPress ? 'button' : undefined}
      accessibilityLabel={title}
    >
      <View style={[s.tileIcon, { backgroundColor: danger ? pa.signalWash : '#EAF1F8' }]}>
        <Ionicons name={icon} size={20} color={tint} />
      </View>
      <Text style={[s.tileTitle, danger && { color: pa.signal }]} numberOfLines={1}>{title}</Text>
      {subtitle ? <Text style={s.tileSub} numberOfLines={2}>{subtitle}</Text> : null}
      {right}
    </Pressable>
  );
}

export function SectionTitle({ children, style }: { children: React.ReactNode; style?: StyleProp<ViewStyle> }) {
  return (
    <View style={style}>
      <Text style={s.section}>{children}</Text>
    </View>
  );
}

/** Simple list row inside a SoftCard. */
export function ListRow({
  icon,
  title,
  subtitle,
  onPress,
  right,
  last,
}: {
  icon?: IconName;
  title: string;
  subtitle?: string | null;
  onPress?: () => void;
  right?: React.ReactNode;
  last?: boolean;
}) {
  return (
    <Pressable onPress={onPress} disabled={!onPress} style={({ pressed }) => [s.row, !last && s.rowBorder, { opacity: pressed ? 0.7 : 1 }]}>
      {icon ? (
        <View style={s.rowIcon}>
          <Ionicons name={icon} size={18} color={pa.info} />
        </View>
      ) : null}
      <View style={{ flex: 1 }}>
        <Text style={s.rowTitle} numberOfLines={1}>{title}</Text>
        {subtitle ? <Text style={s.rowSub} numberOfLines={2}>{subtitle}</Text> : null}
      </View>
      {right ?? (onPress ? <Ionicons name="chevron-forward" size={18} color={pa.muted2} /> : null)}
    </Pressable>
  );
}

export function PrimaryButton({
  title,
  onPress,
  loading,
  disabled,
  tone = 'primary',
  style,
  icon,
}: {
  title: string;
  onPress: () => void;
  loading?: boolean;
  disabled?: boolean;
  tone?: 'primary' | 'ghost' | 'danger';
  style?: StyleProp<ViewStyle>;
  icon?: IconName;
}) {
  const bg = tone === 'primary' ? pa.signal : tone === 'danger' ? pa.signalWash : pa.surface;
  const fg = tone === 'primary' ? '#fff' : tone === 'danger' ? pa.signal : pa.ink;
  return (
    <Pressable
      onPress={onPress}
      disabled={disabled || loading}
      style={({ pressed }) => [
        s.btn,
        { backgroundColor: bg, borderColor: tone === 'ghost' ? pa.line : bg, opacity: disabled ? 0.5 : pressed ? 0.85 : 1 },
        style,
      ]}
    >
      {loading ? (
        <ActivityIndicator color={fg} />
      ) : (
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
          {icon ? <Ionicons name={icon} size={18} color={fg} /> : null}
          <Text style={[s.btnText, { color: fg }]}>{title}</Text>
        </View>
      )}
    </Pressable>
  );
}

/** Rounded floating action button, bottom-right. */
export function Fab({ onPress, icon = 'add', label, bottom = 24 }: { onPress: () => void; icon?: IconName; label: string; bottom?: number }) {
  return (
    <Pressable onPress={onPress} accessibilityRole="button" accessibilityLabel={label} style={({ pressed }) => [s.fab, { bottom, opacity: pressed ? 0.85 : 1 }]}>
      <Ionicons name={icon} size={28} color="#fff" />
    </Pressable>
  );
}

/** Bottom sheet with a title and scrollable body. */
export function Sheet({
  visible,
  onClose,
  title,
  children,
  footer,
}: {
  visible: boolean;
  onClose: () => void;
  title: string;
  children: React.ReactNode;
  footer?: React.ReactNode;
}) {
  const insets = useSafeAreaInsets();
  return (
    <Modal visible={visible} animationType="slide" transparent onRequestClose={onClose}>
      <View style={s.scrim}>
        <Pressable style={{ flex: 1 }} onPress={onClose} accessibilityLabel="Close" />
        <View style={[s.sheet, { paddingBottom: insets.bottom + 16 }]}>
          <View style={s.sheetHandle} />
          <View style={s.sheetHead}>
            <Text style={s.sheetTitle}>{title}</Text>
            <Pressable onPress={onClose} hitSlop={12} accessibilityLabel="Close">
              <Ionicons name="close" size={22} color={pa.muted} />
            </Pressable>
          </View>
          <ScrollView keyboardShouldPersistTaps="handled" style={{ maxHeight: 560 }} contentContainerStyle={{ paddingBottom: 8 }}>
            {children}
          </ScrollView>
          {footer ? <View style={{ paddingTop: 12 }}>{footer}</View> : null}
        </View>
      </View>
    </Modal>
  );
}

/** Pill chip used for pickers (clinic, weekday, slot length…). */
export function Chip({ label, active, onPress, style }: { label: string; active?: boolean; onPress?: () => void; style?: StyleProp<ViewStyle> }) {
  return (
    <Pressable onPress={onPress} style={[s.chip, active && s.chipActive, style]}>
      <Text style={[s.chipText, active && s.chipTextActive]}>{label}</Text>
    </Pressable>
  );
}

/** Labelled rounded text input. */
export function Field(props: TextInputProps & { label: string; error?: string | null }) {
  const { label, error, style, ...rest } = props;
  return (
    <View style={{ marginBottom: 14 }}>
      <Text style={s.label}>{label}</Text>
      <TextInput placeholderTextColor={pa.muted2} {...rest} style={[s.input, error ? { borderColor: pa.signal } : null, style]} />
      {error ? <Text style={{ color: pa.signal, fontFamily: paFonts.regular, fontSize: 12, marginTop: 4 }}>{error}</Text> : null}
    </View>
  );
}

export function EmptyNote({ icon = 'information-circle-outline', text }: { icon?: IconName; text: string }) {
  return (
    <View style={{ alignItems: 'center', paddingVertical: 28, gap: 8 }}>
      <Ionicons name={icon} size={28} color={pa.muted2} />
      <Text style={{ fontFamily: paFonts.regular, color: pa.muted, fontSize: 13, textAlign: 'center' }}>{text}</Text>
    </View>
  );
}

export const s = StyleSheet.create({
  card: { backgroundColor: pa.surface, borderRadius: R.card, padding: 16, ...softShadow },
  tile: {
    width: '31%',
    flexGrow: 1,
    minWidth: 96,
    backgroundColor: pa.surface,
    borderRadius: R.card,
    padding: 14,
    minHeight: 112,
    ...softShadow,
  },
  tileIcon: { width: 38, height: 38, borderRadius: 12, alignItems: 'center', justifyContent: 'center', marginBottom: 12 },
  tileTitle: { fontFamily: paFonts.bold, fontSize: 14, color: pa.ink },
  tileSub: { fontFamily: paFonts.regular, fontSize: 11, color: pa.muted, marginTop: 2 },
  section: { fontFamily: paFonts.bold, fontSize: 15, color: pa.ink, marginBottom: 12 },
  row: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 13 },
  rowBorder: { borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: pa.line },
  rowIcon: { width: 34, height: 34, borderRadius: 10, backgroundColor: '#EAF1F8', alignItems: 'center', justifyContent: 'center' },
  rowTitle: { fontFamily: paFonts.bold, fontSize: 14, color: pa.ink },
  rowSub: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted, marginTop: 2 },
  btn: { borderRadius: R.input, borderWidth: 1, paddingVertical: 14, alignItems: 'center', justifyContent: 'center' },
  btnText: { fontFamily: paFonts.bold, fontSize: 15 },
  fab: {
    position: 'absolute',
    right: 20,
    width: 58,
    height: 58,
    borderRadius: 29,
    backgroundColor: pa.signal,
    alignItems: 'center',
    justifyContent: 'center',
    shadowColor: pa.signal,
    shadowOpacity: 0.35,
    shadowRadius: 10,
    shadowOffset: { width: 0, height: 6 },
    elevation: 6,
  },
  scrim: { flex: 1, backgroundColor: 'rgba(16,16,18,0.45)', justifyContent: 'flex-end' },
  sheet: { backgroundColor: pa.surface, borderTopLeftRadius: 22, borderTopRightRadius: 22, paddingHorizontal: 20, paddingTop: 8 },
  sheetHandle: { alignSelf: 'center', width: 40, height: 4, borderRadius: 2, backgroundColor: pa.line, marginBottom: 10 },
  sheetHead: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 14 },
  sheetTitle: { fontFamily: paFonts.black, fontSize: 18, color: pa.ink },
  chip: { borderWidth: 1, borderColor: pa.line, borderRadius: R.pill, paddingHorizontal: 14, paddingVertical: 8, backgroundColor: pa.surface },
  chipActive: { backgroundColor: pa.ink, borderColor: pa.ink },
  chipText: { fontFamily: paFonts.bold, fontSize: 13, color: pa.ink },
  chipTextActive: { color: '#fff' },
  input: {
    borderWidth: 1,
    borderColor: pa.line,
    borderRadius: R.input,
    paddingHorizontal: 14,
    paddingVertical: 12,
    fontFamily: paFonts.regular,
    fontSize: 15,
    color: pa.ink,
    backgroundColor: pa.surface,
  },
  label: { fontFamily: paFonts.bold, fontSize: 12, color: pa.muted, marginBottom: 6 },
});
