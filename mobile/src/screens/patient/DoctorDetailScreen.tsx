import React, { useEffect, useState } from 'react';
import { Alert, ScrollView, StyleSheet, Text, View } from 'react-native';
import { api, apiErrorMessage } from '../../api/client';
import { Doctor } from '../../api/types';
import { Card, Input, PrimaryButton, SectionTitle } from '../../components/UI';
import { colors, font, radius, spacing } from '../../theme';

export default function DoctorDetailScreen({ route, navigation }: any) {
  const { doctorId } = route.params;
  const [doctor, setDoctor] = useState<Doctor | null>(null);
  const [clinicId, setClinicId] = useState<number | null>(null);
  const [date, setDate] = useState(defaultDate());
  const [slots, setSlots] = useState<string[]>([]);
  const [slot, setSlot] = useState<string | null>(null);
  const [reason, setReason] = useState('');
  const [booking, setBooking] = useState(false);
  const [loadingSlots, setLoadingSlots] = useState(false);

  useEffect(() => {
    api.get(`/doctors/${doctorId}`).then(({ data }) => {
      setDoctor(data.data);
      if (data.data.clinics?.length) setClinicId(data.data.clinics[0].id);
    });
  }, [doctorId]);

  useEffect(() => {
    if (!clinicId || !date) return;
    setLoadingSlots(true);
    setSlot(null);
    api
      .get(`/doctors/${doctorId}/available-slots`, { params: { clinic_id: clinicId, date } })
      .then(({ data }) => setSlots(data.slots))
      .finally(() => setLoadingSlots(false));
  }, [clinicId, date]);

  async function handleBook() {
    if (!clinicId || !slot) return;
    setBooking(true);
    try {
      await api.post('/appointments', {
        doctor_profile_id: doctorId,
        clinic_id: clinicId,
        date,
        start_time: slot,
        visit_type: 'clinic',
        reason: reason || undefined,
      });
      Alert.alert('Booked!', 'Your appointment is pending confirmation.', [
        { text: 'View my appointments', onPress: () => navigation.navigate('Appointments') },
      ]);
    } catch (e) {
      Alert.alert('Booking failed', apiErrorMessage(e));
    } finally {
      setBooking(false);
    }
  }

  if (!doctor) return null;

  return (
    <ScrollView style={styles.container} contentContainerStyle={{ padding: spacing.lg }}>
      <Card>
        <Text style={styles.name}>{doctor.name}</Text>
        <Text style={styles.spec}>{doctor.specialization} · {doctor.experience_years} yrs experience</Text>
        <Text style={styles.meta}>★ {doctor.rating_avg.toFixed(1)} ({doctor.rating_count} reviews)</Text>
        <Text style={styles.bio}>{doctor.bio}</Text>
      </Card>

      <SectionTitle>Book an appointment</SectionTitle>
      <Card>
        <Text style={styles.fee}>Consultation fee: R{doctor.consultation_fee}</Text>

        <Text style={styles.label}>Clinic</Text>
        <View style={styles.chipRow}>
          {doctor.clinics.map((c) => (
            <Chip key={c.id} label={c.name} active={clinicId === c.id} onPress={() => setClinicId(c.id)} />
          ))}
        </View>

        <Input label="Date (YYYY-MM-DD)" value={date} onChangeText={setDate} placeholder="2026-09-20" />

        <Text style={styles.label}>Available time slots</Text>
        {loadingSlots ? (
          <Text style={styles.mutedText}>Loading slots...</Text>
        ) : slots.length ? (
          <View style={styles.chipRow}>
            {slots.map((s) => (
              <Chip key={s} label={s} active={slot === s} onPress={() => setSlot(s)} />
            ))}
          </View>
        ) : (
          <Text style={styles.mutedText}>No slots available this day — try another date.</Text>
        )}

        <Input label="Reason for visit (optional)" value={reason} onChangeText={setReason} placeholder="Briefly describe your symptoms" multiline />

        <PrimaryButton title="Confirm booking" onPress={handleBook} loading={booking} disabled={!slot} />
      </Card>
    </ScrollView>
  );
}

function Chip({ label, active, onPress }: { label: string; active: boolean; onPress: () => void }) {
  return (
    <Text onPress={onPress} style={[styles.chip, active && styles.chipActive]}>
      {label}
    </Text>
  );
}

function defaultDate() {
  const d = new Date();
  d.setDate(d.getDate() + 1);
  return d.toISOString().slice(0, 10);
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: colors.gray50 },
  name: { fontFamily: font.bold, fontSize: 20, color: colors.secondary },
  spec: { fontFamily: font.regular, color: colors.gray600, marginTop: 2 },
  meta: { fontFamily: font.medium, color: colors.accent, marginTop: 6 },
  bio: { fontFamily: font.regular, color: colors.gray700, marginTop: spacing.sm, lineHeight: 20 },
  fee: { fontFamily: font.semibold, color: colors.secondary, marginBottom: spacing.md },
  label: { fontFamily: font.medium, fontSize: 12, color: colors.gray600, marginBottom: 6, marginTop: spacing.sm },
  mutedText: { fontFamily: font.regular, color: colors.gray500, fontSize: 13 },
  chipRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginBottom: spacing.sm },
  chip: {
    borderWidth: 1,
    borderColor: colors.gray300,
    borderRadius: radius.full,
    paddingHorizontal: 14,
    paddingVertical: 8,
    fontFamily: font.medium,
    fontSize: 13,
    color: colors.gray700,
    overflow: 'hidden',
  },
  chipActive: { backgroundColor: colors.primary, color: colors.white, borderColor: colors.primary },
});
