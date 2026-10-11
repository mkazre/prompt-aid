import React from 'react';
import { ImageBackground, Image, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { Feather } from '@expo/vector-icons';
import { LinearGradient } from 'expo-linear-gradient';
import { pa, paFonts } from '../theme';
import { BarsPayload, StatPayload, WidgetProps } from './types';
import { accentOf, goTo, initials, safeIcon } from './helpers';
import { BarChart, IconCircle, MiniBars, Pill, Stars, W, WButton, WCard, WHeader, s, wShadow } from './primitives';

/** Any `*_banner` key — admin copy, accent colour, optional image, CTA. */
export function PromoBanner({ config, navigation }: WidgetProps) {
  const st = config.settings;
  const accent = accentOf(st, W.blue);
  const body = st.body ?? config.subtitle;
  const style: 'solid' | 'gradient' | 'image' = st.style === 'gradient' || st.style === 'image' ? st.style : 'solid';
  const onPress = st.cta_target ? () => goTo(navigation, st.cta_target, st.cta_params ?? undefined) : undefined;

  const content = (
    <>
      <View style={b.bubble1} />
      <View style={b.bubble2} />
      <View style={{ flex: 1, paddingRight: 8 }}>
        <Text style={b.title}>{config.title}</Text>
        {body ? <Text style={b.body} numberOfLines={3}>{body}</Text> : null}
        {onPress ? (
          <View style={b.cta}>
            <Text style={[b.ctaText, { color: accent }]}>{st.cta_label ?? 'Learn more'}</Text>
          </View>
        ) : null}
      </View>
      {style === 'image' ? null : st.image_url ? (
        <Image source={{ uri: st.image_url }} style={b.image} />
      ) : (
        <View style={b.art}>
          {st.icon_image_url ? (
            <Image source={{ uri: st.icon_image_url }} style={{ width: 56, height: 56 }} resizeMode="contain" />
          ) : (
            <Feather name={safeIcon(st.icon, 'shield')} size={44} color="#fff" />
          )}
        </View>
      )}
    </>
  );

  // Banner colour/style, icon and link are all set in Site → App Widgets.
  if (style === 'image' && st.image_url) {
    return (
      <Pressable onPress={onPress} disabled={!onPress}>
        <ImageBackground source={{ uri: st.image_url }} style={[b.banner, { backgroundColor: accent }]} imageStyle={{ borderRadius: W.radius }}>
          <View style={[StyleSheet.absoluteFill, { backgroundColor: 'rgba(16,16,18,0.55)', borderRadius: W.radius }]} />
          {content}
        </ImageBackground>
      </Pressable>
    );
  }
  if (style === 'gradient') {
    return (
      <Pressable onPress={onPress} disabled={!onPress}>
        <LinearGradient colors={[accent, shade(accent), '#101012']} locations={[0, 0.7, 1.4]} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={b.banner}>
          {content}
        </LinearGradient>
      </Pressable>
    );
  }
  return (
    <Pressable onPress={onPress} disabled={!onPress} style={[b.banner, { backgroundColor: accent }]}>
      {content}
    </Pressable>
  );
}

/** Darker variant of a #RRGGBB colour for gradients. */
function shade(hex: string): string {
  const m = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex);
  if (!m) return hex;
  const d = (h: string) => Math.max(0, Math.round(parseInt(h, 16) * 0.6)).toString(16).padStart(2, '0');
  return `#${d(m[1])}${d(m[2])}${d(m[3])}`;
}

/** Default tap targets for stat widgets (settings.cta_target overrides). */
const STAT_TARGETS: Record<string, string> = {
  doctor_total_patients: 'DoctorPatients',
  doctor_total_appointments: 'DoctorCalendar',
  doctor_completed_month: 'DoctorCalendar',
  driver_today_trips: 'Trips',
  driver_week_earnings: 'Earnings',
  pharmacy_pending_scripts: 'Scripts',
  pharmacy_low_stock: 'Stock',
};

