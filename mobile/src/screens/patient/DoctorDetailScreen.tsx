import React, { useCallback, useEffect, useState } from 'react';
import { Alert, Image, KeyboardAvoidingView, Linking, Platform, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { api, apiErrorMessage } from '../../api/client';
import { Appointment, Doctor, SlotSchedule } from '../../api/types';
import { useAuth } from '../../context/AuthContext';
import { PaAvatar } from '../../components/pa';
import SlotPicker from '../../components/SlotPicker';
import SuccessModal from '../../components/SuccessModal';
import { Chip, Fab, PrimaryButton, R, SoftCard, s as ui } from '../../components/settings-ui';
import { pa, paFonts } from '../../theme';
import { PaLiveStatus } from '../../components/pa/care-extras';
import { telUrl } from '../../services/siteContent';

const WEEK = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
import { addDays, longDate, parseYmd, startOfToday, time12, ymd } from '../../services/dates';

type Mode = 'clinic' | 'telemed';

/**
 * Two-step booking. Step 1: doctor, service, clinic, in-person vs video,
 * month/day strip and session-grouped slots (booked slots struck through).
 * Step 2: patient name, notes, summary and confirm → POST /appointments.
 */
export default function DoctorDetailScreen({ route, navigation }: any) {
  const insets = useSafeAreaInsets();
  const { user } = useAuth();
  const { doctorId } = route.params;
  const [doctor, setDoctor] = useState<Doctor | null>(null);
  const [clinicId, setClinicId] = useState<number | null>(null);
  const [serviceId, setServiceId] = useState<number | null>(null);
  const [mode, setMode] = useState<Mode>('clinic');
  const [date, setDate] = useState(ymd(addDays(startOfToday(), 1)));
  const [slot, setSlot] = useState<string | null>(null);
  const [step, setStep] = useState<1 | 2>(1);
  const [reason, setReason] = useState('');
  const [booking, setBooking] = useState(false);
  const [reloadKey, setReloadKey] = useState(0);
  const [confirmed, setConfirmed] = useState<Appointment | null>(null);

  useEffect(() => {
    api
      .get(`/doctors/${doctorId}`)
      .then(({ data }) => {
        setDoctor(data.data);
        if (data.data.clinics?.length) setClinicId(data.data.clinics[0].id);
      })
      .catch((e) => Alert.alert('Could not load doctor', apiErrorMessage(e)));
  }, [doctorId]);

  const loadSlots = useCallback(
    async (d: string): Promise<SlotSchedule> => {
      if (!clinicId) return { slots: [], sessions: [] };
      const { data } = await api.get(`/doctors/${doctorId}/available-slots`, { params: { clinic_id: clinicId, date: d } });
      return data;
    },
    [doctorId, clinicId]
  );

  async function handleBook() {
    if (!clinicId || !slot) return;
    setBooking(true);
    try {
      const { data } = await api.post('/appointments', {
        doctor_profile_id: doctorId,
        clinic_id: clinicId,
        service_id: serviceId ?? undefined,
        date,
        start_time: slot,
        visit_type: mode,
        reason: reason.trim() || undefined,
      });
      setConfirmed(data.data);
      setReason('');
      setSlot(null);
      setReloadKey((k) => k + 1);
    } catch (e) {
      Alert.alert('Booking failed', apiErrorMessage(e));
      setReloadKey((k) => k + 1); // the slot may have just been taken
    } finally {
      setBooking(false);
    }
  }

  if (!doctor) return <View style={styles.container} />;

  const clinic = doctor.clinics.find((c) => c.id === clinicId);
  const services = (doctor.services ?? []).filter((sv) => !clinicId || sv.clinic_id === clinicId);
  const service = services.find((sv) => sv.id === serviceId) ?? null;
  const price = service ? service.price : doctor.consultation_fee;
  const firstName = (user?.name ?? '').split(' ')[0] || 'There';

  const header = (
    <SoftCard style={{ flexDirection: 'row', gap: 14, alignItems: 'center', marginBottom: 18 }}>
      {doctor.avatar_url ? (
        <Image source={{ uri: doctor.avatar_url }} style={{ width: 64, height: 64, borderRadius: 32 }} />
      ) : (
        <PaAvatar name={doctor.name} size={64} />
      )}
      <View style={{ flex: 1 }}>
        <Text style={styles.name}>{doctor.name}</Text>
        <Text style={styles.spec}>{doctor.specialization}</Text>
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 4, marginTop: 6 }}>
          <Ionicons name="star" size={14} color={pa.beacon} />
          <Text style={styles.meta}>{doctor.rating_avg.toFixed(1)} ({doctor.rating_count})</Text>
          <Text style={styles.meta}>  ·  R {Math.round(price)}</Text>
        </View>
        {doctor.live_status ? (
          <View style={{ marginTop: 8, gap: 4 }}>
            <PaLiveStatus status={doctor.live_status} label={doctor.live_status_label} />
            {doctor.live_status_description ? <Text style={styles.meta}>{doctor.live_status_description}</Text> : null}
          </View>
        ) : null}
      </View>
    </SoftCard>
  );

  // Prompt 4: Operating hours + Contact details boxes on the doctor's page.
  const infoBoxes = (
    <>
      <SoftCard style={{ marginBottom: 12 }}>
        <Text style={styles.label}>Operating hours</Text>
        {doctor.operating_hours && Object.keys(doctor.operating_hours).length ? (
          WEEK.map((d) => (
            <View key={d} style={styles.hoursRow}>
              <Text style={styles.hoursDay}>{d}</Text>
              <Text style={[styles.hoursDay, !doctor.operating_hours?.[d] && { color: pa.muted }]}>{doctor.operating_hours?.[d] ?? 'Closed'}</Text>
            </View>
          ))
        ) : (
          <Text style={styles.muted}>Operating hours have not been published yet.</Text>
        )}
      </SoftCard>
      <SoftCard style={{ marginBottom: 16 }}>
        <Text style={styles.label}>Contact details</Text>
        {doctor.clinics.length ? (
          doctor.clinics.map((c) => (
            <View key={c.id} style={{ marginBottom: 8 }}>
              <Text style={styles.hoursDay}>{c.name}</Text>
              <Text style={styles.muted}>{c.address}{c.city ? `, ${c.city}` : ''}</Text>
              {c.phone ? <Text style={styles.contactLink} onPress={() => Linking.openURL(telUrl(c.phone!))}>{c.phone}</Text> : null}
              {c.email ? <Text style={styles.contactLink} onPress={() => Linking.openURL(`mailto:${c.email}`)}>{c.email}</Text> : null}
            </View>
          ))
        ) : (
          <Text style={styles.muted}>Book through PromptAid — video consultation available.</Text>
        )}
      </SoftCard>
    </>
  );

  if (!doctor.is_accepting_appointments || !doctor.clinics.length) {
    return (
      <ScrollView style={styles.container} contentContainerStyle={{ padding: 16 }}>
        {header}
        {doctor.bio ? <Text style={styles.bio}>{doctor.bio}</Text> : null}
        {infoBoxes}
        <SoftCard>
          <Text style={styles.muted}>
            {doctor.clinics.length ? 'This doctor is not currently accepting new appointments.' : 'This doctor has no clinic linked for bookings yet.'}
          </Text>
        </SoftCard>
      </ScrollView>
    );
  }

  return (
    <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <View style={styles.container}>
        <View style={styles.stepBar}>
          <StepDot n={1} label="Select" active={step === 1} done={step === 2} />
          <View style={styles.stepLine} />
          <StepDot n={2} label="Confirm" active={step === 2} />
        </View>

        {step === 1 ? (
          <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: insets.bottom + 110 }}>
            {header}
            {infoBoxes}

            {doctor.availability_summary ? (
              <View style={styles.infoRow}>
                <Ionicons name="time-outline" size={16} color={pa.muted} />
                <Text style={styles.meta}>{doctor.availability_summary}</Text>
              </View>
            ) : null}

            {doctor.clinics.length > 1 ? (
              <>
                <Text style={styles.label}>Clinic</Text>
                <View style={styles.chipRow}>
                  {doctor.clinics.map((c) => (
                    <Chip key={c.id} label={c.name} active={clinicId === c.id} onPress={() => { setClinicId(c.id); setServiceId(null); setSlot(null); }} />
                  ))}
                </View>
              </>
            ) : null}

            {services.length ? (
              <>
                <Text style={styles.label}>Service</Text>
                <View style={styles.chipRow}>
                  <Chip label={`Consultation · R ${Math.round(doctor.consultation_fee)}`} active={serviceId === null} onPress={() => setServiceId(null)} />
                  {services.map((sv) => (
                    <Chip key={sv.id} label={`${sv.name} · R ${Math.round(sv.price)}`} active={serviceId === sv.id} onPress={() => setServiceId(sv.id)} />
                  ))}
                </View>
              </>
            ) : null}

            <Text style={styles.label}>Visit type</Text>
            <View style={styles.modeRow}>
              <ModeOption icon="business-outline" label="In person" active={mode === 'clinic'} onPress={() => setMode('clinic')} />
              <ModeOption icon="videocam-outline" label="Video call" active={mode === 'telemed'} onPress={() => setMode('telemed')} />
            </View>

            <SoftCard style={{ marginTop: 6 }}>
              <SlotPicker load={loadSlots} reloadKey={`${clinicId}-${reloadKey}`} date={date} onDateChange={setDate} time={slot} onTimeChange={setSlot} />
            </SoftCard>
          </ScrollView>
        ) : (
          <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: insets.bottom + 40 }} keyboardShouldPersistTaps="handled">
            {header}
            <SoftCard style={{ marginBottom: 16 }}>
              <SummaryRow icon="calendar-outline" label="Date" value={longDate(parseYmd(date))} />
              <SummaryRow icon="time-outline" label="Time" value={time12(slot)} />
              <SummaryRow icon={mode === 'telemed' ? 'videocam-outline' : 'business-outline'} label="Visit" value={mode === 'telemed' ? 'Video call' : clinic?.name ?? 'In person'} />
              <SummaryRow icon="medkit-outline" label="Service" value={service ? service.name : 'Consultation'} />
              <SummaryRow icon="card-outline" label="Fee" value={`R ${Math.round(price)}`} last />
            </SoftCard>

            <Text style={styles.label}>Patient name</Text>
            <View style={[ui.input, { backgroundColor: pa.paper, marginBottom: 14 }]}>
              <Text style={{ fontFamily: paFonts.regular, fontSize: 15, color: pa.ink }}>{user?.name}</Text>
            </View>

            <Text style={styles.label}>Notes for the doctor (optional)</Text>
            <TextInput
              value={reason}
              onChangeText={setReason}
              placeholder="Briefly describe your symptoms"
              placeholderTextColor={pa.muted2}
              multiline
              maxLength={1000}
              style={[ui.input, { minHeight: 96, textAlignVertical: 'top', marginBottom: 14 }]}
            />

            {clinic && mode === 'clinic' ? (
              <Pressable
                onPress={() => navigation.getParent()?.navigate('Rides', { screen: 'ShuttleRequest', params: {
                  to: [clinic.name, clinic.address, clinic.city].filter(Boolean).join(', '),
                  toLat: clinic.lat ?? undefined,
                  toLng: clinic.lng ?? undefined,
                } })}
                style={styles.shuttleBanner}
              >
                <Ionicons name="bus-outline" size={22} color={pa.ink} />
                <View style={{ flex: 1 }}>
                  <Text style={styles.shuttleTitle}>Add a shuttle</Text>
                  <Text style={styles.shuttleSub}>Fetch from home, return after — get a fare quote.</Text>
                </View>
                <Ionicons name="chevron-forward" size={18} color={pa.inkSoft} />
              </Pressable>
            ) : null}

            <View style={{ flexDirection: 'row', gap: 10 }}>
              <PrimaryButton title="Back" tone="ghost" onPress={() => setStep(1)} style={{ flex: 1 }} />
              <PrimaryButton title="Confirm booking" onPress={handleBook} loading={booking} disabled={!slot} style={{ flex: 2 }} />
            </View>
          </ScrollView>
        )}

        {step === 1 && slot ? (
          <Fab icon="arrow-forward" label="Continue to confirm" onPress={() => setStep(2)} bottom={insets.bottom + 24} />
        ) : null}
      </View>

      <SuccessModal
        visible={!!confirmed}
        title={`${firstName}, we've got you confirmed for your appointment`}
        message={confirmed ? `The clinic will confirm shortly. Ref ${confirmed.booking_ref}` : undefined}
        details={confirmed ? [
          { label: 'Date', value: longDate(parseYmd(confirmed.date)) },
          { label: 'Time', value: time12(confirmed.start_time) },
          { label: 'Doctor', value: doctor.name.startsWith('Dr') ? doctor.name : `Dr ${doctor.name}` },
          { label: 'Visit', value: mode === 'telemed' ? 'Video call' : clinic?.name ?? 'In person' },
        ] : undefined}
        primaryLabel="View my appointments"
        onPrimary={() => {
          setConfirmed(null);
          setStep(1);
          navigation.getParent()?.navigate('Profile', { screen: 'Appointments' });
        }}
        secondaryLabel="Done"
        onSecondary={() => { setConfirmed(null); setStep(1); }}
        onClose={() => { setConfirmed(null); setStep(1); }}
      />
    </KeyboardAvoidingView>
  );
}

