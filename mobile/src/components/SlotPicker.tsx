import React, { useEffect, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { SlotSchedule, SlotSession } from '../api/types';
import { pa, paFonts } from '../theme';
import { MONTHS, WEEKDAYS_SHORT, addDays, parseYmd, sameDay, startOfToday, time12, ymd } from '../services/dates';
import { IconName, R, Sheet, softShadow } from './settings-ui';

/**
 * Month/year picker + "Today" + horizontal day strip + "Available slots"
 * grouped by the doctor's availability sessions. Booked slots render red
 * and struck through; past/blocked slots grey. Fed by any endpoint that
 * returns a SlotSchedule (patient: /doctors/{id}/available-slots,
 * doctor: /doctor/slots).
 */
export default function SlotPicker({
  load,
  reloadKey,
  date,
  onDateChange,
  time,
  onTimeChange,
}: {
  load: (date: string) => Promise<SlotSchedule>;
  reloadKey?: string | number;
  date: string;
  onDateChange: (date: string) => void;
  time: string | null;
  onTimeChange: (time: string | null) => void;
}) {
  const today = startOfToday();
  const selected = parseYmd(date);
  const [schedule, setSchedule] = useState<SlotSchedule | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [monthSheet, setMonthSheet] = useState(false);
  const stripRef = useRef<ScrollView>(null);
  const requestId = useRef(0);

  useEffect(() => {
    const id = ++requestId.current;
    setLoading(true);
    setError(null);
    load(date)
      .then((res) => {
        if (id === requestId.current) setSchedule(res);
      })
      .catch(() => {
        if (id === requestId.current) {
          setSchedule(null);
          setError('Could not load times. Pull to refresh or pick another day.');
        }
      })
      .finally(() => {
        if (id === requestId.current) setLoading(false);
      });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [date, reloadKey]);

  const days = useMemo(() => {
    const first = new Date(selected.getFullYear(), selected.getMonth(), 1);
    const start = first < today ? today : first;
    const end = new Date(selected.getFullYear(), selected.getMonth() + 1, 0);
    const out: Date[] = [];
    for (let d = new Date(start); d <= end; d = addDays(d, 1)) out.push(new Date(d));
    return out;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selected.getFullYear(), selected.getMonth()]);

  useEffect(() => {
    const idx = days.findIndex((d) => sameDay(d, selected));
    if (idx > 2) setTimeout(() => stripRef.current?.scrollTo({ x: (idx - 2) * 62, animated: true }), 50);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [date, days.length]);

  const months = useMemo(() => {
    const out: Date[] = [];
    for (let i = 0; i < 12; i++) out.push(new Date(today.getFullYear(), today.getMonth() + i, 1));
    return out;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  function pickDate(d: Date) {
    onTimeChange(null);
    onDateChange(ymd(d));
  }

  function pickMonth(m: Date) {
    setMonthSheet(false);
    pickDate(m < today ? today : m);
  }

  // Older API builds only return `slots` — present them as one session.
  const sessions: SlotSession[] = schedule?.sessions
    ? schedule.sessions
    : schedule?.slots?.length
      ? [{ label: 'Session 1', start: schedule.slots[0], end: schedule.slots[schedule.slots.length - 1], slot_minutes: 0, slots: schedule.slots.map((t) => ({ time: t, available: true, booked: false, past: false, blocked: false })) }]
      : [];

  const anyAvailable = sessions.some((s) => s.slots.some((c) => c.available));

  return (
    <View>
      <View style={st.monthRow}>
        <Pressable onPress={() => setMonthSheet(true)} style={st.monthBtn} accessibilityRole="button" accessibilityLabel="Choose month">
          <Text style={st.monthText}>{MONTHS[selected.getMonth()]} {selected.getFullYear()}</Text>
          <Ionicons name="chevron-down" size={16} color={pa.ink} />
        </Pressable>
        <Pressable onPress={() => pickDate(today)} style={st.todayBtn} accessibilityRole="button">
          <Text style={st.todayText}>Today</Text>
        </Pressable>
      </View>

      <ScrollView ref={stripRef} horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 8, paddingVertical: 4, paddingRight: 8 }}>
        {days.map((d) => {
          const active = sameDay(d, selected);
          return (
            <Pressable key={ymd(d)} onPress={() => pickDate(d)} style={[st.day, active && st.dayActive]} accessibilityLabel={`${WEEKDAYS_SHORT[d.getDay()]} ${d.getDate()}`}>
              <Text style={[st.dayName, active && st.dayTextActive]}>{WEEKDAYS_SHORT[d.getDay()]}</Text>
              <Text style={[st.dayNum, active && st.dayTextActive]}>{d.getDate()}</Text>
            </Pressable>
          );
        })}
      </ScrollView>

      <View style={st.slotsHead}>
        <Text style={st.slotsTitle}>Available slots</Text>
        {loading ? <ActivityIndicator size="small" color={pa.signal} /> : null}
      </View>

      {error ? (
        <Text style={st.note}>{error}</Text>
      ) : schedule?.time_off ? (
        <View style={st.offBox}>
          <Ionicons name="umbrella-outline" size={18} color={pa.signal} />
          <Text style={st.offText}>Not available on this day ({schedule.time_off}). Please choose another date.</Text>
        </View>
      ) : !loading && sessions.length === 0 ? (
        <Text style={st.note}>No sessions on this day. Try another date.</Text>
      ) : (
        sessions.map((session) => (
          <View key={session.label + session.start} style={{ marginBottom: 14 }}>
            <View style={st.sessionHead}>
              <Ionicons name={sessionIcon(session.start)} size={18} color={pa.beacon} />
              <Text style={st.sessionTitle}>{session.label}</Text>
              <Text style={st.sessionTime}>{time12(session.start)} – {time12(session.end)}</Text>
            </View>
            <View style={st.grid}>
              {session.slots.map((c) => {
                const isSel = time === c.time && c.available;
                const struck = !c.available;
                return (
                  <Pressable
                    key={c.time}
                    disabled={!c.available}
                    onPress={() => onTimeChange(c.time)}
                    style={[st.slot, isSel && st.slotActive, c.booked && st.slotBooked, (c.past || c.blocked) && !c.booked && st.slotPast]}
                    accessibilityState={{ disabled: !c.available, selected: isSel }}
                    accessibilityLabel={`${time12(c.time)}${c.booked ? ', booked' : !c.available ? ', unavailable' : ''}`}
                  >
                    <Text
                      style={[
                        st.slotText,
                        isSel && { color: '#fff' },
                        c.booked && { color: pa.signal },
                        (c.past || c.blocked) && !c.booked && { color: pa.muted2 },
                        struck && { textDecorationLine: 'line-through' },
                      ]}
                    >
                      {time12(c.time)}
                    </Text>
                  </Pressable>
                );
              })}
            </View>
          </View>
        ))
      )}

      {!loading && sessions.length > 0 && !anyAvailable && !schedule?.time_off ? (
        <Text style={st.note}>Fully booked on this day. Try another date.</Text>
      ) : null}

      <Sheet visible={monthSheet} onClose={() => setMonthSheet(false)} title="Choose a month">
        <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
          {months.map((m) => {
            const active = m.getMonth() === selected.getMonth() && m.getFullYear() === selected.getFullYear();
            return (
              <Pressable key={ymd(m)} onPress={() => pickMonth(m)} style={[st.monthCell, active && st.dayActive]}>
                <Text style={[st.monthCellText, active && st.dayTextActive]}>{MONTHS[m.getMonth()].slice(0, 3)} {m.getFullYear()}</Text>
              </Pressable>
            );
          })}
        </View>
      </Sheet>
    </View>
  );
}

function sessionIcon(start: string): IconName {
  const h = Number(start.slice(0, 2));
  if (h < 12) return 'sunny-outline';
  if (h < 17) return 'partly-sunny-outline';
  return 'moon-outline';
}

const st = StyleSheet.create({
  monthRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 12 },
  monthBtn: { flexDirection: 'row', alignItems: 'center', gap: 6, paddingVertical: 6 },
  monthText: { fontFamily: paFonts.black, fontSize: 16, color: pa.ink },
  todayBtn: { borderWidth: 1, borderColor: pa.signal, borderRadius: R.pill, paddingHorizontal: 14, paddingVertical: 6 },
  todayText: { fontFamily: paFonts.bold, fontSize: 13, color: pa.signal },
  day: { width: 54, paddingVertical: 10, borderRadius: 16, alignItems: 'center', backgroundColor: pa.surface, ...softShadow },
  dayActive: { backgroundColor: pa.signal },
  dayName: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted },
  dayNum: { fontFamily: paFonts.black, fontSize: 18, color: pa.ink, marginTop: 2 },
  dayTextActive: { color: '#fff' },
  slotsHead: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginTop: 18, marginBottom: 10 },
  slotsTitle: { fontFamily: paFonts.bold, fontSize: 15, color: pa.ink },
  sessionHead: { flexDirection: 'row', alignItems: 'center', gap: 8, marginBottom: 8 },
  sessionTitle: { fontFamily: paFonts.bold, fontSize: 13, color: pa.ink },
  sessionTime: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted },
  grid: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  slot: { width: '31%', paddingVertical: 10, borderRadius: R.input, borderWidth: 1, borderColor: pa.line, backgroundColor: pa.surface, alignItems: 'center' },
  slotActive: { backgroundColor: pa.signal, borderColor: pa.signal },
  slotBooked: { backgroundColor: pa.signalWash, borderColor: pa.signalWash },
  slotPast: { backgroundColor: pa.lineSoft, borderColor: pa.lineSoft },
  slotText: { fontFamily: paFonts.bold, fontSize: 13, color: pa.ink },
  note: { fontFamily: paFonts.regular, fontSize: 13, color: pa.muted, marginBottom: 12 },
  offBox: { flexDirection: 'row', gap: 8, alignItems: 'center', backgroundColor: pa.signalWash, padding: 12, borderRadius: R.input, marginBottom: 12 },
  offText: { flex: 1, fontFamily: paFonts.regular, fontSize: 13, color: pa.signalInk },
  monthCell: { width: '31%', paddingVertical: 12, borderRadius: R.input, backgroundColor: pa.paper, alignItems: 'center' },
  monthCellText: { fontFamily: paFonts.bold, fontSize: 13, color: pa.ink },
});
