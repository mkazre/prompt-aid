import React, { useEffect, useState } from 'react';
import { Alert, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { api, apiErrorMessage } from '../../api/client';
import { Doctor } from '../../api/types';
import { PaAvatar, PaBadge, PaButton, PaCard, PaInput, PaSectionLabel } from '../../components/pa';
import { pa, paFonts, paRadius, type } from '../../theme';

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
        { text: 'View my appointments', onPress: () => navigation.getParent()?.navigate('Appointments') },
      ]);
    } catch (e) {
      Alert.alert('Booking failed', apiErrorMessage(e));
    } finally {
      setBooking(false);
    }
  }

  if (!doctor) return null;

  const clinic = doctor.clinics.find((c) => c.id === clinicId);

  return (
    <ScrollView style={styles.container} contentContainerStyle={{ padding: 16, paddingBottom: 40 }}>
      <View style={styles.row}>
        <PaAvatar name={doctor.name} size={72} round={false} />
        <View style={{ flex: 1 }}>
          <Text style={styles.name}>{doctor.name}</Text>
          <Text style={styles.spec}>{doctor.specialization}</Text>
          <View style={{ flexDirection: 'row', gap: 6, marginTop: 8 }}>
            <PaBadge tone="go" label={`${doctor.rating_avg.toFixed(1)} · ${doctor.rating_count}`} />
          </View>
        </View>
      </View>

      {doctor.availability_summary ? <Text style={styles.meta}>{doctor.availability_summary}</Text> : null}
      {doctor.bio ? <Text style={styles.bio}>{doctor.bio}</Text> : null}

      {!doctor.is_accepting_appointments ? (
        <PaCard style={{ marginTop: 18 }}>
          <Text style={styles.muted}>This doctor is not currently accepting new appointments.</Text>
        </PaCard>
      ) : (
        <>
          <PaSectionLabel style={{ marginTop: 18, marginBottom: 8 }}>Clinic</PaSectionLabel>
          <View style={styles.chipRow}>
            {doctor.clinics.map((c) => (
              <Chip key={c.id} label={c.name} active={clinicId === c.id} onPress={() => setClinicId(c.id)} />
            ))}
          </View>

          <PaInput label="Date" value={date} onChangeText={setDate} placeholder="YYYY-MM-DD" />

          <PaSectionLabel style={{ marginBottom: 8 }}>Available times{loadingSlots ? ' (loading…)' : ''}</PaSectionLabel>
          {slots.length ? (
            <View style={styles.slotGrid}>
              {slots.map((s) => (
                <Pressable key={s} onPress={() => setSlot(s)} style={[styles.slotCell, slot === s && styles.slotCellActive]}>
                  <Text style={[styles.slotText, slot === s && styles.slotTextActive]}>{s}</Text>
                </Pressable>
              ))}
            </View>
          ) : (
            <Text style={styles.muted}>{loadingSlots ? 'Loading…' : 'No slots available this day — try another date.'}</Text>
          )}

          <PaInput label="Reason for visit (optional)" value={reason} onChangeText={setReason} placeholder="Briefly describe your symptoms" multiline />

          {clinic ? (
            <Pressable
              onPress={() => navigation.getParent()?.navigate('Rides', { screen: 'ShuttleRequest', params: { to: clinic.name } })}
              style={styles.shuttleBanner}
            >
              <Text style={styles.shuttleTitle}>Add a shuttle</Text>
              <Text style={styles.shuttleSub}>Fetch from home, return after — get a fare quote.</Text>
            </Pressable>
          ) : null}

          <PaButton title="Confirm booking" onPress={handleBook} loading={booking} disabled={!slot} style={{ marginTop: 16 }} />
        </>
      )}
    </ScrollView>
  );
}

function Chip({ label, active, onPress }: { label: string; active: boolean; onPress: () => void }) {
  return (
    <Pressable onPress={onPress} style={[styles.chip, active && styles.chipActive]}>
      <Text style={[styles.chipText, active && styles.chipTextActive]}>{label}</Text>
    </Pressable>
  );
}

function defaultDate() {
  const d = new Date();
  d.setDate(d.getDate() + 1);
  return d.toISOString().slice(0, 10);
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper },
  row: { flexDirection: 'row', gap: 14, marginBottom: 14 },
  name: { fontFamily: paFonts.black, fontSize: 18, color: pa.ink },
  spec: { fontFamily: paFonts.regular, fontSize: 13, color: pa.muted, marginTop: 3 },
  meta: { ...type.small, marginBottom: 8 },
  bio: { fontFamily: paFonts.regular, fontSize: 14, color: pa.inkSoft, lineHeight: 20, marginBottom: 8 },
  muted: { fontFamily: paFonts.regular, color: pa.muted, fontSize: 13 },
  chipRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginBottom: 14 },
  chip: { borderWidth: 1, borderColor: pa.line, borderRadius: paRadius.sm, paddingHorizontal: 14, paddingVertical: 9, backgroundColor: pa.surface },
  chipActive: { backgroundColor: pa.beaconWash, borderColor: pa.ink },
  chipText: { fontFamily: paFonts.bold, fontSize: 13, color: pa.ink },
  chipTextActive: { color: pa.ink },
  slotGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginBottom: 14 },
  slotCell: { width: '30%', paddingVertical: 10, borderWidth: 1, borderColor: pa.line, backgroundColor: pa.surface, alignItems: 'center' },
  slotCellActive: { backgroundColor: pa.ink, borderColor: pa.ink },
  slotText: { fontFamily: paFonts.bold, fontSize: 13, color: pa.ink },
  slotTextActive: { color: '#fff' },
  shuttleBanner: { backgroundColor: pa.beaconWash, borderWidth: 1, borderColor: pa.beaconLine, padding: 13, marginBottom: 14 },
  shuttleTitle: { fontFamily: paFonts.black, fontSize: 13, color: pa.ink },
  shuttleSub: { fontFamily: paFonts.regular, fontSize: 12, color: pa.inkSoft, marginTop: 2 },
});
