import React, { useEffect, useMemo, useState } from 'react';
import { Alert, Pressable, StyleSheet, Switch, Text, View } from 'react-native';
import { Feather } from '@expo/vector-icons';
import { api, apiErrorMessage } from '../api/client';
import { Appointment } from '../api/types';
import { pa, paFonts } from '../theme';
import { WidgetProps } from './types';
import { goTo, headline, initials, money, timeAgo } from './helpers';
import { IconCircle, Pill, W, WButton, WCard, WEmpty, WHeader, s, statusPill } from './primitives';

// ---------------------------------------------------------------- doctor

const AVAILABILITY_OPTIONS: { value: 'available' | 'busy' | 'offline'; label: string; hint: string; color: string; bg: string; icon: 'radio' | 'clock' | 'moon' }[] = [
  { value: 'available', label: 'Green', hint: 'Can see a patient within 30 min — receives instant requests', color: '#1F7A4C', bg: '#E7F3EC', icon: 'radio' },
  { value: 'busy', label: 'Orange', hint: 'Available today at a booked slot', color: '#B4520F', bg: '#FCEEE2', icon: 'clock' },
  { value: 'offline', label: 'Red', hint: 'Not available today — patients book a future day', color: '#C8102E', bg: '#FBEAEA', icon: 'moon' },
];

/**
 * The doctor's live status (Prompt 4): green / orange / red, shown to
 * patients on the website and app next to the doctor's name.
 */
export function DoctorAvailability({ config, data, ctx }: WidgetProps<{ availability: string; specialization: string | null }>) {
  const [value, setValue] = useState(data?.availability ?? 'offline');
  const [busy, setBusy] = useState(false);
  useEffect(() => setValue(data?.availability ?? 'offline'), [data?.availability]);
  if (!data) return null;

  async function choose(next: 'available' | 'busy' | 'offline') {
    if (next === value) return;
    const prev = value;
    setValue(next);
    setBusy(true);
    try {
      await api.post('/doctor/availability', { availability: next });
      ctx.refresh();
    } catch (e) {
      setValue(prev);
      Alert.alert('Could not update your status', apiErrorMessage(e));
    } finally {
      setBusy(false);
    }
  }

  const current = AVAILABILITY_OPTIONS.find((o) => o.value === value) ?? AVAILABILITY_OPTIONS[2];

  return (
    <WCard>
      <View style={{ flexDirection: 'row', alignItems: 'center', gap: 12, marginBottom: 12 }}>
        <IconCircle name={current.icon} color={current.color} />
        <View style={{ flex: 1 }}>
          <Text style={s.rowTitle}>{config.title}</Text>
          <Text style={s.rowSub}>{current.hint}</Text>
        </View>
      </View>
      <View style={{ flexDirection: 'row', gap: 8 }}>
        {AVAILABILITY_OPTIONS.map((o) => {
          const on = o.value === value;
          return (
            <Pressable
              key={o.value}
              disabled={busy}
              onPress={() => choose(o.value)}
              style={{ flex: 1, alignItems: 'center', paddingVertical: 10, borderRadius: 10, borderWidth: on ? 2 : 1, borderColor: on ? o.color : '#E0DACB', backgroundColor: on ? o.bg : '#fff' }}
            >
              <View style={{ width: 12, height: 12, borderRadius: 6, backgroundColor: o.color, marginBottom: 4 }} />
              <Text style={{ fontFamily: paFonts.black, fontSize: 12, color: o.color }}>{o.label}</Text>
            </Pressable>
          );
        })}
      </View>
    </WCard>
  );
}

