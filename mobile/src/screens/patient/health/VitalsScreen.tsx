import React, { useCallback, useState } from 'react';
import { Alert, Pressable, RefreshControl, ScrollView, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { api, apiErrorMessage } from '../../../api/client';
import { FLAG_COLORS, VITAL_META, VitalMetric, VitalRange, VitalReading, VitalsPayload } from '../../../api/health';
import { PaEmptyState } from '../../../components/pa';
import { Fab, Field, PrimaryButton, Sheet } from '../../../components/settings-ui';
import { FeatherName, hs, IconCircle, Pill } from '../../../components/HealthUI';
import { pa, paFonts } from '../../../theme';

const METRICS: VitalMetric[] = ['systolic', 'diastolic', 'pulse', 'glucose', 'weight', 'temperature', 'spo2'];
type Form = Record<VitalMetric | 'note', string>;
const EMPTY: Form = { systolic: '', diastolic: '', pulse: '', glucose: '', weight: '', temperature: '', spo2: '', note: '' };

function flagOf(metric: VitalMetric, v: number, ranges: Record<string, VitalRange>): 'red' | 'orange' | null {
  const r = ranges[metric];
  if (!r) return null;
  if (v < r.critical_min || v > r.critical_max) return 'red';
  if (v < r.normal_min || v > r.normal_max) return 'orange';
  return null;
}

/** Simple bar sparkline made of Views; the normal band is shaded. */
function Bars({ values, metric, ranges, color }: { values: number[]; metric: VitalMetric; ranges: Record<string, VitalRange>; color: string }) {
  const r = ranges[metric];
  let lo = Math.min(...values);
  let hi = Math.max(...values);
  if (r) { lo = Math.min(lo, r.normal_min); hi = Math.max(hi, r.normal_max); }
  const pad = (hi - lo) * 0.15 || 1;
  lo -= pad; hi += pad;
  const H = 44;
  const y = (v: number) => ((v - lo) / (hi - lo)) * H;
  return (
    <View style={{ height: H, flexDirection: 'row', alignItems: 'flex-end', gap: 3, marginTop: 8 }}>
      {r ? <View style={{ position: 'absolute', left: 0, right: 0, bottom: y(r.normal_min), height: Math.max(1, y(r.normal_max) - y(r.normal_min)), backgroundColor: '#E7F3EC' }} /> : null}
      {values.map((v, i) => {
        const f = flagOf(metric, v, ranges);
        return <View key={i} style={{ flex: 1, maxWidth: 14, height: Math.max(3, y(v)), borderRadius: 3, backgroundColor: f ? FLAG_COLORS[f].fg : color, opacity: i === values.length - 1 ? 1 : 0.55 }} />;
      })}
    </View>
  );
}

/** Vitals tracker: log readings, trend tiles, flagged history. */
export default function VitalsScreen() {
  const [payload, setPayload] = useState<VitalsPayload | null>(null);
  const [loading, setLoading] = useState(false);
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState<Form>(EMPTY);
  const [saving, setSaving] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const { data } = await api.get<VitalsPayload>('/vitals');
      setPayload(data);
    } catch (e) {
      Alert.alert('Could not load', apiErrorMessage(e));
    } finally {
      setLoading(false);
    }
  }, []);

  useFocusEffect(useCallback(() => { load(); }, [load]));

  async function save() {
    const body: Record<string, any> = { note: form.note.trim() || undefined };
    METRICS.forEach((m) => { if (form[m].trim()) body[m] = Number(form[m].replace(',', '.')); });
    if (!METRICS.some((m) => body[m] !== undefined)) return Alert.alert('Nothing to save', 'Enter at least one reading.');
    setSaving(true);
    try {
      const { data } = await api.post('/vitals', body);
      setOpen(false);
      setForm(EMPTY);
      await load();
      if (data.flag === 'red') Alert.alert('Reading far outside the normal range', 'Please contact your doctor, or call for help if you feel unwell.');
      else if (data.flag === 'orange') Alert.alert('Saved', 'Some readings are outside the normal range.');
    } catch (e) {
      Alert.alert('Could not save', apiErrorMessage(e));
    } finally {
      setSaving(false);
    }
  }

  function remove(r: VitalReading) {
    Alert.alert('Delete this reading?', new Date(r.recorded_at).toLocaleString('en-ZA'), [
      { text: 'Cancel', style: 'cancel' },
      { text: 'Delete', style: 'destructive', onPress: async () => { try { await api.delete(`/vitals/${r.id}`); await load(); } catch (e) { Alert.alert('Could not delete', apiErrorMessage(e)); } } },
    ]);
  }

  const ranges = payload?.ranges ?? {};
  const series = payload?.series ?? {};
  const readings = payload?.data ?? [];
  const set = (k: keyof Form) => (v: string) => setForm((f) => ({ ...f, [k]: v }));

  function chip(r: VitalReading, m: VitalMetric, text: string) {
    const f = r.flags?.[m];
    const c = f ? FLAG_COLORS[f] : { fg: pa.inkSoft, bg: pa.paper };
    return <Pill key={m} label={text} fg={c.fg} bg={c.bg} />;
  }

  return (
    <View style={hs.screen}>
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 110 }} refreshControl={<RefreshControl refreshing={loading} onRefresh={load} />}>
        {payload?.note ? <Text style={[hs.note, { marginBottom: 12 }]}>{payload.note}</Text> : null}

        <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 10 }}>
          {METRICS.filter((m) => series[m]?.length).map((m) => {
            const vals = series[m]!;
            const last = vals[vals.length - 1];
            const meta = VITAL_META[m];
            const f = flagOf(m, last, ranges);
            return (
              <View key={m} style={[hs.card, { width: '48%', flexGrow: 1, marginBottom: 0 }]}>
                <View style={hs.row}>
                  <IconCircle icon={meta.icon as FeatherName} color={meta.color} size={30} />
                  <Text style={[hs.sub, { marginTop: 0, flex: 1 }]} numberOfLines={1}>{meta.label}</Text>
                </View>
                <Text style={{ fontFamily: paFonts.black, fontSize: 22, color: f ? FLAG_COLORS[f].fg : pa.ink, marginTop: 6 }}>
                  {last}<Text style={{ fontFamily: paFonts.regular, fontSize: 12, color: pa.muted }}> {meta.unit}</Text>
                </Text>
                <Bars values={vals} metric={m} ranges={ranges} color={meta.color} />
              </View>
            );
          })}
        </View>

        <Text style={hs.label}>History</Text>
        {readings.length === 0 && !loading ? <PaEmptyState message="No readings yet. Tap + to log your first one." /> : null}
        {readings.map((r) => (
          <Pressable key={r.id} onLongPress={() => remove(r)} style={hs.card}>
            <View style={[hs.row, { justifyContent: 'space-between' }]}>
              <Text style={hs.title}>{new Date(r.recorded_at).toLocaleString('en-ZA', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })}</Text>
              <Text style={hs.sub} onPress={() => remove(r)}>Delete</Text>
            </View>
            <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginTop: 8 }}>
              {r.systolic !== null ? (() => {
                const f = r.flags?.systolic === 'red' || r.flags?.diastolic === 'red' ? 'red' : r.flags?.systolic || r.flags?.diastolic ? 'orange' : null;
                const c = f ? FLAG_COLORS[f] : { fg: pa.inkSoft, bg: pa.paper };
                return <Pill label={`BP ${r.systolic}/${r.diastolic}`} fg={c.fg} bg={c.bg} />;
              })() : null}
              {r.pulse !== null ? chip(r, 'pulse', `Pulse ${r.pulse}`) : null}
              {r.glucose !== null ? chip(r, 'glucose', `Glucose ${r.glucose}`) : null}
              {r.weight !== null ? chip(r, 'weight', `${r.weight} kg`) : null}
              {r.temperature !== null ? chip(r, 'temperature', `${r.temperature}°C`) : null}
              {r.spo2 !== null ? chip(r, 'spo2', `SpO₂ ${r.spo2}%`) : null}
            </View>
            {r.note ? <Text style={[hs.sub, { marginTop: 8 }]}>{r.note}</Text> : null}
          </Pressable>
        ))}
      </ScrollView>

      <Fab label="Log a reading" onPress={() => setOpen(true)} />
      <Sheet visible={open} onClose={() => setOpen(false)} title="Log a reading" footer={<PrimaryButton title="Save reading" onPress={save} loading={saving} />}>
        <View style={{ flexDirection: 'row', gap: 10 }}>
          <View style={{ flex: 1 }}><Field label="Systolic (mmHg)" value={form.systolic} onChangeText={set('systolic')} keyboardType="number-pad" placeholder="120" /></View>
          <View style={{ flex: 1 }}><Field label="Diastolic (mmHg)" value={form.diastolic} onChangeText={set('diastolic')} keyboardType="number-pad" placeholder="80" /></View>
        </View>
        <View style={{ flexDirection: 'row', gap: 10 }}>
          <View style={{ flex: 1 }}><Field label="Pulse (bpm)" value={form.pulse} onChangeText={set('pulse')} keyboardType="number-pad" placeholder="72" /></View>
          <View style={{ flex: 1 }}><Field label="Glucose (mmol/L)" value={form.glucose} onChangeText={set('glucose')} keyboardType="decimal-pad" placeholder="5.4" /></View>
        </View>
        <View style={{ flexDirection: 'row', gap: 10 }}>
          <View style={{ flex: 1 }}><Field label="Weight (kg)" value={form.weight} onChangeText={set('weight')} keyboardType="decimal-pad" /></View>
          <View style={{ flex: 1 }}><Field label="Temp (°C)" value={form.temperature} onChangeText={set('temperature')} keyboardType="decimal-pad" placeholder="36.8" /></View>
        </View>
        <Field label="Oxygen SpO₂ (%)" value={form.spo2} onChangeText={set('spo2')} keyboardType="number-pad" placeholder="98" />
        <Field label="Note" value={form.note} onChangeText={set('note')} placeholder="e.g. After exercise" />
      </Sheet>
    </View>
  );
}