function StepDot({ n, label, active, done }: { n: number; label: string; active?: boolean; done?: boolean }) {
  return (
    <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6 }}>
      <View style={[styles.dot, (active || done) && { backgroundColor: pa.signal, borderColor: pa.signal }]}>
        {done ? <Ionicons name="checkmark" size={13} color="#fff" /> : <Text style={[styles.dotText, active && { color: '#fff' }]}>{n}</Text>}
      </View>
      <Text style={[styles.stepLabel, active && { color: pa.ink }]}>Step {n}: {label}</Text>
    </View>
  );
}

function ModeOption({ icon, label, active, onPress }: { icon: React.ComponentProps<typeof Ionicons>['name']; label: string; active: boolean; onPress: () => void }) {
  return (
    <Pressable onPress={onPress} style={[styles.mode, active && styles.modeActive]} accessibilityState={{ selected: active }}>
      <Ionicons name={icon} size={18} color={active ? pa.signal : pa.muted} />
      <Text style={[styles.modeText, active && { color: pa.signal }]}>{label}</Text>
    </Pressable>
  );
}

function SummaryRow({ icon, label, value, last }: { icon: React.ComponentProps<typeof Ionicons>['name']; label: string; value: string; last?: boolean }) {
  return (
    <View style={[styles.sumRow, !last && styles.sumBorder]}>
      <Ionicons name={icon} size={18} color={pa.info} />
      <Text style={styles.sumLabel}>{label}</Text>
      <Text style={styles.sumValue} numberOfLines={1}>{value}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  hoursRow: { flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 6, borderBottomWidth: 1, borderBottomColor: pa.lineSoft },
  hoursDay: { fontFamily: paFonts.bold, fontSize: 13, color: pa.ink },
  contactLink: { fontFamily: paFonts.bold, fontSize: 13, color: pa.signal, marginTop: 3 },
  container: { flex: 1, backgroundColor: pa.paper },
  stepBar: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 10, paddingVertical: 12, backgroundColor: pa.surface, borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: pa.line },
  stepLine: { width: 24, height: 1, backgroundColor: pa.line },
  dot: { width: 22, height: 22, borderRadius: 11, borderWidth: 1, borderColor: pa.line, alignItems: 'center', justifyContent: 'center' },
  dotText: { fontFamily: paFonts.bold, fontSize: 12, color: pa.muted },
  stepLabel: { fontFamily: paFonts.bold, fontSize: 12, color: pa.muted },
  name: { fontFamily: paFonts.black, fontSize: 18, color: pa.ink },
  spec: { fontFamily: paFonts.regular, fontSize: 13, color: pa.muted, marginTop: 2 },
  meta: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted },
  infoRow: { flexDirection: 'row', alignItems: 'center', gap: 6, marginBottom: 14 },
  bio: { fontFamily: paFonts.regular, fontSize: 14, color: pa.inkSoft, lineHeight: 20, marginBottom: 12 },
  muted: { fontFamily: paFonts.regular, color: pa.muted, fontSize: 13 },
  label: { fontFamily: paFonts.bold, fontSize: 13, color: pa.ink, marginBottom: 8 },
  chipRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginBottom: 16 },
  modeRow: { flexDirection: 'row', gap: 10, marginBottom: 14 },
  mode: { flex: 1, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8, paddingVertical: 12, borderRadius: R.input, backgroundColor: pa.surface, borderWidth: 1, borderColor: pa.line },
  modeActive: { borderColor: pa.signal, backgroundColor: pa.signalWash },
  modeText: { fontFamily: paFonts.bold, fontSize: 14, color: pa.inkSoft },
  shuttleBanner: { flexDirection: 'row', alignItems: 'center', gap: 12, backgroundColor: pa.beaconWash, borderRadius: R.card, padding: 14, marginBottom: 16 },
  shuttleTitle: { fontFamily: paFonts.black, fontSize: 13, color: pa.ink },
  shuttleSub: { fontFamily: paFonts.regular, fontSize: 12, color: pa.inkSoft, marginTop: 2 },
  sumRow: { flexDirection: 'row', alignItems: 'center', gap: 10, paddingVertical: 10 },
  sumBorder: { borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: pa.line },
  sumLabel: { fontFamily: paFonts.regular, fontSize: 13, color: pa.muted, width: 64 },
  sumValue: { flex: 1, fontFamily: paFonts.bold, fontSize: 14, color: pa.ink, textAlign: 'right' },
});
