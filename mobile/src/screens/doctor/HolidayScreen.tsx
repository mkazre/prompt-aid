import React, { useCallback, useState } from 'react';
import { Alert, Pressable, RefreshControl, ScrollView, Switch, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { Ionicons } from '@expo/vector-icons';
import { api, apiErrorMessage } from '../../api/client';
import { DoctorTimeOffItem } from '../../api/types';
import { DateField, DatePickerSheet } from '../../components/DatePickerSheet';
import { EmptyNote, Fab, Field, PrimaryButton, Sheet, SoftCard } from '../../components/settings-ui';
import { pa, paFonts } from '../../theme';
import { parseYmd, shortDate, startOfToday, ymd } from '../../services/dates';

const HM = /^([01]\d|2[0-3]):[0-5]\d$/;

/** Holidays / time off. Patients can't book slots that fall inside these. */
export default function DoctorHolidayScreen() {
  const insets = useSafeAreaInsets();
  const [rows, setRows] = useState<DoctorTimeOffItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [adding, setAdding] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const { data } = await api.get('/doctor/time-off');
      setRows(data.data);
    } catch (e) {
      Alert.alert('Could not load time off', apiErrorMessage(e));
    } finally {
      setLoading(false);
    }
  }, []);

  useFocusEffect(useCallback(() => { load(); }, [load]));

  function remove(row: DoctorTimeOffItem) {
    Alert.alert('Remove time off', 'Patients will be able to book these days again.', [
      { text: 'Cancel', style: 'cancel' },
      {
        text: 'Remove',
        style: 'destructive',
        onPress: async () => {
          try {
            await api.delete(`/doctor/time-off/${row.id}`);
            setRows((r) => r.filter((x) => x.id !== row.id));
          } catch (e) {
            Alert.alert('Could not remove', apiErrorMessage(e));
          }
        },
      },
    ]);
  }

  const today = ymd(startOfToday());

  return (
    <View style={{ flex: 1, backgroundColor: pa.paper }}>
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: insets.bottom + 100 }} refreshControl={<RefreshControl refreshing={loading} onRefresh={load} />}>
        <Text style={{ fontFamily: paFonts.regular, fontSize: 13, color: pa.muted, marginBottom: 14 }}>
          Add holidays or blocked hours. Booking slots in these periods are hidden from patients automatically.
        </Text>
        {rows.length === 0 && !loading ? (
          <SoftCard><EmptyNote icon="umbrella-outline" text="No time off scheduled. Tap + to add a holiday." /></SoftCard>
        ) : (
          rows.map((r) => {
            const past = (r.end_date ?? r.date) < today;
            return (
              <SoftCard key={r.id} style={{ marginBottom: 10, flexDirection: 'row', alignItems: 'center', gap: 12, opacity: past ? 0.55 : 1 }}>
                <View style={{ width: 42, height: 42, borderRadius: 12, backgroundColor: pa.signalWash, alignItems: 'center', justifyContent: 'center' }}>
                  <Ionicons name="umbrella-outline" size={20} color={pa.signal} />
                </View>
                <View style={{ flex: 1 }}>
                  <Text style={{ fontFamily: paFonts.bold, fontSize: 14, color: pa.ink }}>
                    {shortDate(parseYmd(r.date))}{r.end_date ? ` – ${shortDate(parseYmd(r.end_date))}` : ''}
                  </Text>
                  <Text style={{ fontFamily: paFonts.regular, fontSize: 12, color: pa.muted, marginTop: 2 }}>
                    {r.start_time ? `${r.start_time} – ${r.end_time}` : 'All day'}{r.reason ? ` · ${r.reason}` : ''}{past ? ' · Past' : ''}
                  </Text>
                </View>
                <Pressable onPress={() => remove(r)} hitSlop={10} accessibilityLabel="Remove time off">
                  <Ionicons name="trash-outline" size={20} color={pa.signal} />
                </Pressable>
              </SoftCard>
            );
          })
        )}
      </ScrollView>

      <Fab label="Add time off" onPress={() => setAdding(true)} bottom={insets.bottom + 24} />
      {adding ? <AddTimeOffSheet onClose={() => setAdding(false)} onSaved={() => { setAdding(false); load(); }} /> : null}
    </View>
  );
}

function AddTimeOffSheet({ onClose, onSaved }: { onClose: () => void; onSaved: () => void }) {
  const [from, setFrom] = useState<string | null>(ymd(startOfToday()));
  const [to, setTo] = useState<string | null>(null);
  const [allDay, setAllDay] = useState(true);
  const [start, setStart] = useState('08:00');
  const [end, setEnd] = useState('12:00');
  const [reason, setReason] = useState('');
  const [picker, setPicker] = useState<'from' | 'to' | null>(null);
  const [saving, setSaving] = useState(false);

  async function save() {
    if (!from) return;
    if (!allDay && (!HM.test(start) || !HM.test(end) || end <= start)) {
      Alert.alert('Check the hours', 'Use 24-hour times like 08:00 and make sure the end is after the start.');
      return;
    }
    setSaving(true);
    try {
      const { data } = await api.post('/doctor/time-off', {
        date: from,
        end_date: to && to > from ? to : null,
        start_time: allDay ? null : start,
        end_time: allDay ? null : end,
        reason: reason.trim() || null,
      });
      if (data.conflicting_appointments > 0) {
        Alert.alert(
          'Time off saved',
          `${data.conflicting_appointments} existing appointment(s) fall in this period. Reschedule or cancel them from your calendar.`
        );
      }
      onSaved();
    } catch (e) {
      Alert.alert('Could not save', apiErrorMessage(e));
    } finally {
      setSaving(false);
    }
  }

  return (
    <Sheet visible onClose={onClose} title="Add time off" footer={<PrimaryButton title="Save time off" onPress={save} loading={saving} disabled={!from} />}>
      <View style={{ flexDirection: 'row', gap: 10 }}>
        <DateField label="From" value={from} onPress={() => setPicker('from')} />
        <DateField label="To (optional)" value={to} placeholder="Same day" onPress={() => setPicker('to')} />
      </View>
      <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 14 }}>
        <Text style={{ fontFamily: paFonts.bold, fontSize: 14, color: pa.ink }}>All day</Text>
        <Switch value={allDay} onValueChange={setAllDay} trackColor={{ true: pa.go, false: pa.line }} />
      </View>
      {!allDay ? (
        <View style={{ flexDirection: 'row', gap: 10 }}>
          <View style={{ flex: 1 }}><Field label="From (HH:MM)" value={start} onChangeText={setStart} maxLength={5} keyboardType="numbers-and-punctuation" /></View>
          <View style={{ flex: 1 }}><Field label="Until (HH:MM)" value={end} onChangeText={setEnd} maxLength={5} keyboardType="numbers-and-punctuation" /></View>
        </View>
      ) : null}
      <Field label="Reason (optional)" value={reason} onChangeText={setReason} placeholder="e.g. Annual leave, conference" maxLength={255} />

      <DatePickerSheet visible={picker === 'from'} value={from} onClose={() => setPicker(null)} onPick={(d) => { setFrom(d); if (to && to < d) setTo(null); }} title="Time off starts" />
      <DatePickerSheet visible={picker === 'to'} value={to} min={from ?? undefined} onClose={() => setPicker(null)} onPick={setTo} title="Time off ends" />
    </Sheet>
  );
}