/** Generic KPI card: coloured icon circle, big number, caption, optional sparkline / stars. */
export function StatWidget({ config, data, navigation }: WidgetProps<StatPayload>) {
  if (!data) return null;
  const accent = accentOf(config.settings, W.blue);
  const target = config.settings.cta_target ?? STAT_TARGETS[config.key];
  return (
    <WCard style={{ flex: 1 }} onPress={target ? () => goTo(navigation, target) : undefined}>
      <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' }}>
        <IconCircle name={safeIcon(config.settings.icon, 'bar-chart-2')} imageUrl={config.settings.icon_image_url} color={accent} size={42} />
        {target ? <Feather name="arrow-up-right" size={16} color={pa.muted2} /> : null}
      </View>
      <Text style={st.value} numberOfLines={1} adjustsFontSizeToFit>{data.value}</Text>
      <Text style={st.label} numberOfLines={1}>{config.title}</Text>
      {typeof data.rating === 'number' ? <View style={{ marginTop: 6 }}><Stars rating={data.rating} /></View> : null}
      {data.caption ? <Text style={st.caption} numberOfLines={1}>{data.caption}</Text> : null}
      {data.trend && data.trend.some((v) => v > 0) ? <MiniBars values={data.trend} color={accent} /> : null}
    </WCard>
  );
}

export function WeeklyChart({ config, data }: WidgetProps<BarsPayload>) {
  if (!data) return null;
  const accent = accentOf(config.settings, W.blue);
  return (
    <WCard>
      <WHeader title={config.title} subtitle={config.subtitle ?? 'Monday to Sunday'} />
      <View style={{ flexDirection: 'row', alignItems: 'baseline', gap: 6, marginBottom: 12 }}>
        <Text style={st.chartTotal}>{Math.round(data.total * 10) / 10}</Text>
        <Text style={st.caption}>this week</Text>
      </View>
      <BarChart bars={data.bars} color={accent} />
    </WCard>
  );
}

interface DoctorCard {
  id: number;
  name: string;
  avatar: string | null;
  specialization: string;
  rating_avg: number;
  rating_count: number;
  consultation_fee: number;
  is_accepting_appointments: boolean;
  clinic_name: string | null;
  distance_km: number | null;
}

export function TopDoctors({ config, data, navigation }: WidgetProps<{ items: DoctorCard[] }>) {
  const items = data?.items ?? [];
  if (!items.length) return null;
  const cta = config.settings.cta_label ?? 'Book now';
  const open = (id: number) => goTo(navigation, 'Care/DoctorDetail', { doctorId: id });
  return (
    <View>
      <WHeader title={config.title} subtitle={config.subtitle} action="See all" onAction={() => goTo(navigation, 'Care/FindCare')} style={{ paddingHorizontal: 2 }} />
      <ScrollView horizontal showsHorizontalScrollIndicator={false} style={s.hScroll} contentContainerStyle={{ gap: 12, paddingRight: 32, paddingVertical: 6 }}>
        {items.map((d) => (
          <Pressable key={d.id} onPress={() => open(d.id)} style={dc.card}>
            {d.avatar ? (
              <Image source={{ uri: d.avatar }} style={dc.photo} />
            ) : (
              <View style={[dc.photo, dc.initials]}><Text style={dc.initialsText}>{initials(d.name)}</Text></View>
            )}
            <Text style={dc.name} numberOfLines={1}>{d.name}</Text>
            <Text style={dc.spec} numberOfLines={1}>{d.specialization}</Text>
            <View style={dc.meta}>
              <Feather name="star" size={12} color="#E0A800" />
              <Text style={dc.metaText}>{d.rating_avg.toFixed(1)}</Text>
              {d.distance_km != null ? (
                <>
                  <Feather name="map-pin" size={12} color={W.sub} style={{ marginLeft: 8 }} />
                  <Text style={dc.metaText}>{d.distance_km} km</Text>
                </>
              ) : null}
            </View>
            <WButton title={cta} small onPress={() => open(d.id)} style={{ alignSelf: 'stretch', marginTop: 10 }} />
          </Pressable>
        ))}
      </ScrollView>
    </View>
  );
}

interface ClinicCard {
  id: number;
  name: string;
  address: string | null;
  city: string | null;
  logo: string | null;
  doctors_count: number;
  distance_km: number | null;
}

