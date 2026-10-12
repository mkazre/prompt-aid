import React, { useCallback, useState } from 'react';
import { Alert, ScrollView, Text, View } from 'react-native';
import { api, apiErrorMessage } from '../../../api/client';
import { Appointment, SlotSchedule } from '../../../api/types';
import SlotPicker from '../../../components/SlotPicker';
import { PrimaryButton } from '../../../components/settings-ui';
import { hs } from '../../../components/HealthUI';
import { addDays, longDate, parseYmd, startOfToday, time12, ymd } from '../../../services/dates';

/** Move an upcoming appointment to another free slot of the same doctor. */
export default function RescheduleScreen({ route, navigation }: any) {
  const appointment: Appointment = route.params.appointment;
  const [date, setDate] = useState(ymd(addDays(startOfToday(), 1)));
  const [time, setTime] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const load = useCallback(
    async (d: string): Promise<SlotSchedule> => {
      const { data } = await api.get(`/appointments/${appointment.id}/reschedule-slots`, { params: { date: d } });
      return data;
    },
    [appointment.id],
  );

  async function submit() {
    if (!time) return;
    setSaving(true);
    try {
      await api.post(`/appointments/${appointment.id}/reschedule`, { date, start_time: time });
      Alert.alert('Appointment moved', `${longDate(parseYmd(date))} at ${time12(time)}. Your doctor has been notified.`);
      navigation.goBack();
    } catch (e) {
      Alert.alert('Could not reschedule', apiErrorMessage(e));
    } finally {
      setSaving(false);
    }
  }

  return (
    <ScrollView style={hs.screen} contentContainerStyle={{ padding: 16, paddingBottom: 40 }}>
      <View style={hs.card}>
        <Text style={hs.label}>Currently booked</Text>
        <Text style={hs.title}>{appointment.doctor?.name ?? 'Your doctor'}</Text>
        <Text style={hs.sub}>{longDate(parseYmd(appointment.date))} at {appointment.start_time?.slice(0, 5)}</Text>
      </View>
      <View style={hs.card}>
        <SlotPicker load={load} reloadKey={appointment.id} date={date} onDateChange={(d) => { setDate(d); setTime(null); }} time={time} onTimeChange={setTime} />
      </View>
      <PrimaryButton title={time ? `Move to ${time12(time)}` : 'Choose a time'} onPress={submit} disabled={!time} loading={saving} />
    </ScrollView>
  );
}
