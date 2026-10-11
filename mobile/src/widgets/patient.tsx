import React, { useState } from 'react';
import { Image, Linking, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { Feather } from '@expo/vector-icons';
import { pa, paFonts } from '../theme';
import { Appointment, Product } from '../api/types';
import { META } from '../services/triage';
import { WidgetProps } from './types';
import { FeatherName, accentOf, goTo, headline, iconFor, initials, money, shortDate, timeAgo } from './helpers';
import { IconCircle, Pill, W, WButton, WCard, WEmpty, WHeader, WRow, s, statusPill, wShadow } from './primitives';
import { ArticleCard, HealthArticleModal, HealthArticleSummary } from './HealthArticleReader';

// ---------------------------------------------------------------- search

const SHORTCUTS: { label: string; icon: FeatherName; color: string; target: string; params?: object }[] = [
  { label: 'Doctor', icon: 'user', color: W.blue, target: 'Care/FindCare' },
  { label: 'Pharmacy', icon: 'package', color: W.green, target: 'Pharmacy/Shop' },
  { label: 'Hospital', icon: 'plus-square', color: W.red, target: 'Care/FindCare' },
  { label: 'Ambulance', icon: 'truck', color: W.orange, target: 'Home/TriageStart' },
  { label: 'Shuttle', icon: 'navigation', color: '#6A4C93', target: 'Rides/ShuttleRequest' },
  { label: 'Lab', icon: 'droplet', color: '#0F7C8C', target: 'Profile/LabResults' },
];

export function SearchShortcuts({ config, navigation }: WidgetProps) {
  return (
    <View>
      <Text style={[s.title, { marginBottom: 10 }]}>{config.title}</Text>
      {/* Opens the search-as-you-type screen (grouped live results). */}
      <Pressable
        onPress={() => goTo(navigation, 'Home/Search')}
        style={({ pressed }) => [p.search, pressed && { opacity: 0.8 }]}
        accessibilityRole="search"
        accessibilityLabel="Search"
      >
        <Feather name="search" size={18} color={W.sub} />
        <Text style={[p.searchInput, { color: pa.muted2 }]} numberOfLines={1}>
          {config.settings.placeholder ?? 'Search doctor, speciality or clinic'}
        </Text>
      </Pressable>
      <View style={p.shortcuts}>
        {SHORTCUTS.map((sc) => (
          <Pressable key={sc.label} onPress={() => goTo(navigation, sc.target, sc.params)} style={({ pressed }) => [p.shortcut, pressed && { opacity: 0.7 }]}>
            <View style={[p.shortcutCircle]}>
              <Feather name={sc.icon} size={22} color={sc.color} />
            </View>
            <Text style={p.shortcutLabel} numberOfLines={1}>{sc.label}</Text>
          </Pressable>
        ))}
      </View>
    </View>
  );
}

// ---------------------------------------------------------------- appointment

export function NextAppointment({ config, data, navigation }: WidgetProps<{ appointment: Appointment | null }>) {
  const a = data?.appointment;
  if (!a) {
    return (
      <WCard style={p.dark}>
        <Text style={p.darkEyebrow}>{config.title}</Text>
        <Text style={p.darkMuted}>Nothing booked yet.</Text>
        <WButton title="Book a consult" tone="beacon" onPress={() => goTo(navigation, 'Care/FindCare')} style={{ marginTop: 14, alignSelf: 'flex-start' }} />
      </WCard>
    );
  }
  const isVideo = a.mode === 'video' || a.visit_type === 'telemed';
  const when = new Date(`${a.date}T${a.start_time ?? '00:00'}`);
  return (
    <WCard style={p.dark} onPress={() => goTo(navigation, 'Profile/Appointments')}>
      <Text style={p.darkEyebrow}>{config.title}</Text>
      <View style={{ flexDirection: 'row', alignItems: 'center', gap: 12 }}>
        <View style={p.darkAvatar}><Text style={p.darkAvatarText}>{initials(a.doctor?.name)}</Text></View>
        <View style={{ flex: 1 }}>
          <Text style={p.darkName} numberOfLines={1}>{a.doctor?.name ?? 'Your doctor'}</Text>
          <Text style={p.darkMuted} numberOfLines={1}>{a.doctor?.specialization ?? headline(a.status)}</Text>
        </View>
        <Pill label={headline(a.status)} color={pa.ink} bg={pa.beacon} />
      </View>
      <View style={p.when}>
        <View style={p.whenItem}><Feather name="calendar" size={14} color={pa.beacon} /><Text style={p.whenText}>{when.toLocaleDateString('en-ZA', { weekday: 'short', day: '2-digit', month: 'short' })}</Text></View>
        <View style={p.whenItem}><Feather name="clock" size={14} color={pa.beacon} /><Text style={p.whenText}>{a.start_time?.slice(0, 5)}</Text></View>
        <View style={[p.whenItem, { flexShrink: 1 }]}><Feather name={isVideo ? 'video' : 'map-pin'} size={14} color={pa.beacon} /><Text style={p.whenText} numberOfLines={1}>{isVideo ? 'Video' : a.clinic?.name ?? 'Clinic'}</Text></View>
      </View>
      {isVideo ? (
        <WButton title={a.can_join_video ? 'Join video consult' : 'Video room'} tone="beacon" onPress={() => goTo(navigation, 'Home/ConsultRoom', { appointment: a })} style={{ marginTop: 12 }} />
      ) : null}
      {a.doctor ? (
        <WButton title="Message doctor" tone="light" onPress={() => navigation.navigate('Chat', { appointmentId: a.id, title: `Dr. ${a.doctor?.name ?? ''}` })} style={{ marginTop: 8 }} />
      ) : null}
    </WCard>
  );
}

// ---------------------------------------------------------------- triage

export function LatestTriage({ config, data, navigation }: WidgetProps<{ triage: { level: 'red' | 'orange' | 'yellow' | 'green'; reference: string; created_at: string } | null }>) {
  const t = data?.triage;
  if (!t) return null;
  const color = pa.sats[t.level] ?? pa.go;
  return (
    <WCard onPress={() => goTo(navigation, 'Home/TriageStart')} style={{ flexDirection: 'row', alignItems: 'center', gap: 14 }}>
      <View style={[p.triageDot, { backgroundColor: color, shadowColor: color }]} />
      <View style={{ flex: 1 }}>
        <Text style={p.eyebrow}>{config.title}</Text>
        <Text style={s.rowTitle}>{META[t.level]?.name ?? headline(t.level)}</Text>
        <Text style={s.rowSub}>Ref {t.reference} · {timeAgo(t.created_at)}</Text>
      </View>
      <Feather name="chevron-right" size={18} color={pa.muted2} />
    </WCard>
  );
}

// ---------------------------------------------------------------- stats

const STAT_COLORS = [W.blue, W.red, W.green, W.orange];

export function VisitStats({ config, data, navigation }: WidgetProps<{ items: { label: string; value: string; icon: string }[] }>) {
  const items = data?.items ?? [];
  if (!items.length) return null;
  const targets = ['Profile/Appointments', 'Profile/Appointments', 'Profile/LabResults', 'Profile/Orders'];
  return (
    <View>
      <WHeader title={config.title} subtitle={config.subtitle} />
      <View style={p.statGrid}>
        {items.map((it, i) => (
          <WCard key={it.label} style={p.statCell} onPress={() => goTo(navigation, targets[i])}>
            <IconCircle name={(it.icon as FeatherName) ?? 'activity'} color={STAT_COLORS[i % 4]} size={40} />
            <View style={{ flex: 1 }}>
              <Text style={p.statValue}>{it.value}</Text>
              <Text style={s.rowSub}>{it.label}</Text>
            </View>
          </WCard>
        ))}
      </View>
    </View>
  );
}

// ---------------------------------------------------------------- ride

export function UpcomingRide({ config, data, navigation }: WidgetProps<{ ride: any | null }>) {
  const r = data?.ride;
  if (!r) return null;
  return (
    <WCard onPress={() => goTo(navigation, 'Rides/RideTrack', { rideId: r.id })}>
      <WHeader title={config.title} subtitle={r.ride_ref} />
      <View style={{ flexDirection: 'row', gap: 12, alignItems: 'center' }}>
        <IconCircle name="navigation" color="#6A4C93" />
        <View style={{ flex: 1 }}>
          <Text style={s.rowTitle}>{headline(r.status)}{r.eta_minutes ? ` · ETA ${r.eta_minutes} min` : ''}</Text>
          <Text style={s.rowSub} numberOfLines={1}>{r.pickup_address} → {r.dropoff_address}</Text>
          <Text style={s.rowSub} numberOfLines={1}>
            {r.scheduled_for ? new Date(r.scheduled_for).toLocaleString('en-ZA', { weekday: 'short', day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : 'On demand'}
            {r.driver_name ? ` · ${r.driver_name}` : ''}
          </Text>
        </View>
      </View>
      <WButton title="Track shuttle" onPress={() => goTo(navigation, 'Rides/RideTrack', { rideId: r.id })} style={{ marginTop: 12 }} />
    </WCard>
  );
}

// ---------------------------------------------------------------- services / specialities

export function ClinicServices({ config, data, navigation, ctx }: WidgetProps<{ items: { id: number; name: string; count: number }[] }>) {
  const items = data?.items ?? [];
  if (!items.length) return null;
  const open = (name: string) => (ctx.onSpecialization ? ctx.onSpecialization(name) : goTo(navigation, 'Care/FindCare', { search: '', specialization: name }));
  return (
    <View>
      <WHeader title={config.title} subtitle={config.subtitle} action="See all" onAction={() => goTo(navigation, 'Care/FindCare')} />
      <ScrollView horizontal showsHorizontalScrollIndicator={false} style={s.hScroll} contentContainerStyle={{ gap: 14, paddingRight: 32, paddingVertical: 6 }}>
        {items.map((c) => (
          <Pressable key={c.id} onPress={() => open(c.name)} style={p.service}>
            <View style={p.serviceCircle}><Feather name={iconFor(c.name)} size={24} color={W.ink} /></View>
            <Text style={p.serviceLabel} numberOfLines={2}>{c.name}</Text>
          </Pressable>
        ))}
      </ScrollView>
    </View>
  );
}

export function Specialties({ config, data, navigation, ctx }: WidgetProps<{ items: { name: string; count: number }[] }>) {
  const items = data?.items ?? [];
  if (!items.length) return null;
  const open = (name: string) => (ctx.onSpecialization ? ctx.onSpecialization(name) : goTo(navigation, 'Care/FindCare', { specialization: name }));
  return (
    <View>
      <WHeader title={config.title} subtitle={config.subtitle} />
      <ScrollView horizontal showsHorizontalScrollIndicator={false} style={s.hScroll} contentContainerStyle={{ gap: 10, paddingRight: 32, paddingVertical: 6 }}>
        {items.map((sp, i) => (
          <Pressable key={sp.name} onPress={() => open(sp.name)} style={p.specCard}>
            <IconCircle name={iconFor(sp.name)} color={STAT_COLORS[i % 4]} size={40} />
            <Text style={p.specName} numberOfLines={2}>{sp.name}</Text>
            <Text style={s.rowSub}>{sp.count} doctor{sp.count === 1 ? '' : 's'}</Text>
          </Pressable>
        ))}
      </ScrollView>
    </View>
  );
}

// ---------------------------------------------------------------- prescriptions / labs / orders / invoices

export function ActivePrescriptions({ config, data, navigation }: WidgetProps<{ items: any[]; pending_uploads: number }>) {
  const items = data?.items ?? [];
  return (
    <WCard>
      <WHeader title={config.title} subtitle={config.subtitle} action="Order" onAction={() => goTo(navigation, 'Pharmacy/Shop')} />
      {items.length === 0 ? <WEmpty icon="package" message={`No prescriptions in the last ${config.settings.days ?? 30} days.`} /> : null}
      {items.map((rx, i) => (
        <WRow
          key={rx.id}
          first={i === 0}
          icon="package"
          color={W.red}
          title={rx.medicine_name}
          subtitle={[rx.dosage, rx.frequency, rx.duration_days ? `${rx.duration_days} days` : null].filter(Boolean).join(' · ')}
          onPress={() => goTo(navigation, 'Pharmacy/Shop', { search: rx.medicine_name })}
        />
      ))}
      {data?.pending_uploads ? (
        <Pressable onPress={() => goTo(navigation, 'Pharmacy/ScriptUpload')} style={p.note}>
          <Feather name="clock" size={14} color="#7A5C00" />
          <Text style={p.noteText}>{data.pending_uploads} uploaded script{data.pending_uploads === 1 ? '' : 's'} awaiting pharmacist review</Text>
        </Pressable>
      ) : null}
    </WCard>
  );
}

export function LabResultsWidget({ config, data, navigation }: WidgetProps<{ items: any[]; ready_count: number }>) {
  const items = data?.items ?? [];
  return (
    <WCard>
      <WHeader title={config.title} subtitle={data?.ready_count ? `${data.ready_count} ready to view` : config.subtitle} action="See all" onAction={() => goTo(navigation, 'Profile/LabResults')} />
      {items.length === 0 ? <WEmpty icon="droplet" message="No lab requests yet." /> : null}
      {items.map((l, i) => (
        <WRow
          key={l.id}
          first={i === 0}
          icon="droplet"
          color="#0F7C8C"
          title={l.tests?.length ? l.tests.join(', ') : l.request_ref}
          subtitle={l.doctor_name ?? l.request_ref}
          right={l.results_ready > 0 ? <Pill label="Ready" color={pa.go} bg={pa.goWash} /> : statusPill(l.status)}
          onPress={() => goTo(navigation, 'Profile/LabResults')}
        />
      ))}
    </WCard>
  );
}

export function RecentOrders({ config, data, navigation }: WidgetProps<{ items: any[] }>) {
  const items = data?.items ?? [];
  return (
    <WCard>
      <WHeader title={config.title} subtitle={config.subtitle} action="See all" onAction={() => goTo(navigation, 'Profile/Orders')} />
      {items.length === 0 ? <WEmpty icon="shopping-bag" message="No orders yet." /> : null}
      {items.map((o, i) => (
        <WRow
          key={o.id}
          first={i === 0}
          icon="shopping-bag"
          color={W.orange}
          title={o.order_no}
          subtitle={`${o.pharmacy_name ?? 'Pharmacy'} · ${o.items_count} item${o.items_count === 1 ? '' : 's'} · ${money(o.total, 2)}`}
          right={statusPill(o.status)}
          onPress={() => goTo(navigation, 'Profile/Orders')}
        />
      ))}
    </WCard>
  );
}

export function OutstandingInvoices({ config, data, navigation }: WidgetProps<{ items: any[]; total_due: number; count: number }>) {
  if (!data) return null;
  const due = data.total_due ?? 0;
  return (
    <WCard onPress={() => goTo(navigation, 'Profile/Invoices')}>
      <WHeader title={config.title} subtitle={config.subtitle} />
      <View style={{ flexDirection: 'row', alignItems: 'center', gap: 12 }}>
        <IconCircle name="credit-card" color={due > 0 ? W.red : W.green} />
        <View style={{ flex: 1 }}>
          <Text style={p.statValue}>{money(due, 2)}</Text>
          <Text style={s.rowSub}>{due > 0 ? `${data.count} unpaid invoice${data.count === 1 ? '' : 's'}` : 'All settled'}</Text>
        </View>
        {due > 0 ? <WButton title="Pay" small onPress={() => goTo(navigation, 'Profile/Invoices')} /> : null}
      </View>
      {data.items.map((inv: any) => (
        <WRow
          key={inv.id}
          title={inv.invoice_no}
          subtitle={`${inv.clinic_name ?? ''}${inv.due_date ? ` · due ${shortDate(inv.due_date)}` : ''}`}
          right={<Pill label={money(inv.total, 2)} color={inv.overdue ? pa.signalInk : '#7A5C00'} bg={inv.overdue ? pa.signalWash : pa.beaconWash} />}
        />
      ))}
    </WCard>
  );
}

// ---------------------------------------------------------------- medical aid

export function MedicalAidCard({ config, data, navigation }: WidgetProps<{ membership: any | null }>) {
  const m = data?.membership;
  if (!m) {
    return (
      <WCard onPress={() => goTo(navigation, 'Profile/ProfileHome')}>
        <WHeader title={config.title} />
        <WEmpty icon="credit-card" message="No medical aid on file — add your scheme to claim directly." />
      </WCard>
    );
  }
  const accent = accentOf(config.settings, pa.ink);
  return (
    <Pressable onPress={() => goTo(navigation, 'Profile/ProfileHome')} style={[p.aid, { backgroundColor: accent }]}>
      <View style={p.aidGlow} />
      <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' }}>
        <Text style={[p.eyebrow, { color: 'rgba(255,255,255,0.7)' }]}>{config.title}</Text>
        <Pill label={m.verified ? 'Verified' : 'Pending'} color={m.verified ? pa.go : '#7A5C00'} bg={m.verified ? pa.goWash : pa.beaconWash} />
      </View>
      <Text style={p.aidScheme}>{m.scheme_name ?? 'Medical aid'}</Text>
      <Text style={p.aidNumber}>{m.member_number}{m.dependant_code ? `  /  ${m.dependant_code}` : ''}</Text>
      <Text style={p.aidName}>{m.main_member_name}</Text>
    </Pressable>
  );
}

// ---------------------------------------------------------------- emergency

export function EmergencyQuickDial({ config, data, navigation }: WidgetProps<{ items: { label: string; phone: string; tel_url: string; is_primary: boolean }[] }>) {
  const items = data?.items ?? [];
  if (!items.length) return null;
  return (
    <View>
      <WHeader title={config.title} subtitle={config.subtitle} action="Start triage" onAction={() => goTo(navigation, 'Home/TriageStart')} />
      <View style={p.dialGrid}>
        {items.map((c) => (
          <Pressable key={c.tel_url + c.label} onPress={() => Linking.openURL(c.tel_url).catch(() => {})} style={({ pressed }) => [p.dial, c.is_primary && p.dialPrimary, pressed && { opacity: 0.8 }]}>
            <View style={[p.dialIcon, c.is_primary && { backgroundColor: 'rgba(255,255,255,0.2)' }]}>
              <Feather name="phone" size={16} color={c.is_primary ? '#fff' : W.red} />
            </View>
            <View style={{ flex: 1, minWidth: 0 }}>
              <Text style={[s.rowTitle, c.is_primary && { color: '#fff' }]} numberOfLines={1}>{c.label}</Text>
              <Text style={[s.rowSub, c.is_primary && { color: 'rgba(255,255,255,0.85)' }]} numberOfLines={1}>{c.phone}</Text>
            </View>
          </Pressable>
        ))}
      </View>
    </View>
  );
}

// ---------------------------------------------------------------- articles

export function HealthArticles({ config, data }: WidgetProps<{ items: HealthArticleSummary[] }>) {
  const [open, setOpen] = useState<string | null>(null);
  const items = data?.items ?? [];
  if (!items.length) return null;
  return (
    <View>
      <WHeader title={config.title} subtitle={config.subtitle} action="See all" onAction={() => setOpen('*')} />
      <ScrollView horizontal showsHorizontalScrollIndicator={false} style={s.hScroll} contentContainerStyle={{ gap: 12, paddingRight: 32, paddingVertical: 6 }}>
        {items.map((a) => <ArticleCard key={a.id} article={a} onPress={() => setOpen(a.slug)} />)}
      </ScrollView>
      <HealthArticleModal slug={open} onClose={() => setOpen(null)} />
    </View>
  );
}

// ---------------------------------------------------------------- shop

export function ShopCategories({ config, data, navigation, ctx }: WidgetProps<{ items: { id: number; name: string; count: number; image: string | null }[] }>) {
  const items = data?.items ?? [];
  if (!items.length) return null;
  const open = (c: { id: number; name: string }) => (ctx.onCategory ? ctx.onCategory(c) : goTo(navigation, 'Pharmacy/Shop', { categoryId: c.id, categoryName: c.name }));
  return (
    <View>
      <WHeader title={config.title} subtitle={config.subtitle} />
      <View style={p.catGrid}>
        {items.map((c, i) => (
          <Pressable key={c.id} onPress={() => open(c)} style={({ pressed }) => [p.catCard, pressed && { opacity: 0.8 }]}>
            {c.image ? <Image source={{ uri: c.image }} style={p.catImg} /> : <IconCircle name={iconFor(c.name)} color={STAT_COLORS[i % 4]} size={44} />}
            <View style={{ flex: 1, minWidth: 0 }}>
              <Text style={s.rowTitle} numberOfLines={1}>{c.name}</Text>
              <Text style={s.rowSub}>{c.count} item{c.count === 1 ? '' : 's'}</Text>
            </View>
          </Pressable>
        ))}
      </View>
    </View>
  );
}

export function FeaturedProducts({ config, data, navigation }: WidgetProps<{ items: Product[] }>) {
  const items = data?.items ?? [];
  if (!items.length) return null;
  return (
    <View>
      <WHeader title={config.title} subtitle={config.subtitle} />
      <ScrollView horizontal showsHorizontalScrollIndicator={false} style={s.hScroll} contentContainerStyle={{ gap: 12, paddingRight: 32, paddingVertical: 6 }}>
        {items.map((prod) => (
          <Pressable key={prod.id} onPress={() => goTo(navigation, 'Pharmacy/ProductDetail', { product: prod })} style={p.product}>
            {prod.image ? (
              <Image source={{ uri: prod.image }} style={p.productImg} />
            ) : (
              <View style={[p.productImg, { alignItems: 'center', justifyContent: 'center', backgroundColor: W.soft }]}>
                <Feather name={iconFor(prod.category)} size={28} color={W.sub} />
              </View>
            )}
            <Text style={p.productName} numberOfLines={2}>{prod.name}</Text>
            <Text style={p.productPrice}>{money(prod.price, 2)}</Text>
            {prod.requires_prescription ? <Text style={p.rx}>Script needed</Text> : null}
          </Pressable>
        ))}
      </ScrollView>
    </View>
  );
}

// ---------------------------------------------------------------- record

export function HealthSummary({ config, data, navigation }: WidgetProps<any>) {
  if (!data) return null;
  const facts: { label: string; value: string; icon: FeatherName }[] = [
    { label: 'Blood group', value: data.blood_group ?? '—', icon: 'droplet' },
    { label: 'Age', value: data.age != null ? String(data.age) : '—', icon: 'user' },
    { label: 'Gender', value: data.gender ? headline(data.gender) : '—', icon: 'users' },
  ];
  const alerts: string[] = [...(data.allergies ?? []), ...(data.chronic_conditions ?? [])];
  return (
    <WCard>
      <WHeader title={config.title} subtitle={config.subtitle} action="Edit" onAction={() => goTo(navigation, 'Profile/ProfileHome')} />
      <View style={{ flexDirection: 'row', gap: 10 }}>
        {facts.map((f, i) => (
          <View key={f.label} style={p.fact}>
            <Feather name={f.icon} size={16} color={STAT_COLORS[i]} />
            <Text style={p.factValue} numberOfLines={1}>{f.value}</Text>
            <Text style={s.rowSub}>{f.label}</Text>
          </View>
        ))}
      </View>
      <Text style={[p.eyebrow, { marginTop: 14 }]}>Allergies & conditions</Text>
      {alerts.length ? (
        <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginTop: 4 }}>
          {(data.allergies ?? []).map((a: string) => <Pill key={'a' + a} label={a} color={pa.signalInk} bg={pa.signalWash} />)}
          {(data.chronic_conditions ?? []).map((c: string) => <Pill key={'c' + c} label={c} color={W.blue} bg="#E8EEF4" />)}
        </View>
      ) : (
        <Text style={s.rowSub}>None recorded.</Text>
      )}
      {data.emergency_contact_name ? (
        <WRow
          icon="phone"
          color={W.red}
          title={data.emergency_contact_name}
          subtitle={`Emergency contact · ${data.emergency_contact_phone ?? ''}`}
          onPress={data.emergency_contact_phone ? () => Linking.openURL(`tel:${String(data.emergency_contact_phone).replace(/\s/g, '')}`).catch(() => {}) : undefined}
        />
      ) : null}
    </WCard>
  );
}

export function RecentEncounters({ config, data, navigation }: WidgetProps<{ items: any[] }>) {
  const items = data?.items ?? [];
  return (
    <WCard>
      <WHeader title={config.title} subtitle={config.subtitle} action="All visits" onAction={() => goTo(navigation, 'Profile/Appointments')} />
      {items.length === 0 ? <WEmpty icon="clipboard" message="Your consultation notes will appear here after a visit." /> : null}
      {items.map((e, i) => (
        <WRow
          key={e.id}
          first={i === 0}
          icon="clipboard"
          color={W.blue}
          title={e.diagnosis || e.chief_complaint || 'Consultation'}
          subtitle={`${shortDate(e.date)} · ${e.doctor_name ?? 'Doctor'}${e.follow_up_date ? ` · follow-up ${shortDate(e.follow_up_date)}` : ''}`}
          onPress={() => goTo(navigation, 'Profile/Appointments')}
        />
      ))}
    </WCard>
  );
}

const p = StyleSheet.create({
  search: { flexDirection: 'row', alignItems: 'center', gap: 10, backgroundColor: pa.surface, borderRadius: 999, paddingHorizontal: 16, height: 50, ...wShadow },
  searchInput: { flex: 1, fontFamily: paFonts.regular, fontSize: 14, color: pa.ink, paddingVertical: 0 },
  shortcuts: { flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'space-between', rowGap: 14, marginTop: 16 },
  shortcut: { width: '31%', alignItems: 'center', gap: 6 },
  shortcutCircle: { width: 58, height: 58, borderRadius: 29, backgroundColor: pa.surface, alignItems: 'center', justifyContent: 'center', ...wShadow },
  shortcutLabel: { fontFamily: paFonts.bold, fontSize: 12, color: pa.ink },
  dark: { backgroundColor: pa.ink },
  darkEyebrow: { fontFamily: paFonts.black, fontSize: 11, letterSpacing: 1.2, textTransform: 'uppercase', color: pa.beacon, marginBottom: 12 },
  darkMuted: { fontFamily: paFonts.regular, fontSize: 12, color: '#A8A39A', marginTop: 2 },
  darkAvatar: { width: 46, height: 46, borderRadius: 23, backgroundColor: '#2A2A2E', alignItems: 'center', justifyContent: 'center' },
  darkAvatarText: { fontFamily: paFonts.black, fontSize: 16, color: pa.beacon },
  darkName: { fontFamily: paFonts.black, fontSize: 16, color: '#fff' },
  when: { flexDirection: 'row', gap: 14, marginTop: 14, backgroundColor: '#1E1E22', borderRadius: 12, paddingHorizontal: 12, paddingVertical: 10 },
  whenItem: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  whenText: { fontFamily: paFonts.bold, fontSize: 12, color: '#E6E1D6' },
  eyebrow: { fontFamily: paFonts.black, fontSize: 10, letterSpacing: 1.2, textTransform: 'uppercase', color: W.sub, marginBottom: 3 },
  triageDot: { width: 16, height: 16, borderRadius: 8, shadowOpacity: 0.5, shadowRadius: 6, shadowOffset: { width: 0, height: 0 }, elevation: 3 },
  statGrid: { flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'space-between', rowGap: 12 },
  statCell: { width: '48.3%', flexDirection: 'row', alignItems: 'center', gap: 10, padding: 14 },
  statValue: { fontFamily: paFonts.black, fontSize: 22, color: W.ink, letterSpacing: -0.4, fontVariant: ['tabular-nums'] },
  service: { width: 78, alignItems: 'center', gap: 8 },
  serviceCircle: { width: 62, height: 62, borderRadius: 31, backgroundColor: pa.surface, alignItems: 'center', justifyContent: 'center', ...wShadow },
  serviceLabel: { fontFamily: paFonts.bold, fontSize: 11, color: pa.ink, textAlign: 'center', lineHeight: 14 },
  specCard: { width: 128, backgroundColor: pa.surface, borderRadius: W.radius, padding: 12, gap: 6, ...wShadow },
  specName: { fontFamily: paFonts.black, fontSize: 13, color: W.ink, lineHeight: 16, minHeight: 32 },
  note: { flexDirection: 'row', alignItems: 'center', gap: 8, marginTop: 10, backgroundColor: pa.beaconWash, borderRadius: 10, padding: 10 },
  noteText: { flex: 1, fontFamily: paFonts.bold, fontSize: 12, color: '#7A5C00' },
  aid: { borderRadius: W.radius, padding: 20, minHeight: 170, overflow: 'hidden', ...wShadow },
  aidGlow: { position: 'absolute', right: -50, top: -50, width: 180, height: 180, borderRadius: 90, backgroundColor: 'rgba(242,194,0,0.22)' },
  aidScheme: { fontFamily: paFonts.black, fontSize: 20, color: '#fff', marginTop: 14 },
  aidNumber: { fontFamily: paFonts.bold, fontSize: 18, letterSpacing: 2.5, color: '#fff', marginTop: 26, fontVariant: ['tabular-nums'] },
  aidName: { fontFamily: paFonts.bold, fontSize: 11, letterSpacing: 1.2, textTransform: 'uppercase', color: 'rgba(255,255,255,0.7)', marginTop: 4 },
  dialGrid: { flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'space-between', rowGap: 10 },
  dial: { width: '48.5%', flexDirection: 'row', alignItems: 'center', gap: 10, backgroundColor: pa.surface, borderRadius: 14, padding: 12, ...wShadow },
  dialPrimary: { backgroundColor: pa.sats.red },
  dialIcon: { width: 34, height: 34, borderRadius: 17, backgroundColor: pa.signalWash, alignItems: 'center', justifyContent: 'center' },
  catGrid: { flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'space-between', rowGap: 10 },
  catCard: { width: '48.5%', flexDirection: 'row', alignItems: 'center', gap: 10, backgroundColor: pa.surface, borderRadius: 14, padding: 12, ...wShadow },
  catImg: { width: 44, height: 44, borderRadius: 10 },
  product: { width: 148, backgroundColor: pa.surface, borderRadius: W.radius, padding: 10, ...wShadow },
  productImg: { width: '100%', height: 96, borderRadius: 12, marginBottom: 8 },
  productName: { fontFamily: paFonts.bold, fontSize: 13, lineHeight: 17, color: W.ink, minHeight: 34 },
  productPrice: { fontFamily: paFonts.black, fontSize: 15, color: W.ink, marginTop: 4 },
  rx: { fontFamily: paFonts.bold, fontSize: 10, color: pa.signal, marginTop: 2 },
  fact: { flex: 1, backgroundColor: W.soft, borderRadius: 12, padding: 10, gap: 4 },
  factValue: { fontFamily: paFonts.black, fontSize: 16, color: W.ink },
});
