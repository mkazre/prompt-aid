import React, { useCallback, useMemo, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect, useNavigation } from '@react-navigation/native';
import { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { api } from '../../api/client';
import { Appointment } from '../../api/types';
import { PaBadge, PaCard, PaEmptyState, PaRow, PaSectionLabel } from '../../components/pa';
import { pa, paFonts, type } from '../../theme';
import { DoctorStackParamList } from '../../navigation/DoctorTabs';

type Nav = NativeStackNavigationProp<DoctorStackParamList>;

const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

function toKey(d: Date) {
  return d.toISOString().slice(0, 10);
}

export default function DoctorCalendarScreen() {
  const navigation = useNavigation<Nav>();
  const [cursor, setCursor] = useState(new Date());
  const [selected, setSelected] = useState(new Date());
  const [appointments, setAppointments] = useState<Appointment[]>([]);

  const load = useCallback(async (date: Date) => {
    const { data } = await api.get('/doctor/appointments', { params: { date: toKey(date) } });
    setAppointments(data.data);
  }, []);

  useFocusEffect(
    useCallback(() => {
      load(selected);
    }, [load, selected])
  );

  function openAppointment(appointment: Appointment) {
    if (appointment.mode === 'video' && appointment.meet_url) {
      navigation.navigate('ConsultRoom', { appointment });
    } else {
      navigation.navigate('DoctorEncounter', { appointment });
    }
  }

  const weeks = useMemo(() => {
    const year = cursor.getFullYear();
    const month = cursor.getMonth();
    const first = new Date(year, month, 1);
    const startOffset = (first.getDay() + 6) % 7; // Monday-first
    const daysInMonth = new Date(year, month + 1, 0).getDate();

    const cells: (Date | null)[] = [];
    for (let i = 0; i < startOffset; i++) cells.push(null);
    for (let d = 1; d <= daysInMonth; d++) cells.push(new Date(year, month, d));
    while (cells.length % 7 !== 0) cells.push(null);

    const rows: (Date | null)[][] = [];
    for (let i = 0; i < cells.length; i += 7) rows.push(cells.slice(i, i + 7));
    return rows;
  }, [cursor]);

  function isSameDay(a: Date, b: Date) {
    return a.toDateString() === b.toDateString();
  }

  return (
    <ScrollView style={styles.container} contentContainerStyle={{ padding: 16, paddingBottom: 48 }}>
      <PaCard>
        <View style={styles.monthHeader}>
          <Pressable onPress={() => setCursor(new Date(cursor.getFullYear(), cursor.getMonth() - 1, 1))} hitSlop={12}>
            <Text style={styles.monthArrow}>‹</Text>
          </Pressable>
          <View style={{ alignItems: 'center' }}>
            <Text style={type.small}>Today</Text>
            <Text style={styles.monthTitle}>{MONTHS[cursor.getMonth()]} {cursor.getFullYear()}</Text>
          </View>
          <Pressable onPress={() => setCursor(new Date(cursor.getFullYear(), cursor.getMonth() + 1, 1))} hitSlop={12}>
            <Text style={styles.monthArrow}>›</Text>
          </Pressable>
        </View>

        <View style={styles.weekRow}>
          {WEEKDAYS.map((d) => (
            <Text key={d} style={styles.weekday}>{d}</Text>
          ))}
        </View>

        {weeks.map((row, i) => (
          <View key={i} style={styles.weekRow}>
            {row.map((day, j) => {
              const isSelected = day && isSameDay(day, selected);
              return (
                <Pressable
                  key={j}
                  disabled={!day}
                  onPress={() => day && setSelected(day)}
                  style={[styles.dayCell, isSelected && styles.dayCellSelected]}
                >
                  {day ? <Text style={[styles.dayText, isSelected && styles.dayTextSelected]}>{day.getDate()}</Text> : null}
                </Pressable>
              );
            })}
          </View>
        ))}
      </PaCard>

      <View style={styles.selectedBanner}>
        <Text style={styles.selectedText}>
          {selected.toLocaleDateString('en-ZA', { weekday: 'long', month: 'long', day: '2-digit', year: 'numeric' })}
        </Text>
      </View>

      <PaSectionLabel style={{ marginBottom: 8 }}>Appointment{appointments.length !== 1 ? 's' : ''}</PaSectionLabel>
      {appointments.length === 0 ? (
        <PaCard><PaEmptyState message="No appointments on this day." /></PaCard>
      ) : (
        appointments.map((a) => (
          <PaCard key={a.id} style={{ marginBottom: 8, padding: 0, paddingHorizontal: 16 }}>
            <PaRow
              title={`${a.start_time?.slice(0, 5)} · ${a.patient?.name ?? 'Patient'}`}
              subtitle={`${a.clinic?.name ?? 'Clinic'}${a.reason ? ' · ' + a.reason : ''}`}
              right={<PaBadge status={a.status} />}
              onPress={() => openAppointment(a)}
            />
          </PaCard>
        ))
      )}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper },
  monthHeader: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 12 },
  monthArrow: { fontSize: 26, color: pa.muted, paddingHorizontal: 8 },
  monthTitle: { ...type.h3, color: pa.ink },
  weekRow: { flexDirection: 'row', justifyContent: 'space-between', marginBottom: 6 },
  weekday: { flex: 1, textAlign: 'center', fontFamily: paFonts.bold, fontSize: 11, color: pa.muted },
  dayCell: { flex: 1, aspectRatio: 1, alignItems: 'center', justifyContent: 'center' },
  dayCellSelected: { backgroundColor: pa.ink },
  dayText: { fontFamily: paFonts.regular, fontSize: 13, color: pa.inkSoft },
  dayTextSelected: { color: '#fff', fontFamily: paFonts.bold },
  selectedBanner: { backgroundColor: pa.lineSoft, paddingVertical: 8, alignItems: 'center', marginTop: 16, marginBottom: 16 },
  selectedText: { fontFamily: paFonts.bold, fontSize: 13, color: pa.inkSoft },
});
