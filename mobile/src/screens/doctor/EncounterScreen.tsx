import React, { useLayoutEffect, useState } from 'react';
import { Alert, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useNavigation, useRoute } from '@react-navigation/native';
import { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { api, apiErrorMessage } from '../../api/client';
import { PaBadge, PaButton, PaInput, PaSectionLabel } from '../../components/pa';
import { PaChipButton, PaChipRow, PaStatCell, PaStatGrid } from '../../components/pa/doctor-extras';
import { pa, paFonts, type } from '../../theme';
import { DoctorStackParamList } from '../../navigation/DoctorTabs';

type Nav = NativeStackNavigationProp<DoctorStackParamList>;
type Route = { params: DoctorStackParamList['DoctorEncounter'] };

/**
 * In-progress consultation view — mirrors new-ui/promptaid-mobile/doctor-encounter.html.
 *
 * The mockup shows editable notes/vitals + "Sign & prescribe". There is
 * currently NO backend endpoint to create or update an Encounter/Prescription
 * (see backend/routes/api.php — the `role:doctor` group only has
 * stats/appointments/patients/lab-requests; no /doctor/encounters route, and
 * DoctorController::appointments() does not even eager-load the `encounter`
 * relation, though Appointment/Encounter/Prescription models, resources and
 * the relation all already exist). So notes/vitals/diagnosis below are
 * READ-ONLY, sourced from `appointment.encounter` when the backend happens to
 * provide it, and the "Sign & prescribe" action is disabled with an
 * explanation rather than silently doing nothing.
 */
export default function DoctorEncounterScreen() {
  const navigation = useNavigation<Nav>();
  const { appointment } = useRoute<any>().params as Route['params'];
  const patient = appointment.patient;
  const encounter = appointment.encounter;
  const vitals = encounter?.vitals;

  useLayoutEffect(() => {
    navigation.setOptions({ title: patient?.name ?? 'Encounter' });
  }, [navigation, patient?.name]);

  const allergyChips = (patient?.allergies ?? '')
    .split(',')
    .map((s) => s.trim())
    .filter(Boolean);
  const conditionChips = (patient?.chronic_conditions ?? '')
    .split(',')
    .map((s) => s.trim())
    .filter(Boolean);

  function signAndPrescribe() {
    // TODO: no backend endpoint yet for creating an Encounter/Prescription —
    // see file header. Wire this up once POST /doctor/encounters exists.
    Alert.alert(
      'Not available yet',
      'Recording clinical notes, diagnosis and prescriptions from the app needs a backend endpoint that does not exist yet. Ask the patient to see the clinic system for now.'
    );
  }

  function bookReturnShuttle() {
    // TODO: no backend endpoint yet for a doctor to book a ride on a
    // patient's behalf — the `driver`-role /rides routes only let a driver
    // accept/update rides, and ride creation is patient-initiated
    // (POST /rides under the patient role group).
    Alert.alert('Not available yet', 'Booking a return shuttle for a patient is not supported from the doctor app yet.');
  }

  return (
    <ScrollView style={styles.container} contentContainerStyle={{ padding: 16, paddingBottom: 48 }}>
      <PaChipRow style={{ marginBottom: 14 }}>
        <PaBadge status={appointment.status} />
        {allergyChips.map((c) => (
          <PaBadge key={c} tone="stop" label={`${c} allergy`} />
        ))}
        {conditionChips.map((c) => (
          <PaBadge key={c} tone="wait" label={c} />
        ))}
      </PaChipRow>

      <PaStatGrid>
        <PaStatCell label="BP" value={vitals?.bp ?? '—'} size={18} />
        <PaStatCell label="Pulse" value={vitals?.pulse ?? '—'} size={18} />
        <PaStatCell label="Temp" value={vitals?.temp ?? '—'} size={18} />
        <PaStatCell label="SpO₂" value={vitals?.spo2 ?? '—'} size={18} />
      </PaStatGrid>

      <PaSectionLabel style={{ marginBottom: 8 }}>Notes</PaSectionLabel>
      {encounter?.notes ? (
        <Text style={styles.notes}>{encounter.notes}</Text>
      ) : (
        <View style={styles.notesEmpty}>
          <Text style={styles.notesEmptyText}>
            No clinical notes recorded for this visit yet. Editing notes from the app needs a backend endpoint that
            does not exist yet.
          </Text>
        </View>
      )}

      {encounter?.diagnosis ? (
        <>
          <PaSectionLabel style={{ marginTop: 14, marginBottom: 8 }}>Diagnosis</PaSectionLabel>
          <PaChipRow>
            <PaBadge tone="ink" label={encounter.diagnosis} />
          </PaChipRow>
        </>
      ) : null}

      <PaSectionLabel style={{ marginTop: 20, marginBottom: 8 }}>Appointment</PaSectionLabel>
      <View style={styles.metaRow}>
        <Text style={styles.metaLabel}>Reason</Text>
        <Text style={styles.metaValue}>{appointment.reason ?? '—'}</Text>
      </View>
      <View style={styles.metaRow}>
        <Text style={styles.metaLabel}>Time</Text>
        <Text style={styles.metaValue}>{appointment.start_time?.slice(0, 5)} – {appointment.end_time?.slice(0, 5)}</Text>
      </View>
      <View style={styles.metaRow}>
        <Text style={styles.metaLabel}>Clinic</Text>
        <Text style={styles.metaValue}>{appointment.clinic?.name ?? '—'}</Text>
      </View>

      <LabRequestForm appointment={appointment} />

      <View style={{ gap: 8, marginTop: 20 }}>
        <PaButton title="Sign & prescribe" onPress={signAndPrescribe} />
        <PaButton title="Book return shuttle" variant="ghost" onPress={bookReturnShuttle} />
      </View>
    </ScrollView>
  );
}

/** Real "Order labs" action — POSTs to /doctor/lab-requests (see backend/app/Http/Controllers/Api/LabRequestController.php::store). */
function LabRequestForm({ appointment }: { appointment: any }) {
  const [open, setOpen] = useState(false);
  const [testName, setTestName] = useState('');
  const [notes, setNotes] = useState('');
  const [priority, setPriority] = useState<'routine' | 'urgent'>('routine');
  const [submitting, setSubmitting] = useState(false);

  async function submit() {
    if (!testName.trim()) {
      Alert.alert('Add a test', 'Enter at least one test name.');
      return;
    }
    setSubmitting(true);
    try {
      await api.post('/doctor/lab-requests', {
        patient_profile_id: appointment.patient?.id,
        appointment_id: appointment.id,
        priority,
        clinical_notes: notes || undefined,
        items: [{ test_name: testName.trim() }],
      });
      Alert.alert('Lab request sent', `${testName.trim()} requested for ${appointment.patient?.name ?? 'the patient'}.`);
      setTestName('');
      setNotes('');
      setPriority('routine');
      setOpen(false);
    } catch (e) {
      Alert.alert('Could not send lab request', apiErrorMessage(e));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <View style={{ marginTop: 20 }}>
      <PaSectionLabel style={{ marginBottom: 8 }}>Order labs</PaSectionLabel>
      {!open ? (
        <PaButton title="Order labs" variant="ghost" onPress={() => setOpen(true)} />
      ) : (
        <View style={styles.labForm}>
          <PaInput label="Test name" placeholder="e.g. Full blood count" value={testName} onChangeText={setTestName} />
          <PaInput label="Notes (optional)" placeholder="Clinical notes for the lab" value={notes} onChangeText={setNotes} multiline />
          <Text style={[type.label, { marginBottom: 8 }]}>Priority</Text>
          <View style={{ flexDirection: 'row', gap: 8, marginBottom: 14 }}>
            <PaChipButton title="Routine" tone={priority === 'routine' ? 'go' : 'ink'} onPress={() => setPriority('routine')} />
            <PaChipButton title="Urgent" tone={priority === 'urgent' ? 'stop' : 'ink'} onPress={() => setPriority('urgent')} />
          </View>
          <PaButton title="Send request" loading={submitting} onPress={submit} />
        </View>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper },
  notes: { fontFamily: paFonts.regular, fontSize: 14, color: pa.ink, lineHeight: 20, backgroundColor: pa.surface, borderWidth: 1, borderColor: pa.line, padding: 14 },
  notesEmpty: { backgroundColor: pa.surface, borderWidth: 1, borderColor: pa.line, padding: 14 },
  notesEmptyText: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted, lineHeight: 18 },
  metaRow: { flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 8, borderBottomWidth: 1, borderBottomColor: pa.lineSoft },
  metaLabel: { fontFamily: paFonts.regular, fontSize: 13, color: pa.muted },
  metaValue: { fontFamily: paFonts.bold, fontSize: 13, color: pa.ink },
  labForm: { backgroundColor: pa.surface, borderWidth: 1, borderColor: pa.line, padding: 14 },
});
