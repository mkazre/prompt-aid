import React, { useEffect, useLayoutEffect, useState } from 'react';
import { ActivityIndicator, Alert, KeyboardAvoidingView, Platform, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { useNavigation, useRoute } from '@react-navigation/native';
import { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { Ionicons } from '@expo/vector-icons';
import { api, apiErrorMessage } from '../../api/client';
import { Appointment } from '../../api/types';
import { PaBadge, PaButton, PaInput, PaSectionLabel } from '../../components/pa';
import { PaChipButton, PaChipRow } from '../../components/pa/doctor-extras';
import { DateField, DatePickerSheet } from '../../components/DatePickerSheet';
import { Field, PrimaryButton, R, SoftCard } from '../../components/settings-ui';
import { pa, paFonts, type } from '../../theme';
import { DoctorStackParamList } from '../../navigation/DoctorTabs';
import { time12 } from '../../services/dates';

type Nav = NativeStackNavigationProp<DoctorStackParamList>;
type Route = { params: DoctorStackParamList['DoctorEncounter'] };

interface RxDraft { key: string; medicine_name: string; dosage: string; frequency: string; duration_days: string; instructions: string }

let rxSeq = 0;
const newRx = (p: Partial<RxDraft> = {}): RxDraft => ({ key: `rx${++rxSeq}`, medicine_name: '', dosage: '', frequency: '', duration_days: '', instructions: '', ...p });

/**
 * Consultation screen: vitals, complaint, diagnosis, notes, follow-up and
 * prescription items — saved to GET/POST /doctor/appointments/{id}/encounter
 * (scoped to the signed-in doctor). "Sign & complete" also marks the
 * appointment completed. Lab orders use /doctor/lab-requests.
 */
export default function DoctorEncounterScreen() {
  const navigation = useNavigation<Nav>();
  const { appointment: initial } = useRoute<any>().params as Route['params'];
  const [appointment, setAppointment] = useState<Appointment>(initial);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState<'save' | 'complete' | null>(null);
  const [vitals, setVitals] = useState({ bp: '', pulse: '', temp: '', spo2: '' });
  const [complaint, setComplaint] = useState('');
  const [diagnosis, setDiagnosis] = useState('');
  const [notes, setNotes] = useState('');
  const [followUp, setFollowUp] = useState<string | null>(null);
  const [rxNotes, setRxNotes] = useState('');
  const [items, setItems] = useState<RxDraft[]>([]);
  const [picker, setPicker] = useState(false);
  const patient = appointment.patient;

  useLayoutEffect(() => {
    navigation.setOptions({ title: patient?.name ?? 'Consult' });
  }, [navigation, patient?.name]);

  function hydrate(a: Appointment) {
    setAppointment(a);
    const e = a.encounter;
    setVitals({ bp: e?.vitals?.bp ?? '', pulse: e?.vitals?.pulse ?? '', temp: e?.vitals?.temp ?? '', spo2: e?.vitals?.spo2 ?? '' });
    setComplaint(e?.chief_complaint ?? a.reason ?? '');
    setDiagnosis(e?.diagnosis ?? '');
    setNotes(e?.notes ?? '');
    setFollowUp(e?.follow_up_date ?? null);
    setRxNotes(e?.prescription?.notes ?? '');
    setItems(
      (e?.prescription?.items ?? []).map((i) =>
        newRx({ medicine_name: i.medicine_name, dosage: i.dosage ?? '', frequency: i.frequency ?? '', duration_days: i.duration_days ? String(i.duration_days) : '', instructions: i.instructions ?? '' })
      )
    );
  }

  useEffect(() => {
    api
      .get(`/doctor/appointments/${initial.id}/encounter`)
      .then(({ data }) => hydrate(data.data))
      .catch((e) => Alert.alert('Could not load consult', apiErrorMessage(e)))
      .finally(() => setLoading(false));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [initial.id]);

  async function save(complete: boolean) {
    const rx = items.filter((i) => i.medicine_name.trim());
    setSaving(complete ? 'complete' : 'save');
    try {
      const { data } = await api.post(`/doctor/appointments/${appointment.id}/encounter`, {
        vitals,
        chief_complaint: complaint.trim() || null,
        diagnosis: diagnosis.trim() || null,
        notes: notes.trim() || null,
        follow_up_date: followUp,
        prescription_notes: rxNotes.trim() || null,
        items: rx.map((i) => ({
          medicine_name: i.medicine_name.trim(),
          dosage: i.dosage.trim() || null,
          frequency: i.frequency.trim() || null,
          duration_days: i.duration_days ? Number(i.duration_days) : null,
          instructions: i.instructions.trim() || null,
        })),
        complete,
      });
      hydrate(data.data);
      Alert.alert(complete ? 'Consult signed' : 'Saved', complete ? 'Notes saved and the appointment is marked completed.' : 'Consultation notes saved.');
    } catch (e) {
      Alert.alert('Could not save', apiErrorMessage(e));
    } finally {
      setSaving(null);
    }
  }

  const allergyChips = (patient?.allergies ?? '').split(',').map((s) => s.trim()).filter(Boolean);
  const conditionChips = (patient?.chronic_conditions ?? '').split(',').map((s) => s.trim()).filter(Boolean);
  const editable = appointment.status !== 'cancelled';

  if (loading) {
    return (
      <View style={[styles.container, { alignItems: 'center', justifyContent: 'center' }]}>
        <ActivityIndicator color={pa.signal} />
      </View>
    );
  }

  return (
    <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <ScrollView style={styles.container} contentContainerStyle={{ padding: 16, paddingBottom: 48 }} keyboardShouldPersistTaps="handled">
        <PaChipRow style={{ marginBottom: 14 }}>
          <PaBadge status={appointment.status} />
          {allergyChips.map((c) => <PaBadge key={c} tone="stop" label={`${c} allergy`} />)}
          {conditionChips.map((c) => <PaBadge key={c} tone="wait" label={c} />)}
        </PaChipRow>

        <SoftCard style={{ marginBottom: 14 }}>
          <Text style={styles.meta}>
            {time12(appointment.start_time)} – {time12(appointment.end_time)} · {appointment.clinic?.name ?? (appointment.mode === 'video' ? 'Video' : 'Clinic')}
          </Text>
          {appointment.reason ? <Text style={[styles.meta, { marginTop: 4 }]}>Patient note: “{appointment.reason}”</Text> : null}
        </SoftCard>

        <Text style={styles.h}>Vitals</Text>
        <View style={styles.vitals}>
          {(['bp', 'pulse', 'temp', 'spo2'] as const).map((k) => (
            <View key={k} style={styles.vitalCell}>
              <Text style={styles.vitalLabel}>{{ bp: 'BP', pulse: 'Pulse', temp: 'Temp', spo2: 'SpO₂' }[k]}</Text>
              <TextInput
                editable={editable}
                value={vitals[k]}
                onChangeText={(t) => setVitals((v) => ({ ...v, [k]: t }))}
                placeholder={{ bp: '120/80', pulse: '72', temp: '36.8', spo2: '98' }[k]}
                placeholderTextColor={pa.muted2}
                keyboardType={k === 'bp' ? 'numbers-and-punctuation' : 'decimal-pad'}
                style={styles.vitalInput}
              />
            </View>
          ))}
        </View>

        <Field label="Chief complaint" value={complaint} onChangeText={setComplaint} editable={editable} multiline />
        <Field label="Diagnosis" value={diagnosis} onChangeText={setDiagnosis} editable={editable} placeholder="e.g. Acute tonsillitis (J03.9)" />
        <Field label="Clinical notes" value={notes} onChangeText={setNotes} editable={editable} multiline style={{ minHeight: 110, textAlignVertical: 'top' }} />
        <DateField label="Follow-up (optional)" value={followUp} placeholder="No follow-up" onPress={() => editable && setPicker(true)} />
        {followUp ? (
          <Pressable onPress={() => setFollowUp(null)} style={{ marginTop: -8, marginBottom: 12 }}>
            <Text style={{ fontFamily: paFonts.bold, fontSize: 12, color: pa.signal }}>Clear follow-up</Text>
          </Pressable>
        ) : null}

        <View style={styles.rxHead}>
          <Text style={styles.h}>Prescription</Text>
          {editable ? (
            <Pressable onPress={() => setItems((l) => [...l, newRx()])} hitSlop={8} style={{ flexDirection: 'row', alignItems: 'center', gap: 4 }}>
              <Ionicons name="add-circle-outline" size={20} color={pa.signal} />
              <Text style={{ fontFamily: paFonts.bold, color: pa.signal, fontSize: 13 }}>Add medicine</Text>
            </Pressable>
          ) : null}
        </View>
        {items.length === 0 ? <Text style={[styles.meta, { marginBottom: 12 }]}>No medicines prescribed.</Text> : null}
        {items.map((it) => (
          <SoftCard key={it.key} style={{ marginBottom: 10 }}>
            <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
              <TextInput
                value={it.medicine_name}
                onChangeText={(t) => setItems((l) => l.map((x) => (x.key === it.key ? { ...x, medicine_name: t } : x)))}
                placeholder="Medicine name"
                placeholderTextColor={pa.muted2}
                editable={editable}
                style={[styles.rxInput, { flex: 1, fontFamily: paFonts.bold }]}
              />
              {editable ? (
                <Pressable onPress={() => setItems((l) => l.filter((x) => x.key !== it.key))} hitSlop={8} accessibilityLabel="Remove medicine">
                  <Ionicons name="trash-outline" size={19} color={pa.signal} />
                </Pressable>
              ) : null}
            </View>
            <View style={{ flexDirection: 'row', gap: 8, marginTop: 8 }}>
              {(['dosage', 'frequency', 'duration_days'] as const).map((f) => (
                <TextInput
                  key={f}
                  value={it[f]}
                  onChangeText={(t) => setItems((l) => l.map((x) => (x.key === it.key ? { ...x, [f]: f === 'duration_days' ? t.replace(/[^0-9]/g, '') : t } : x)))}
                  placeholder={{ dosage: '500mg', frequency: '3x daily', duration_days: 'Days' }[f]}
                  placeholderTextColor={pa.muted2}
                  keyboardType={f === 'duration_days' ? 'number-pad' : 'default'}
                  editable={editable}
                  style={[styles.rxInput, { flex: 1 }]}
                />
              ))}
            </View>
            <TextInput
              value={it.instructions}
              onChangeText={(t) => setItems((l) => l.map((x) => (x.key === it.key ? { ...x, instructions: t } : x)))}
              placeholder="Instructions (e.g. after meals)"
              placeholderTextColor={pa.muted2}
              editable={editable}
              style={[styles.rxInput, { marginTop: 8 }]}
            />
          </SoftCard>
        ))}
        {items.length ? <Field label="Prescription notes (optional)" value={rxNotes} onChangeText={setRxNotes} editable={editable} /> : null}

        {editable ? (
          <View style={{ flexDirection: 'row', gap: 10, marginTop: 6 }}>
            <PrimaryButton title="Save" tone="ghost" onPress={() => save(false)} loading={saving === 'save'} disabled={saving !== null} style={{ flex: 1 }} />
            <PrimaryButton title={appointment.status === 'completed' ? 'Update & sign' : 'Sign & complete'} onPress={() => save(true)} loading={saving === 'complete'} disabled={saving !== null} style={{ flex: 2 }} />
          </View>
        ) : null}

        <LabRequestForm appointment={appointment} />

        <DatePickerSheet visible={picker} value={followUp} onClose={() => setPicker(false)} onPick={setFollowUp} title="Follow-up date" />
      </ScrollView>
    </KeyboardAvoidingView>
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
  h: { fontFamily: paFonts.black, fontSize: 15, color: pa.ink, marginBottom: 10 },
  meta: { fontFamily: paFonts.regular, fontSize: 13, color: pa.muted },
  vitals: { flexDirection: 'row', gap: 8, marginBottom: 16 },
  vitalCell: { flex: 1, backgroundColor: pa.surface, borderRadius: R.input, padding: 10, borderWidth: 1, borderColor: pa.line },
  vitalLabel: { fontFamily: paFonts.bold, fontSize: 11, color: pa.muted },
  vitalInput: { fontFamily: paFonts.black, fontSize: 16, color: pa.ink, paddingVertical: 4 },
  rxHead: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginTop: 6 },
  rxInput: { borderWidth: 1, borderColor: pa.line, borderRadius: R.input, paddingHorizontal: 10, paddingVertical: 8, fontFamily: paFonts.regular, fontSize: 14, color: pa.ink },
  labForm: { backgroundColor: pa.surface, borderWidth: 1, borderColor: pa.line, padding: 14 },
});
