import React, { useEffect, useState } from 'react';
import { Alert, Linking, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { api, apiErrorMessage } from '../../api/client';
import { Doctor } from '../../api/types';
import { PaAvatar, PaBadge, PaButton, PaCard, PaInput, PaSectionLabel } from '../../components/pa';
import { pa, paFonts, paRadius, type } from '../../theme';
import { PaLiveStatus } from '../../components/pa/care-extras';
import { telUrl } from '../../services/siteContent';

const WEEK = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

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
      // Red = not available today, so start the booking on tomorrow.
      setDate(defaultDate(data.data.live_status === 'red' ? 1 : 0));
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
          <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginTop: 8 }}>
            <PaLiveStatus status={doctor.live_status} label={doctor.live_status_label} />
            <PaBadge tone="go" label={`${doctor.rating_avg.toFixed(1)} · ${doctor.rating_count}`} />
          </View>
        </View>
      </View>

      {doctor.live_status_description ? <Text style={styles.meta}>{doctor.live_status_description}</Text> : null}
      {doctor.bio ? <Text style={styles.bio}>{doctor.bio}</Text> : null}

      <PaSectionLabel style={{ marginTop: 12, marginBottom: 8 }}>Operating hours</PaSectionLabel>
      <PaCard style={{ paddingVertical: 4 }}>
        {doctor.operating_hours && Object.keys(doctor.operating_hours).length ? (
          WEEK.map((d) => (
            <View key={d} style={styles.hoursRow}>
              <Text style={styles.hoursDay}>{d}</Text>
              <Text style={[styles.hoursTime, !doctor.operating_hours?.[d] && { color: pa.muted }]}>{doctor.operating_hours?.[d] ?? 'Closed'}</Text>
            </View>
          ))
        ) : (
          <Text style={[styles.muted, { paddingVertical: 10 }]}>Operating hours have not been published yet.</Text>
        )}
      </PaCard>

      <PaSectionLabel style={{ marginTop: 16, marginBottom: 8 }}>Contact details</PaSectionLabel>
      <PaCard>
        {doctor.clinics.length ? (
          doctor.clinics.map((c) => (
            <View key={c.id} style={{ marginBottom: 8 }}>
              <Text style={styles.contactName}>{c.name}</Text>
              <Text style={styles.muted}>{c.address}{c.city ? `, ${c.city}` : ''}</Text>
              {c.phone ? <Text style={styles.link} onPress={() => Linking.openURL(telUrl(c.phone!))}>{c.phone}</Text> : null}
              {c.email ? <Text style={styles.link} onPress={() => Linking.openURL(`mailto:${c.email}`)}>{c.email}</Text> : null}
            </View>
          ))
        ) : (
          <Text style={styles.muted}>Book through PromptAid — video consultation available.</Text>
        )}
      </PaCard>

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

function defaultDate(daysAhead = 1) {
  const d = new Date();
  d.setDate(d.getDate() + daysAhead);
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
  hoursRow: { flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 8, borderBottomWidth: 1, borderBottomColor: pa.lineSoft },
  hoursDay: { fontFamily: paFonts.bold, fontSize: 13, color: pa.ink },
  hoursTime: { fontFamily: paFonts.bold, fontSize: 13, color: pa.ink },
  contactName: { fontFamily: paFonts.bold, fontSize: 14, color: pa.ink },
  link: { fontFamily: paFonts.bold, fontSize: 13, color: pa.signal, marginTop: 3 },
  shuttleBanner: { backgroundColor: pa.beaconWash, borderWidth: 1, borderColor: pa.beaconLine, padding: 13, marginBottom: 14 },
  shuttleTitle: { fontFamily: paFonts.black, fontSize: 13, color: pa.ink },
  shuttleSub: { fontFamily: paFonts.regular, fontSize: 12, color: pa.inkSoft, marginTop: 2 },
});
