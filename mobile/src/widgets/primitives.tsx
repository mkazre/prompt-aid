import React from 'react';
import { Image, ActivityIndicator, Pressable, StyleProp, StyleSheet, Text, View, ViewStyle } from 'react-native';
import { Feather } from '@expo/vector-icons';
import { pa, paFonts } from '../theme';
import { FeatherName, tint } from './helpers';

/**
 * Visual building blocks for widgets — softer than the core `pa` kit on
 * purpose (rounded cards, soft shadows, coloured icon circles), per the
 * client's reference screenshots. Charts are plain Views: no native deps.
 */

export const W = {
  radius: 18,
  radiusSm: 12,
  gap: 12,
  ink: pa.ink,
  sub: '#6B6B70',
  soft: '#F4F2EE',
  blue: '#1F4E7A',
  red: pa.signal,
  green: pa.go,
  orange: '#E4701E',
  yellow: pa.beacon,
};

export const wShadow = {
  shadowColor: '#101012',
  shadowOpacity: 0.07,
  shadowRadius: 14,
  shadowOffset: { width: 0, height: 6 },
  elevation: 2,
};

export function WCard({ children, style, onPress }: { children: React.ReactNode; style?: StyleProp<ViewStyle>; onPress?: () => void }) {
  if (onPress) {
    return (
      <Pressable onPress={onPress} style={({ pressed }) => [s.card, style, pressed && { opacity: 0.85 }]}>
        {children}
      </Pressable>
    );
  }
  return <View style={[s.card, style]}>{children}</View>;
}

export function WHeader({ title, subtitle, action, onAction, style }: { title: string; subtitle?: string | null; action?: string; onAction?: () => void; style?: StyleProp<ViewStyle> }) {
  return (
    <View style={[s.header, style]}>
      <View style={{ flex: 1 }}>
        <Text style={s.title} numberOfLines={1}>{title}</Text>
        {subtitle ? <Text style={s.subtitle} numberOfLines={1}>{subtitle}</Text> : null}
      </View>
      {action && onAction ? (
        <Pressable onPress={onAction} hitSlop={10}>
          <Text style={s.action}>{action}</Text>
        </Pressable>
      ) : null}
    </View>
  );
}

export function IconCircle({ name, color = W.ink, bg, size = 44, iconSize, imageUrl }: { name: FeatherName; color?: string; bg?: string; size?: number; iconSize?: number; imageUrl?: string | null }) {
  const inner = iconSize ?? Math.round(size * 0.45);
  return (
    <View style={{ width: size, height: size, borderRadius: size / 2, backgroundColor: bg ?? tint(color), alignItems: 'center', justifyContent: 'center', overflow: 'hidden' }}>
      {imageUrl ? (
        // Admin-uploaded icon image (Site → App Widgets) instead of a library icon.
        <Image source={{ uri: imageUrl }} style={{ width: Math.round(size * 0.62), height: Math.round(size * 0.62) }} resizeMode="contain" />
      ) : (
        <Feather name={name} size={inner} color={color} />
      )}
    </View>
  );
}

export function WEmpty({ message, icon = 'inbox' }: { message: string; icon?: FeatherName }) {
  return (
    <View style={s.empty}>
      <Feather name={icon} size={18} color={pa.muted2} />
      <Text style={s.emptyText}>{message}</Text>
    </View>
  );
}

export function WRow({ icon, color = W.ink, title, subtitle, right, onPress, first }: { icon?: FeatherName; color?: string; title: string; subtitle?: string; right?: React.ReactNode; onPress?: () => void; first?: boolean }) {
  return (
    <Pressable onPress={onPress} disabled={!onPress} style={({ pressed }) => [s.row, first && { borderTopWidth: 0 }, pressed && { opacity: 0.7 }]}>
      {icon ? <IconCircle name={icon} color={color} size={38} /> : null}
      <View style={{ flex: 1, minWidth: 0 }}>
        <Text style={s.rowTitle} numberOfLines={1}>{title}</Text>
        {subtitle ? <Text style={s.rowSub} numberOfLines={1}>{subtitle}</Text> : null}
      </View>
      {right ?? (onPress ? <Feather name="chevron-right" size={18} color={pa.muted2} /> : null)}
    </Pressable>
  );
}

export function Pill({ label, color = W.sub, bg }: { label: string; color?: string; bg?: string }) {
  return (
    <View style={[s.pill, { backgroundColor: bg ?? W.soft }]}>
      <Text style={[s.pillText, { color }]} numberOfLines={1}>{label}</Text>
    </View>
  );
}

export function statusPill(status: string) {
  const go = ['completed', 'confirmed', 'paid', 'delivered', 'accepted', 'approved'];
  const stop = ['cancelled', 'no_show', 'rejected', 'refunded'];
  const label = status.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
  if (go.includes(status)) return <Pill label={label} color={pa.go} bg={pa.goWash} />;
  if (stop.includes(status)) return <Pill label={label} color={pa.signalInk} bg={pa.signalWash} />;
  return <Pill label={label} color="#7A5C00" bg={pa.beaconWash} />;
}

