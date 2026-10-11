import React, { useCallback, useEffect, useState } from 'react';
import { Alert, Pressable, RefreshControl, ScrollView, Switch, Text, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { Ionicons } from '@expo/vector-icons';
import { api, apiErrorMessage } from '../../api/client';
import { DoctorSessionItem } from '../../api/types';
import { Chip, EmptyNote, PrimaryButton, R, SoftCard } from '../../components/settings-ui';
import { pa, paFonts } from '../../theme';
import { WEEKDAYS_LONG } from '../../services/dates';

interface ClinicLite { id: number; name: string }
interface Draft { key: string; weekday: number; start_time: string; end_time: string; slot_minutes: number; is_active: boolean }

const HM = /^([01]\d|2[0-3]):[0-5]\d$/;
const ORDER = [1, 2, 3, 4, 5, 6, 0]; // Monday first
const SLOT_OPTIONS = [10, 15, 20, 30, 45, 60];

let seq = 0;
const nextKey = () => `s${++seq}`;

/**
 * Weekly schedule editor — exactly the rows the booking slot generator
 * reads (doctor_availabilities). Several sessions per day are allowed
 * (e.g. 08:00–12:00 and 14:00–17:00). Saved per clinic in one request.
 */
export default function DoctorSessionsScreen() {
  const insets = useSafeAreaInsets();
  const [clinics, setClinics] = useState<ClinicLite[]>([]);
  const [all, setAll] = useState<DoctorSessionItem[]>([]);
  const [clinicId, setClinicId] = useState<number | null>(null);
  const [drafts, setDrafts] = useState<Draft[]>([]);
  const [dirty, setDirty] = useState(false);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const { data } = await api.get('/doctor/sessions');
      setAll(data.data);
      setClinics(data.clinics);
      setClinicId((cur) => cur ?? data.clinics[0]?.id ?? null);
    } catch (e) {
      Alert.alert('Could not load sessions', apiErrorMessage(e));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { load(); }, [load]);

  useEffect(() => {
    setDrafts(
      all
        .filter((s) => s.clinic_id === clinicId)
        .map((s) => ({ key: nextKey(), weekday: s.weekday, start_time: s.start_time, end_time: s.end_time, slot_minutes: s.slot_minutes, is_active: s.is_active }))
    );
    setDirty(false);
  }, [all, clinicId]);

  function update(key: string, patch: Partial<Draft>) {
    setDrafts((d) => d.map((x) => (x.key === key ? { ...x, ...patch } : x)));
    setDirty(true);
  }

  function addSession(weekday: number) {
    const same = drafts.filter((d) => d.weekday === weekday).sort((a, b) => a.end_time.localeCompare(b.end_time));
    const last = same[same.length - 1];
    const start = last ? addHours(last.end_time, 1) : '08:00';
    setDrafts((d) => [...d, { key: nextKey(), weekday, start_time: start, end_time: addHours(start, 4), slot_minutes: last?.slot_minutes ?? 30, is_active: true }]);
    setDirty(true);
  }

  function copyToWeekdays(weekday: number) {
    const source = drafts.filter((d) => d.weekday === weekday);
    const targets = [1, 2, 3, 4, 5].filter((w) => w !== weekday);
    setDrafts((d) => [
      ...d.filter((x) => !targets.includes(x.weekday)),
      ...targets.flatMap((w) => source.map((s) => ({ ...s, key: nextKey(), weekday: w }))),
    ]);
    setDirty(true);
  }

  async function save() {
    if (!clinicId) return;
    for (const d of drafts) {
      if (!HM.test(d.start_time) || !HM.test(d.end_time)) {
        Alert.alert('Check the times', `${WEEKDAYS_LONG[d.weekday]}: use 24-hour times like 08:00.`);
        return;
      }
      if (d.end_time <= d.start_time) {
        Alert.alert('Check the times', `${WEEKDAYS_LONG[d.weekday]}: each session must end after it starts.`);
        return;
      }
    }
    setSaving(true);
    try {
      const { data } = await api.put('/doctor/sessions', {
        clinic_id: clinicId,
        sessions: drafts.map(({ weekday, start_time, end_time, slot_minutes, is_active }) => ({ weekday, start_time, end_time, slot_minutes, is_active })),
      });
      setAll(data.data);
      Alert.alert('Schedule saved', 'Patients now see booking slots from these sessions.');
    } catch (e) {
      Alert.alert('Could not save schedule', apiErrorMessage(e));
    } finally {
      setSaving(false);
    }
  }

  return (
    <View style={{ flex: 1, backgroundColor: pa.paper }}>
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: insets.bottom + 110 }} refreshControl={<RefreshControl refreshing={loading} onRefresh={load} />} keyboardShouldPersistTaps="handled">
        {clinics.length === 0 && !loading ? (
          <SoftCard><EmptyNote icon="business-outline" text="You are not linked to a clinic yet, so there is no schedule to edit." /></SoftCard>
        ) : (
          <>
            {clinics.length > 1 ? (
              <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginBottom: 14 }}>
                {clinics.map((c) => (
                  <Chip
                    key={c.id}
                    label={c.name}
                    active={c.id === clinicId}
                    onPress={() => {
                      if (dirty) {
                        Alert.alert('Unsaved changes', 'Save or discard changes before switching clinic.', [
                          { text: 'Keep editing', style: 'cancel' },
                          { text: 'Discard', style: 'destructive', onPress: () => setClinicId(c.id) },
                        ]);
                      } else setClinicId(c.id);
                    }}
                  />
                ))}
              </View>
            ) : null}

            {ORDER.map((weekday) => {
              const sessions = drafts.filter((d) => d.weekday === weekday).sort((a, b) => a.start_time.localeCompare(b.start_time));
              return (
                <SoftCard key={weekday} style={{ marginBottom: 10 }}>
                  <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' }}>
                    <Text style={{ fontFamily: paFonts.black, fontSize: 15, color: pa.ink }}>{WEEKDAYS_LONG[weekday]}</Text>
                    <View style={{ flexDirection: 'row', gap: 14, alignItems: 'center' }}>
                      {sessions.length && weekday >= 1 && weekday <= 5 ? (
                        <Pressable onPress={() => copyToWeekdays(weekday)} hitSlop={8}>
                          <Text style={{ fontFamily: paFonts.bold, fontSize: 12, color: pa.info }}>Copy to Mon–Fri</Text>
                        </Pressable>
                      ) : null}
                      <Pressable onPress={() => addSession(weekday)} hitSlop={8} accessibilityLabel={`Add session on ${WEEKDAYS_LONG[weekday]}`}>
                        <Ionicons name="add-circle-outline" size={24} color={pa.signal} />
                      </Pressable>
                    </View>
                  </View>
                  {sessions.length === 0 ? (
                    <Text style={{ fontFamily: paFonts.regular, fontSize: 12, color: pa.muted, marginTop: 6 }}>Not working</Text>
                  ) : (
                    sessions.map((sess, i) => (
                      <View key={sess.key} style={{ marginTop: 12, paddingTop: 12, borderTopWidth: 1, borderTopColor: pa.lineSoft }}>
                        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
                          <Text style={{ fontFamily: paFonts.bold, fontSize: 12, color: pa.muted, width: 62 }}>Session {i + 1}</Text>
                          <TimeInput value={sess.start_time} onChange={(v) => update(sess.key, { start_time: v })} />
                          <Text style={{ color: pa.muted }}>–</Text>
                          <TimeInput value={sess.end_time} onChange={(v) => update(sess.key, { end_time: v })} />
                          <View style={{ flex: 1 }} />
                          <Switch value={sess.is_active} onValueChange={(v) => update(sess.key, { is_active: v })} trackColor={{ true: pa.go, false: pa.line }} />
                          <Pressable
                            onPress={() => { setDrafts((d) => d.filter((x) => x.key !== sess.key)); setDirty(true); }}
                            hitSlop={8}
                            accessibilityLabel="Remove session"
                          >
                            <Ionicons name="trash-outline" size={19} color={pa.signal} />
                          </Pressable>
                        </View>
                        <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginTop: 10 }}>
                          {SLOT_OPTIONS.map((m) => (
                            <Chip key={m} label={`${m} min`} active={sess.slot_minutes === m} onPress={() => update(sess.key, { slot_minutes: m })} style={{ paddingHorizontal: 10, paddingVertical: 5 }} />
                          ))}
                        </View>
                      </View>
                    ))
                  )}
                </SoftCard>
              );
            })}
          </>
        )}
      </ScrollView>

      {clinics.length ? (
        <View style={{ position: 'absolute', left: 16, right: 16, bottom: insets.bottom + 16 }}>
          <PrimaryButton title={dirty ? 'Save schedule' : 'Saved'} onPress={save} loading={saving} disabled={!dirty} icon="checkmark-circle-outline" />
        </View>
      ) : null}
    </View>
  );
}

function TimeInput({ value, onChange }: { value: string; onChange: (v: string) => void }) {
  const valid = HM.test(value);
  return (
    <TextInput
      value={value}
      onChangeText={(t) => {
        const digits = t.replace(/[^0-9]/g, '').slice(0, 4);
        onChange(digits.length > 2 ? `${digits.slice(0, 2)}:${digits.slice(2)}` : digits);
      }}
      keyboardType="number-pad"
      maxLength={5}
      style={{
        width: 64,
        textAlign: 'center',
        borderWidth: 1,
        borderColor: valid ? pa.line : pa.signal,
        borderRadius: R.input,
        paddingVertical: 7,
        fontFamily: paFonts.bold,
        fontSize: 14,
        color: pa.ink,
      }}
    />
  );
}

function addHours(hm: string, hours: number): string {
  const [h, m] = hm.split(':').map(Number);
  const total = Math.min(23 * 60 + 59, h * 60 + m + hours * 60);
  return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
}