export function NearbyClinics({ config, data, navigation, ctx }: WidgetProps<{ items: ClinicCard[]; has_location: boolean }>) {
  const items = data?.items ?? [];
  if (!items.length) return null;
  const open = (c: ClinicCard) => {
    if (ctx.onClinic) ctx.onClinic({ id: c.id, name: c.name });
    else goTo(navigation, 'Care/FindCare', { clinicId: c.id, clinicName: c.name });
  };
  return (
    <WCard>
      <WHeader title={config.title} subtitle={config.subtitle ?? (data?.has_location ? 'Closest to you' : 'Most doctors on call')} />
      {items.map((c, i) => (
        <Pressable key={c.id} onPress={() => open(c)} style={({ pressed }) => [s.row, i === 0 && { borderTopWidth: 0 }, pressed && { opacity: 0.7 }]}>
          {c.logo ? <Image source={{ uri: c.logo }} style={{ width: 38, height: 38, borderRadius: 10 }} /> : <IconCircle name="plus-square" color={W.green} size={38} />}
          <View style={{ flex: 1, minWidth: 0 }}>
            <Text style={s.rowTitle} numberOfLines={1}>{c.name}</Text>
            <Text style={s.rowSub} numberOfLines={1}>{[c.address, c.city].filter(Boolean).join(', ')} · {c.doctors_count} doctors</Text>
          </View>
          {c.distance_km != null ? <Pill label={`${c.distance_km} km`} /> : null}
        </Pressable>
      ))}
    </WCard>
  );
}

const b = StyleSheet.create({
  banner: { borderRadius: W.radius, padding: 20, flexDirection: 'row', alignItems: 'center', overflow: 'hidden', minHeight: 140, ...wShadow },
  bubble1: { position: 'absolute', right: -40, top: -40, width: 160, height: 160, borderRadius: 80, backgroundColor: 'rgba(255,255,255,0.10)' },
  bubble2: { position: 'absolute', right: 40, bottom: -60, width: 120, height: 120, borderRadius: 60, backgroundColor: 'rgba(255,255,255,0.07)' },
  title: { fontFamily: paFonts.black, fontSize: 19, lineHeight: 23, color: '#fff' },
  body: { fontFamily: paFonts.regular, fontSize: 13, lineHeight: 18, color: 'rgba(255,255,255,0.88)', marginTop: 6 },
  cta: { alignSelf: 'flex-start', backgroundColor: '#fff', borderRadius: 999, paddingHorizontal: 16, paddingVertical: 8, marginTop: 14 },
  ctaText: { fontFamily: paFonts.black, fontSize: 13 },
  image: { width: 104, height: 104, borderRadius: 14 },
  art: { width: 92, height: 92, borderRadius: 46, backgroundColor: 'rgba(255,255,255,0.16)', alignItems: 'center', justifyContent: 'center' },
});

const st = StyleSheet.create({
  value: { fontFamily: paFonts.black, fontSize: 26, color: W.ink, marginTop: 12, letterSpacing: -0.6, fontVariant: ['tabular-nums'] },
  label: { fontFamily: paFonts.bold, fontSize: 13, color: W.ink, marginTop: 2 },
  caption: { fontFamily: paFonts.regular, fontSize: 12, color: W.sub, marginTop: 3 },
  chartTotal: { fontFamily: paFonts.black, fontSize: 26, color: W.ink, letterSpacing: -0.6 },
});

const dc = StyleSheet.create({
  card: { width: 164, backgroundColor: pa.surface, borderRadius: W.radius, padding: 14, alignItems: 'center', ...wShadow },
  photo: { width: 72, height: 72, borderRadius: 36, backgroundColor: W.soft },
  initials: { backgroundColor: pa.ink, alignItems: 'center', justifyContent: 'center' },
  initialsText: { fontFamily: paFonts.black, fontSize: 22, color: pa.beacon },
  name: { fontFamily: paFonts.black, fontSize: 14, color: W.ink, marginTop: 10, maxWidth: '100%' },
  spec: { fontFamily: paFonts.regular, fontSize: 12, color: W.sub, marginTop: 2, maxWidth: '100%' },
  meta: { flexDirection: 'row', alignItems: 'center', gap: 4, marginTop: 8 },
  metaText: { fontFamily: paFonts.bold, fontSize: 12, color: W.ink },
});
