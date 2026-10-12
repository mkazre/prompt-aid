import React, { useCallback, useState } from 'react';
import { Alert, Pressable, RefreshControl, ScrollView, Switch, Text, View } from 'react-native';
import { Feather } from '@expo/vector-icons';
import { useFocusEffect } from '@react-navigation/native';
import { api, apiErrorMessage } from '../../../api/client';
import { DoseStatus, Medication, MedicationsPayload } from '../../../api/health';
import { PaEmptyState } from '../../../components/pa';
import { Fab, Field, PrimaryButton, Sheet } from '../../../components/settings-ui';
import { ActionChip, hs, IconCircle, Pill } from '../../../components/HealthUI';
import { pa, paFonts } from '../../../theme';

const STATUS: Record<DoseStatus, { label: string; fg: string; bg: string }> = {
  taken: { label: 'Taken', fg: '#1F7A4C', bg: '#E7F3EC' },
  due: { label: 'Due now', fg: '#B4520F', bg: '#FCEEE2' },
  missed: { label: 'Missed', fg: '#C8102E', bg: '#FBEAEA' },
  upcoming: { label: 'Upcoming', fg: '#1F5FBF', bg: '#E6EEFA' },
};
const PALETTE = ['#D0211C', '#1F7A4C', '#1F4E7A', '#6A4C93', '#B4520F', '#0E7C86'];

type Form = { name: string; dose: string; times: string; start_date: string; end_date: string; notes: string; reminders_enabled: boolean };