export function DoctorPendingRequests({ config, data, ctx }: WidgetProps<{ count: number; items: any[] }>) {
  const [accepting, setAccepting] = useState<number | null>(null);
  if (!data) return null;

  async function accept(id: number) {
    setAccepting(id);
    try {
      await api.post(`/doctor/requests/${id}/accept`);
      Alert.alert('Request accepted', 'The patient has been notified and the consult is on your list for today.');
      ctx.refresh();
    } catch (e) {
      Alert.alert('Could not accept', apiErrorMessage(e));
      ctx.refresh();
    } finally {
      setAccepting(null);
    }
  }

  return (
    <WCard>
      <WHeader title={config.title} subtitle={config.subtitle} />
      {data.count > 0 ? <View style={pr.countBadge}><Text style={pr.countText}>{data.count} waiting</Text></View> : null}
      {data.items.length === 0 ? <WEmpty icon="inbox" message="No one is waiting right now." /> : null}
      {data.items.map((r, i) => (
        <View key={r.id} style={[s.row, i === 0 && { borderTopWidth: 0 }]}>
          <IconCircle name={r.mode === 'video' ? 'video' : 'map-pin'} color={W.red} size={38} />
          <View style={{ flex: 1, minWidth: 0 }}>
            <Text style={s.rowTitle} numberOfLines={1}>{r.patient_name ?? 'Patient'}</Text>
            <Text style={s.rowSub} numberOfLines={1}>{r.reason ?? headline(r.mode)} · {timeAgo(r.created_at)}</Text>
          </View>
          <WButton title="Accept" small tone="green" loading={accepting === r.id} onPress={() => accept(r.id)} />
        </View>
      ))}
    </WCard>
  );
}

const NEXT: Record<string, { label: string; status: string; tone: 'green' | 'ink' }> = {
  pending: { label: 'Confirm', status: 'confirmed', tone: 'green' },
  confirmed: { label: 'Check in', status: 'checked_in', tone: 'ink' },
  checked_in: { label: 'Complete', status: 'completed', tone: 'green' },
};

export function DoctorTodayAppointments({ config, data, navigation, ctx }: WidgetProps<{ items: Appointment[] }>) {
  const [updating, setUpdating] = useState<number | null>(null);
  const items = data?.items ?? [];

  function open(a: Appointment) {
    if (a.is_callback || a.mode === 'callback') navigation.navigate('ConsultHub', { appointmentId: a.id });
    else if (a.is_video || a.mode === 'video' || a.visit_type === 'telemed') navigation.navigate('ConsultRoom', { appointment: a });
    else navigation.navigate('DoctorEncounter', { appointment: a });
  }

  async function update(id: number, status: string) {
    setUpdating(id);
    try {
      await api.post(`/doctor/appointments/${id}/status`, { status });
      ctx.refresh();
    } catch (e) {
      Alert.alert('Could not update', apiErrorMessage(e));
    } finally {
      setUpdating(null);
    }
  }

  return (
    <WCard>
      <WHeader title={config.title} subtitle={config.subtitle ?? `${items.length} booked`} action="Calendar" onAction={() => goTo(navigation, 'DoctorCalendar')} />
      {items.length === 0 ? <WEmpty icon="calendar" message="No appointments today." /> : null}
      {items.map((a, i) => {
        const next = NEXT[a.status];
        return (
          <View key={a.id} style={[pr.appt, i === 0 && { borderTopWidth: 0 }]}>
            <View style={pr.time}>
              <Text style={pr.timeText}>{a.start_time?.slice(0, 5)}</Text>
              <View style={[pr.timeLine, i === items.length - 1 && { opacity: 0 }]} />
            </View>
            <Pressable onPress={() => open(a)} style={{ flex: 1 }}>
              <View style={{ flexDirection: 'row', alignItems: 'center', gap: 10 }}>
                <View style={pr.avatar}><Text style={pr.avatarText}>{initials(a.patient?.name)}</Text></View>
                <View style={{ flex: 1, minWidth: 0 }}>
                  <Text style={s.rowTitle} numberOfLines={1}>{a.patient?.name ?? 'Patient'}</Text>
                  <Text style={s.rowSub} numberOfLines={1}>{a.reason ?? (a.mode === 'video' || a.visit_type === 'telemed' ? 'Video consult' : a.clinic?.name ?? 'Clinic')}</Text>
                </View>
                {statusPill(a.status)}
              </View>
              <View style={{ flexDirection: 'row', gap: 8, marginTop: 8 }}>
                {next ? <WButton title={next.label} small tone={next.tone} loading={updating === a.id} onPress={() => update(a.id, next.status)} /> : null}
                {!['completed', 'cancelled', 'no_show'].includes(a.status) ? (
                  <WButton title="Cancel" small tone="light" loading={updating === a.id} onPress={() => update(a.id, 'cancelled')} />
                ) : null}
                <WButton title="Message" small tone="light" onPress={() => navigation.navigate('Chat', { appointmentId: a.id, title: a.patient?.name ?? 'Patient' })} />
              </View>
            </Pressable>
          </View>
        );
      })}
    </WCard>
  );
}