export function WButton({ title, onPress, tone = 'red', loading, small, style }: { title: string; onPress: () => void; tone?: 'red' | 'ink' | 'light' | 'beacon' | 'green'; loading?: boolean; small?: boolean; style?: StyleProp<ViewStyle> }) {
  const bg = tone === 'red' ? pa.signal : tone === 'ink' ? pa.ink : tone === 'beacon' ? pa.beacon : tone === 'green' ? pa.go : W.soft;
  const fg = tone === 'light' || tone === 'beacon' ? pa.ink : '#fff';
  return (
    <Pressable onPress={onPress} disabled={loading} style={({ pressed }) => [s.btn, small && s.btnSm, { backgroundColor: bg, opacity: pressed ? 0.85 : 1 }, style]}>
      {loading ? <ActivityIndicator color={fg} size="small" /> : <Text style={[s.btnText, small && { fontSize: 12 }, { color: fg }]}>{title}</Text>}
    </Pressable>
  );
}

export function Stars({ rating, size = 13 }: { rating: number; size?: number }) {
  const full = Math.round(rating);
  return (
    <View style={{ flexDirection: 'row', gap: 2 }}>
      {[1, 2, 3, 4, 5].map((i) => (
        <Feather key={i} name="star" size={size} color={i <= full ? '#E0A800' : '#D9D4C8'} />
      ))}
    </View>
  );
}

/** Tiny sparkline of bars for stat cards. */
export function MiniBars({ values, color }: { values: number[]; color: string }) {
  const max = Math.max(1, ...values);
  return (
    <View style={s.miniBars}>
      {values.map((v, i) => (
        <View key={i} style={{ flex: 1, height: `${Math.max(8, (v / max) * 100)}%` as `${number}%`, backgroundColor: v > 0 ? color : tint(color, '33'), borderRadius: 2 }} />
      ))}
    </View>
  );
}

/** Weekly bar chart built from Views, today highlighted. */
export function BarChart({ bars, color, height = 130 }: { bars: { label: string; date: string; value: number }[]; color: string; height?: number }) {
  const max = Math.max(1, ...bars.map((b) => b.value));
  const today = new Date();
  const todayKey = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
  return (
    <View style={{ flexDirection: 'row', alignItems: 'flex-end', height: height + 36, gap: 8 }}>
      {bars.map((b) => {
        const isToday = b.date === todayKey;
        const h = b.value > 0 ? Math.max(10, (b.value / max) * height) : 6;
        return (
          <View key={b.date} style={{ flex: 1, alignItems: 'center' }}>
            <Text style={[s.barValue, { color: b.value > 0 ? W.ink : pa.muted2 }]}>{b.value > 0 ? formatNum(b.value) : ''}</Text>
            <View style={{ width: '70%', maxWidth: 28, height: h, borderRadius: 8, backgroundColor: isToday ? color : tint(color, b.value > 0 ? '66' : '22') }} />
            <Text style={[s.barLabel, isToday && { color: W.ink, fontFamily: paFonts.black }]}>{b.label}</Text>
          </View>
        );
      })}
    </View>
  );
}

function formatNum(v: number) {
  return v >= 1000 ? `${(v / 1000).toFixed(1)}k` : String(Math.round(v * 10) / 10);
}

export const s = StyleSheet.create({
  card: { backgroundColor: pa.surface, borderRadius: W.radius, padding: 16, ...wShadow },
  header: { flexDirection: 'row', alignItems: 'center', gap: 10, marginBottom: 10 },
  title: { fontFamily: paFonts.black, fontSize: 17, color: W.ink, letterSpacing: -0.2 },
  subtitle: { fontFamily: paFonts.regular, fontSize: 12, color: W.sub, marginTop: 2 },
  action: { fontFamily: paFonts.bold, fontSize: 13, color: pa.signal },
  empty: { flexDirection: 'row', alignItems: 'center', gap: 8, paddingVertical: 10 },
  emptyText: { fontFamily: paFonts.regular, fontSize: 13, color: W.sub, flex: 1 },
  row: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 10, borderTopWidth: 1, borderTopColor: '#F1EEE7' },
  rowTitle: { fontFamily: paFonts.bold, fontSize: 14, color: W.ink },
  rowSub: { fontFamily: paFonts.regular, fontSize: 12, color: W.sub, marginTop: 2 },
  pill: { paddingHorizontal: 10, paddingVertical: 4, borderRadius: 999, alignSelf: 'center', maxWidth: 140 },
  pillText: { fontFamily: paFonts.bold, fontSize: 11 },
  btn: { borderRadius: 999, paddingVertical: 12, paddingHorizontal: 18, alignItems: 'center', justifyContent: 'center', minHeight: 44 },
  btnSm: { paddingVertical: 8, paddingHorizontal: 14, minHeight: 34 },
  btnText: { fontFamily: paFonts.bold, fontSize: 14 },
  miniBars: { flexDirection: 'row', alignItems: 'flex-end', gap: 3, height: 28, marginTop: 10 },
  barValue: { fontFamily: paFonts.bold, fontSize: 10, marginBottom: 4 },
  barLabel: { fontFamily: paFonts.bold, fontSize: 11, color: pa.muted2, marginTop: 6 },
  hScroll: { marginHorizontal: -16, paddingHorizontal: 16 },
});
