import React, { useCallback, useMemo, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { api } from '../../api/client';
import { Appointment } from '../../api/types';
import { Badge, Card, EmptyState } from '../../components/UI';
import { colors, font, spacing } from '../../theme';

const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

function toKey(d: Date) {
  return d.toISOString().slice(0, 10);
}

export default function DoctorCalendarScreen() {
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
    <ScrollView style={styles.container} contentContainerStyle={{ padding: spacing.lg, paddingBottom: spacing.xxl }}>
      <Card>
        <View style={styles.monthHeader}>
          <Pressable onPress={() => setCursor(new Date(cursor.getFullYear(), cursor.getMonth() - 1, 1))}>
            <Text style={styles.monthArrow}>‹</Text>
          </Pressable>
          <View style={{ alignItems: 'center' }}>
            <Text style={styles.today}>Today</Text>
            <Text style={styles.monthTitle}>{MONTHS[cursor.getMonth()]} {cursor.getFullYear()}</Text>
          </View>
          <Pressable onPress={() => setCursor(new Date(cursor.getFullYear(), cursor.getMonth() + 1, 1))}>
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
      </Card>

      <View style={styles.selectedBanner}>
        <Text style={styles.selectedText}>
          {selected.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: '2-digit', year: 'numeric' })}
        </Text>
      </View>

      <Text style={styles.sectionTitle}>Today's Appointment{appointments.length !== 1 ? 's' : ''}</Text>
      {appointments.length === 0 ? (
        <Card><EmptyState message="No appointments on this day." /></Card>
      ) : (
        appointments.map((a) => (
          <Card key={a.id} style={{ marginBottom: spacing.sm }}>
            <View style={styles.apptRow}>
              <Text style={styles.apptPatient}>{a.patient?.name ?? 'Patient'}</Text>
              <Text style={styles.apptTime}> ({a.start_time?.slice(0, 5)} - {a.end_time?.slice(0, 5)})</Text>
              <View style={{ flex: 1 }} />
              <Badge status={a.status} />
            </View>
            <Text style={styles.apptMeta}>Clinic: <Text style={styles.apptMetaValue}>{a.clinic?.name}</Text></Text>
            {a.reason ? <Text style={styles.apptMeta}>Reason: <Text style={styles.apptMetaValue}>{a.reason}</Text></Text> : null}
          </Card>
        ))
      )}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: colors.gray50 },
  monthHeader: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: spacing.md },
  monthArrow: { fontSize: 26, color: colors.gray500, paddingHorizontal: spacing.sm },
  today: { fontFamily: font.regular, fontSize: 11, color: colors.gray500 },
  monthTitle: { fontFamily: font.bold, fontSize: 17, color: colors.secondary },
  weekRow: { flexDirection: 'row', justifyContent: 'space-between', marginBottom: 6 },
  weekday: { flex: 1, textAlign: 'center', fontFamily: font.medium, fontSize: 12, color: colors.gray500 },
  dayCell: { flex: 1, aspectRatio: 1, alignItems: 'center', justifyContent: 'center', borderRadius: 999 },
  dayCellSelected: { backgroundColor: colors.primary },
  dayText: { fontFamily: font.regular, fontSize: 13, color: colors.gray700 },
  dayTextSelected: { color: colors.white, fontFamily: font.bold },
  selectedBanner: { backgroundColor: colors.gray100, borderRadius: 8, paddingVertical: spacing.sm, alignItems: 'center', marginTop: spacing.md, marginBottom: spacing.lg },
  selectedText: { fontFamily: font.medium, fontSize: 13, color: colors.gray700 },
  sectionTitle: { fontFamily: font.bold, fontSize: 16, color: colors.secondary, marginBottom: spacing.sm },
  apptRow: { flexDirection: 'row', alignItems: 'center', flexWrap: 'wrap' },
  apptPatient: { fontFamily: font.semibold, fontSize: 14, color: colors.primary },
  apptTime: { fontFamily: font.regular, fontSize: 12, color: colors.gray500 },
  apptMeta: { fontFamily: font.regular, fontSize: 12, color: colors.gray500, marginTop: 4 },
  apptMetaValue: { fontFamily: font.medium, color: colors.gray700 },
});