const WEEKDAYS = ['M', 'T', 'W', 'T', 'F', 'S', 'S'];

function keyOf(d: Date) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/** Month grid with a dot per day that has appointments; drives the calendar screen's selected date. */
export function DoctorMonthCalendar({ data, ctx }: WidgetProps<{ from: string; to: string; counts: Record<string, number> }>) {
  const [cursor, setCursor] = useState(() => {
    const d = ctx.selectedDate ?? new Date();
    return new Date(d.getFullYear(), d.getMonth(), 1);
  });
  const selected = ctx.selectedDate ?? new Date();
  const counts = data?.counts ?? {};
  const todayKey = keyOf(new Date());

  const cells = useMemo(() => {
    const first = new Date(cursor.getFullYear(), cursor.getMonth(), 1);
    const offset = (first.getDay() + 6) % 7;
    const days = new Date(cursor.getFullYear(), cursor.getMonth() + 1, 0).getDate();
    const out: (Date | null)[] = Array(offset).fill(null);
    for (let d = 1; d <= days; d++) out.push(new Date(cursor.getFullYear(), cursor.getMonth(), d));
    while (out.length % 7) out.push(null);
    return out;
  }, [cursor]);

  const monthTotal = Object.entries(counts)
    .filter(([k]) => k.startsWith(`${cursor.getFullYear()}-${String(cursor.getMonth() + 1).padStart(2, '0')}`))
    .reduce((sum, [, v]) => sum + v, 0);

  return (
    <WCard>
      <View style={pr.monthHead}>
        <Pressable hitSlop={12} onPress={() => setCursor(new Date(cursor.getFullYear(), cursor.getMonth() - 1, 1))} style={pr.monthBtn}>
          <Feather name="chevron-left" size={20} color={W.ink} />
        </Pressable>
        <View style={{ alignItems: 'center' }}>
          <Text style={s.title}>{cursor.toLocaleDateString('en-ZA', { month: 'long', year: 'numeric' })}</Text>
          <Text style={s.subtitle}>{monthTotal} appointment{monthTotal === 1 ? '' : 's'}</Text>
        </View>
        <Pressable hitSlop={12} onPress={() => setCursor(new Date(cursor.getFullYear(), cursor.getMonth() + 1, 1))} style={pr.monthBtn}>
          <Feather name="chevron-right" size={20} color={W.ink} />
        </Pressable>
      </View>
      <View style={pr.weekRow}>
        {WEEKDAYS.map((d, i) => <Text key={i} style={pr.weekday}>{d}</Text>)}
      </View>
      <View style={pr.grid}>
        {cells.map((day, i) => {
          if (!day) return <View key={i} style={pr.cell} />;
          const k = keyOf(day);
          const isSel = k === keyOf(selected);
          const isToday = k === todayKey;
          const n = counts[k] ?? 0;
          return (
            <Pressable key={i} style={pr.cell} onPress={() => ctx.onSelectDate?.(day)}>
              <View style={[pr.day, isToday && pr.dayToday, isSel && pr.daySel]}>
                <Text style={[pr.dayText, isSel && { color: '#fff', fontFamily: paFonts.black }]}>{day.getDate()}</Text>
              </View>
              <View style={pr.dots}>
                {Array.from({ length: Math.min(n, 3) }).map((_, j) => <View key={j} style={[pr.dot, { backgroundColor: isSel ? pa.signal : W.blue }]} />)}
              </View>
            </Pressable>
          );
        })}
      </View>
    </WCard>
  );
}

// ---------------------------------------------------------------- driver