/** Medications with today's doses (taken / due / missed) and reminders. */
export default function MedicationsScreen() {
  const [payload, setPayload] = useState<MedicationsPayload | null>(null);
  const [loading, setLoading] = useState(false);
  const [busy, setBusy] = useState<string | null>(null);
  const [open, setOpen] = useState(false);
  const [editing, setEditing] = useState<Medication | null>(null);
  const [form, setForm] = useState<Form | null>(null);
  const [saving, setSaving] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const { data } = await api.get<MedicationsPayload>('/medications');
      setPayload(data);
    } catch (e) {
      Alert.alert('Could not load', apiErrorMessage(e));
    } finally {
      setLoading(false);
    }
  }, []);

  useFocusEffect(useCallback(() => { load(); }, [load]));

  async function toggleTaken(medicationId: number, time: string, taken: boolean) {
    setBusy(`${medicationId}-${time}`);
    try {
      await api.post(`/medications/${medicationId}/taken`, { time, taken });
      await load();
    } catch (e) {
      Alert.alert('Could not update', apiErrorMessage(e));
    } finally {
      setBusy(null);
    }
  }

  function openForm(m?: Medication) {
    setEditing(m ?? null);
    setForm({
      name: m?.name ?? '',
      dose: m?.dose ?? '',
      times: m ? m.times.join(', ') : '08:00, 20:00',
      start_date: m?.start_date ?? payload?.local_date ?? new Date().toISOString().slice(0, 10),
      end_date: m?.end_date ?? '',
      notes: m?.notes ?? '',
      reminders_enabled: m ? m.reminders_enabled : true,
    });
    setOpen(true);
  }

  async function save() {
    if (!form) return;
    if (!form.name.trim() || !form.dose.trim()) return Alert.alert('Missing details', 'Enter the medication name and dose.');
    setSaving(true);
    const body = { ...form, times: form.times.split(/[\s,;]+/).filter(Boolean), end_date: form.end_date || null };
    try {
      if (editing) await api.put(`/medications/${editing.id}`, body);
      else await api.post('/medications', body);
      setOpen(false);
      await load();
    } catch (e) {
      Alert.alert('Could not save', apiErrorMessage(e));
    } finally {
      setSaving(false);
    }
  }

  function remove(m: Medication) {
    Alert.alert('Remove medication?', `${m.name} ${m.dose}`, [
      { text: 'Cancel', style: 'cancel' },
      {
        text: 'Remove', style: 'destructive', onPress: async () => {
          try {
            await api.delete(`/medications/${m.id}`);
            await load();
          } catch (e) {
            Alert.alert('Could not remove', apiErrorMessage(e));
          }
        },
      },
    ]);
  }

  const meds = payload?.data ?? [];
  const today = payload?.today ?? [];
  const takenCount = today.filter((d) => d.status === 'taken').length;
  const set = (k: keyof Form) => (v: any) => setForm((f) => (f ? { ...f, [k]: v } : f));

  return (
    <View style={hs.screen}>
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 110 }} refreshControl={<RefreshControl refreshing={loading} onRefresh={load} />}>
        <View style={[hs.card, { flexDirection: 'row', alignItems: 'center', gap: 12 }]}>
          <IconCircle icon="clock" color="#1F7A4C" size={46} />
          <View style={{ flex: 1 }}>
            <Text style={hs.title}>Today · {takenCount}/{today.length} doses taken</Text>
            <Text style={hs.sub}>{payload?.reminders_enabled ? 'We’ll send a reminder at each dose time.' : 'Reminders are currently switched off.'}</Text>
          </View>
        </View>

        <Text style={hs.label}>Today’s doses</Text>
        {today.length === 0 && !loading ? <Text style={hs.note}>No doses scheduled today.</Text> : null}
        {today.map((d) => {
          const st = STATUS[d.status];
          const key = `${d.medication_id}-${d.time}`;
          const taken = d.status === 'taken';
          return (
            <View key={key} style={[hs.card, { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 12 }]}>
              <Text style={{ fontFamily: paFonts.black, fontSize: 18, color: pa.ink, width: 54 }}>{d.time}</Text>
              <View style={{ flex: 1 }}>
                <Text style={hs.title} numberOfLines={1}>{d.name}</Text>
                <Text style={hs.sub} numberOfLines={1}>{d.dose}{d.taken_at ? ` · taken ${d.taken_at}` : ''}</Text>
                <View style={{ marginTop: 6 }}><Pill label={st.label} fg={st.fg} bg={st.bg} /></View>
              </View>
              <Pressable
                onPress={() => toggleTaken(d.medication_id, d.time, !taken)}
                disabled={busy === key}
                style={({ pressed }) => [{ borderRadius: 999, paddingHorizontal: 14, paddingVertical: 9, backgroundColor: taken ? pa.surface : pa.go, borderWidth: 1, borderColor: taken ? pa.line : pa.go }, pressed && { opacity: 0.7 }]}
                accessibilityLabel={taken ? 'Undo taken' : 'Mark taken'}
              >
                <Text style={{ fontFamily: paFonts.bold, fontSize: 13, color: taken ? pa.ink : '#fff' }}>{busy === key ? '…' : taken ? 'Undo' : 'Taken'}</Text>
              </Pressable>
            </View>
          );
        })}

        <Text style={hs.label}>My medications</Text>
        {meds.length === 0 && !loading ? <PaEmptyState message="No medications yet. Tap + to add one." /> : null}
        {meds.map((m, i) => (
          <View key={m.id} style={[hs.card, !m.active && { opacity: 0.6 }]}>
            <View style={hs.row}>
              <IconCircle icon="package" color={PALETTE[i % PALETTE.length]} />
              <View style={{ flex: 1 }}>
                <Text style={hs.title}>{m.name}</Text>
                <Text style={hs.sub}>{m.dose} · {m.times.join(', ')}</Text>
                <Text style={hs.sub}>From {m.start_date}{m.end_date ? ` to ${m.end_date}` : ' · ongoing'}</Text>
                {m.notes ? <Text style={hs.sub}>{m.notes}</Text> : null}
              </View>
              <Feather name={m.reminders_enabled ? 'bell' : 'bell-off'} size={18} color={m.reminders_enabled ? pa.go : pa.muted2} />
            </View>
            <View style={hs.actions}>
              <ActionChip icon="edit-2" label="Edit" onPress={() => openForm(m)} />
              <ActionChip icon="trash-2" label="Remove" tone="danger" onPress={() => remove(m)} />
            </View>
          </View>
        ))}

        {payload?.taken_log?.length ? (
          <>
            <Text style={hs.label}>Taken log</Text>
            <View style={hs.card}>
              {payload.taken_log.slice(0, 20).map((l, i) => (
                <View key={i} style={{ flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 6 }}>
                  <Text style={[hs.sub, { color: pa.ink, marginTop: 0 }]} numberOfLines={1}>{l.medication}</Text>
                  <Text style={[hs.sub, { marginTop: 0 }]}>{l.date.slice(5)} · {l.time}</Text>
                </View>
              ))}
            </View>
          </>
        ) : null}
      </ScrollView>

      <Fab label="Add medication" onPress={() => openForm()} />
      <Sheet visible={open} onClose={() => setOpen(false)} title={editing ? 'Edit medication' : 'Add medication'} footer={<PrimaryButton title={editing ? 'Save changes' : 'Add medication'} onPress={save} loading={saving} />}>
        {form ? (
          <>
            <Field label="Medication" value={form.name} onChangeText={set('name')} placeholder="e.g. Metformin" />
            <Field label="Dose" value={form.dose} onChangeText={set('dose')} placeholder="e.g. 1 tablet (500 mg)" />
            <Field label="Times per day (HH:MM, comma separated)" value={form.times} onChangeText={set('times')} placeholder="08:00, 20:00" keyboardType="numbers-and-punctuation" />
            <Field label="Start date (YYYY-MM-DD)" value={form.start_date} onChangeText={set('start_date')} keyboardType="numbers-and-punctuation" />
            <Field label="End date (optional)" value={form.end_date} onChangeText={set('end_date')} placeholder="YYYY-MM-DD" keyboardType="numbers-and-punctuation" />
            <Field label="Notes" value={form.notes} onChangeText={set('notes')} placeholder="e.g. Take with food" />
            <View style={[hs.row, { justifyContent: 'space-between', marginBottom: 8 }]}>
              <Text style={hs.title}>Remind me at each time</Text>
              <Switch value={form.reminders_enabled} onValueChange={set('reminders_enabled')} trackColor={{ true: pa.go, false: pa.line }} />
            </View>
          </>
        ) : null}
      </Sheet>
    </View>
  );
}