export function DriverNextPickup({ config, data, navigation }: WidgetProps<{ ride: any | null }>) {
  if (!data) return null;
  const r = data.ride;
  if (!r) {
    return (
      <WCard>
        <WHeader title={config.title} />
        <WEmpty icon="navigation" message="No active or scheduled trips. Go online to receive offers." />
      </WCard>
    );
  }
  const open = () => (r.is_active ? navigation.navigate('Trip') : goTo(navigation, 'Trips/TripDetail', { rideId: r.id }));
  return (
    <WCard onPress={open} style={r.priority === 'urgent' ? { borderWidth: 2, borderColor: pa.signal } : undefined}>
      <WHeader title={r.is_active ? 'Current trip' : config.title} subtitle={r.ride_ref} />
      <View style={{ flexDirection: 'row', gap: 12 }}>
        <View style={pr.route}>
          <View style={[pr.routeDot, { backgroundColor: W.green }]} />
          <View style={pr.routeLine} />
          <View style={[pr.routeDot, { backgroundColor: pa.signal }]} />
        </View>
        <View style={{ flex: 1, gap: 12 }}>
          <View>
            <Text style={s.rowSub}>Pickup</Text>
            <Text style={s.rowTitle} numberOfLines={2}>{r.pickup_address}</Text>
          </View>
          <View>
            <Text style={s.rowSub}>Drop-off</Text>
            <Text style={s.rowTitle} numberOfLines={2}>{r.dropoff_address}</Text>
          </View>
        </View>
      </View>
      <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8, marginTop: 12, flexWrap: 'wrap' }}>
        {statusPill(r.status)}
        {r.scheduled_for ? <Pill label={new Date(r.scheduled_for).toLocaleString('en-ZA', { weekday: 'short', hour: '2-digit', minute: '2-digit' })} /> : null}
        {r.patient_name ? <Pill label={r.patient_name} /> : null}
        {r.fare_estimate != null ? <Pill label={money(r.fare_estimate)} color={pa.go} bg={pa.goWash} /> : null}
      </View>
    </WCard>
  );
}

const pr = StyleSheet.create({
  countBadge: { position: 'absolute', right: 16, top: 16, backgroundColor: pa.signal, borderRadius: 999, paddingHorizontal: 10, paddingVertical: 4 },
  countText: { fontFamily: paFonts.black, fontSize: 11, color: '#fff' },
  appt: { flexDirection: 'row', gap: 10, paddingVertical: 10, borderTopWidth: 1, borderTopColor: '#F1EEE7' },
  time: { width: 44, alignItems: 'center' },
  timeText: { fontFamily: paFonts.black, fontSize: 12, color: W.ink },
  timeLine: { flex: 1, width: 2, backgroundColor: '#EDE9E0', marginTop: 6, borderRadius: 1 },
  avatar: { width: 36, height: 36, borderRadius: 18, backgroundColor: '#E8EEF4', alignItems: 'center', justifyContent: 'center' },
  avatarText: { fontFamily: paFonts.black, fontSize: 12, color: W.blue },
  monthHead: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 12 },
  monthBtn: { width: 36, height: 36, borderRadius: 18, backgroundColor: W.soft, alignItems: 'center', justifyContent: 'center' },
  weekRow: { flexDirection: 'row', marginBottom: 4 },
  weekday: { flex: 1, textAlign: 'center', fontFamily: paFonts.bold, fontSize: 11, color: W.sub },
  grid: { flexDirection: 'row', flexWrap: 'wrap' },
  cell: { width: `${100 / 7}%` as `${number}%`, alignItems: 'center', paddingVertical: 3 },
  day: { width: 34, height: 34, borderRadius: 17, alignItems: 'center', justifyContent: 'center' },
  dayToday: { borderWidth: 1.5, borderColor: W.blue },
  daySel: { backgroundColor: pa.ink, borderWidth: 0 },
  dayText: { fontFamily: paFonts.bold, fontSize: 13, color: W.ink },
  dots: { flexDirection: 'row', gap: 2, height: 5, marginTop: 2 },
  dot: { width: 5, height: 5, borderRadius: 3 },
  route: { alignItems: 'center', paddingTop: 6, width: 14 },
  routeDot: { width: 10, height: 10, borderRadius: 5 },
  routeLine: { flex: 1, width: 2, backgroundColor: '#E0DACB', marginVertical: 4 },
});
