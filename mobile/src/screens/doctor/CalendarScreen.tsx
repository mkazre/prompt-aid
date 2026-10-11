import React, { useCallback, useMemo, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, RefreshControl, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useFocusEffect, useNavigation } from '@react-navigation/native';
import { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { Ionicons } from '@expo/vector-icons';
import { api, apiErrorMessage } from '../../api/client';
import { Appointment, DoctorPatient, ServiceItem, SlotSchedule } from '../../api/types';
import { PaAvatar, PaBadge } from '../../components/pa';
import SlotPicker from '../../components/SlotPicker';
import { Chip, EmptyNote, Fab, Field, PrimaryButton, R, Sheet, SoftCard, softShadow } from '../../components/settings-ui';
import { pa, paFonts, type } from '../../theme';
import { DoctorStackParamList } from '../../navigation/DoctorTabs';
import WidgetHost from '../../widgets/WidgetHost';
import { MONTHS, longDate, parseYmd, sameDay, startOfToday, time12, ymd } from '../../services/dates';

type Nav = NativeStackNavigationProp<DoctorStackParamList>;
interface ClinicLite { id: number; name: string }

const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const OPEN = ['pending', 'confirmed'];

/**
 * Doctor calendar: month grid (widget or built-in fallback), a collapsible
 * selected-day bar, the day's appointment cards with Edit (reschedule),
 * Check in and Cancel actions, and a "+" FAB to book for a patient.
 */
export default function DoctorCalendarScreen() {
  const insets = useSafeAreaInsets();
  const navigation = useNavigation<Nav>();
  const [cursor, setCursor] = useState(new Date());
  const [selected, setSelected] = useState(startOfToday());
  const [appointments, setAppointments] = useState<Appointment[]>([]);
  const [loading, setLoading] = useState(false);
  const [refreshKey, setRefreshKey] = useState(0);
  const [collapsed, setCollapsed] = useState(false);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [rescheduling, setRescheduling] = useState<Appointment | null>(null);
  const [creating, setCreating] = useState(false);
  // The month-calendar widget (with appointment dots) replaces the plain grid below when enabled.
  const [widgetKeys, setWidgetKeys] = useState<string[] | null>(null);

  const load = useCallback(async (date: Date) => {
    setLoading(true);
    try {
      const { data } = await api.get('/doctor/appointments', { params: { date: ymd(date) } });
      setAppointments(data.data);
    } catch (e) {
      Alert.alert('Could not load appointments', apiErrorMessage(e));
    } finally {
      setLoading(false);
    }
  }, []);

  useFocusEffect(
    useCallback(() => {
      load(selected);
    }, [load, selected])
  );

  function refreshAll() {
    load(selected);
    setRefreshKey((k) => k + 1);
  }

  function openAppointment(appointment: Appointment) {
    if (appointment.mode === 'video' && appointment.meet_url) {
      navigation.navigate('ConsultRoom', { appointment });
    } else {
      navigation.navigate('DoctorEncounter', { appointment });
    }
  }

  async function setStatus(a: Appointment, status: 'checked_in' | 'cancelled' | 'confirmed') {
    setBusyId(a.id);
    try {
      const { data } = await api.post(`/doctor/appointments/${a.id}/status`, { status });
      setAppointments((list) => list.map((x) => (x.id === a.id ? { ...x, ...data.data } : x)));
      setRefreshKey((k) => k + 1);
    } catch (e) {
      Alert.alert('Could not update appointment', apiErrorMessage(e));
    } finally {
      setBusyId(null);
    }
  }

  function confirmCancel(a: Appointment) {
    Alert.alert('Cancel appointment', `Cancel ${a.patient?.name ?? 'this patient'}'s appointment at ${time12(a.start_time)}? The patient will be notified.`, [
      { text: 'Keep', style: 'cancel' },
      { text: 'Cancel appointment', style: 'destructive', onPress: () => setStatus(a, 'cancelled') },
    ]);
  }

  const weeks = useMemo(() => {
    const year = cursor.getFullYear();
    const month = cursor.getMonth();
    const first = new Date(year, month, 1);
    const startOffset = (first.getDay() + 6) % 7; // Monday-first
    const daysInMonth = new Date(year, month + 1, 0).getDate();

    const cells: (Date | null)[] = [];
    for (let i = 0; i < startOffset; i++) cells.push(null);
    for (let d = 1; d <= daysInMonth; d++) cells.push(new Date(year, month, d));
    while (cells.length % 7 !== 0) cells.push(null);

    const rows: (Date | null)[][] = [];
    for (let i = 0; i < cells.length; i += 7) rows.push(cells.slice(i, i + 7));
    return rows;
  }, [cursor]);

  const isToday = sameDay(selected, startOfToday());
  const isPast = selected < startOfToday();
  const active = appointments.filter((a) => a.status !== 'cancelled');
  const cancelled = appointments.filter((a) => a.status === 'cancelled');

  return (
    <View style={{ flex: 1, backgroundColor: pa.paper }}>
      <ScrollView
        contentContainerStyle={{ padding: 16, paddingTop: insets.top + 16, paddingBottom: insets.bottom + 110 }}
        refreshControl={<RefreshControl refreshing={false} onRefresh={refreshAll} />}
      >
        {/* Kept mounted while collapsed so the widget doesn't refetch on every toggle. */}
        <View style={collapsed ? { display: 'none' } : undefined}>
          <WidgetHost
            screen="calendar"
            refreshKey={refreshKey}
            onLoaded={setWidgetKeys}
            context={{ selectedDate: selected, onSelectDate: (d: Date) => setSelected(new Date(d.getFullYear(), d.getMonth(), d.getDate())) }}
          />

          {widgetKeys !== null && !widgetKeys.includes('doctor_month_calendar') ? (
            <SoftCard>
              <View style={styles.monthHeader}>
                <Pressable onPress={() => setCursor(new Date(cursor.getFullYear(), cursor.getMonth() - 1, 1))} hitSlop={12}>
                  <Ionicons name="chevron-back" size={22} color={pa.ink} />
                </Pressable>
                <Text style={styles.monthTitle}>{MONTHS[cursor.getMonth()]} {cursor.getFullYear()}</Text>
                <Pressable onPress={() => setCursor(new Date(cursor.getFullYear(), cursor.getMonth() + 1, 1))} hitSlop={12}>
                  <Ionicons name="chevron-forward" size={22} color={pa.ink} />
                </Pressable>
              </View>
              <View style={styles.weekRow}>
                {WEEKDAYS.map((d) => <Text key={d} style={styles.weekday}>{d}</Text>)}
              </View>
              {weeks.map((row, i) => (
                <View key={i} style={styles.weekRow}>
                  {row.map((day, j) => {
                    const isSel = day && sameDay(day, selected);
                    return (
                      <Pressable key={j} disabled={!day} onPress={() => day && setSelected(day)} style={styles.dayCell}>
                        {day ? (
                          <View style={[styles.dayCircle, isSel && { backgroundColor: pa.signal }]}>
                            <Text style={[styles.dayText, isSel && { color: '#fff', fontFamily: paFonts.black }]}>{day.getDate()}</Text>
                          </View>
                        ) : null}
                      </Pressable>
                    );
                  })}
                </View>
              ))}
            </SoftCard>
          ) : null}
        </View>

        <Pressable onPress={() => setCollapsed((c) => !c)} style={styles.dayBar} accessibilityRole="button" accessibilityLabel={collapsed ? 'Show calendar' : 'Hide calendar'}>
          <Text style={styles.dayBarText}>{longDate(selected)}</Text>
          <Ionicons name={collapsed ? 'chevron-down' : 'chevron-up'} size={18} color={pa.ink} />
        </Pressable>

        <View style={styles.listHead}>
          <Text style={styles.listTitle}>{isToday ? "Today's appointments" : 'Appointments'}</Text>
          {loading ? <ActivityIndicator size="small" color={pa.signal} /> : <Text style={type.small}>{active.length}</Text>}
        </View>

        {active.length === 0 && !loading ? (
          <SoftCard><EmptyNote icon="calendar-clear-outline" text="No appointments on this day." /></SoftCard>
        ) : (
          active.map((a) => (
            <AppointmentCard
              key={a.id}
              a={a}
              busy={busyId === a.id}
              canAct={!isPast}
              onOpen={() => openAppointment(a)}
              onEdit={() => setRescheduling(a)}
              onCheckIn={() => setStatus(a, 'checked_in')}
              onConfirm={() => setStatus(a, 'confirmed')}
              onCancel={() => confirmCancel(a)}
            />
          ))
        )}

        {cancelled.length ? (
          <>
            <Text style={[styles.listTitle, { marginTop: 18, marginBottom: 8, color: pa.muted }]}>Cancelled</Text>
            {cancelled.map((a) => (
              <SoftCard key={a.id} style={{ marginBottom: 8, opacity: 0.6, flexDirection: 'row', alignItems: 'center', gap: 10 }}>
                <Text style={styles.cardTime}>{time12(a.start_time)}</Text>
                <Text style={[styles.cardName, { flex: 1 }]} numberOfLines={1}>{a.patient?.name ?? 'Patient'}</Text>
                <PaBadge status="cancelled" />
              </SoftCard>
            ))}
          </>
        ) : null}
      </ScrollView>

      <Fab label="Add appointment" onPress={() => setCreating(true)} bottom={insets.bottom + 24} />

      {rescheduling ? (
        <RescheduleSheet
          appointment={rescheduling}
          onClose={() => setRescheduling(null)}
          onDone={(newDate) => {
            setRescheduling(null);
            setSelected(parseYmd(newDate));
            setRefreshKey((k) => k + 1);
          }}
        />
      ) : null}

      {creating ? (
        <NewAppointmentSheet
          initialDate={selected < startOfToday() ? startOfToday() : selected}
          onClose={() => setCreating(false)}
          onDone={(newDate) => {
            setCreating(false);
            setSelected(parseYmd(newDate));
            setRefreshKey((k) => k + 1);
          }}
        />
      ) : null}
    </View>
  );
}

function AppointmentCard({
  a,
  busy,
  canAct,
  onOpen,
  onEdit,
  onCheckIn,
  onConfirm,
  onCancel,
}: {
  a: Appointment;
  busy: boolean;
  canAct: boolean;
  onOpen: () => void;
  onEdit: () => void;
  onCheckIn: () => void;
  onConfirm: () => void;
  onCancel: () => void;
}) {
  const open = OPEN.includes(a.status);
  const isVideo = a.mode === 'video' || a.visit_type === 'telemed';
  return (
    <View style={styles.card}>
      <Pressable onPress={onOpen} style={{ flexDirection: 'row', gap: 12, alignItems: 'center' }}>
        <PaAvatar name={a.patient?.name} size={44} />
        <View style={{ flex: 1 }}>
          <Text style={styles.cardName} numberOfLines={1}>{a.patient?.name ?? 'Patient'}</Text>
          <Text style={styles.cardSub} numberOfLines={1}>
            {a.service?.name ?? (isVideo ? 'Video consult' : 'Consultation')}{a.clinic?.name && !isVideo ? ` · ${a.clinic.name}` : ''}
          </Text>
          {a.reason ? <Text style={styles.cardSub} numberOfLines={1}>“{a.reason}”</Text> : null}
        </View>
        <View style={{ alignItems: 'flex-end', gap: 6 }}>
          <Text style={styles.cardTime}>{time12(a.start_time)}</Text>
          <PaBadge status={a.status} />
        </View>
      </Pressable>

      {canAct && open ? (
        <View style={styles.actions}>
          {busy ? (
            <ActivityIndicator color={pa.signal} style={{ flex: 1, paddingVertical: 8 }} />
          ) : (
            <>
              <ActionBtn icon="create-outline" label="Edit" onPress={onEdit} />
              {a.status === 'pending' ? <ActionBtn icon="checkmark-done-outline" label="Confirm" onPress={onConfirm} /> : null}
              <ActionBtn icon="log-in-outline" label="Check in" onPress={onCheckIn} tone="go" />
              <ActionBtn icon="trash-outline" label="Cancel" onPress={onCancel} tone="danger" />
            </>
          )}
        </View>
      ) : null}
    </View>
  );
}

function ActionBtn({ icon, label, onPress, tone }: { icon: React.ComponentProps<typeof Ionicons>['name']; label: string; onPress: () => void; tone?: 'go' | 'danger' }) {
  const color = tone === 'go' ? pa.go : tone === 'danger' ? pa.signal : pa.info;
  return (
    <Pressable onPress={onPress} style={({ pressed }) => [styles.action, { opacity: pressed ? 0.6 : 1 }]} accessibilityRole="button" accessibilityLabel={label}>
      <Ionicons name={icon} size={17} color={color} />
      <Text style={[styles.actionText, { color }]}>{label}</Text>
    </Pressable>
  );
}

function RescheduleSheet({ appointment, onClose, onDone }: { appointment: Appointment; onClose: () => void; onDone: (date: string) => void }) {
  const today = ymd(startOfToday());
  const [date, setDate] = useState(appointment.date >= today ? appointment.date : today);
  const [time, setTime] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const clinicId = appointment.clinic?.id;

  const loadSlots = useCallback(
    async (d: string): Promise<SlotSchedule> => {
      const { data } = await api.get('/doctor/slots', { params: { clinic_id: clinicId, date: d, appointment_id: appointment.id } });
      return data;
    },
    [clinicId, appointment.id]
  );

  async function save() {
    if (!time) return;
    setSaving(true);
    try {
      await api.post(`/doctor/appointments/${appointment.id}/reschedule`, { date, start_time: time });
      onDone(date);
    } catch (e) {
      Alert.alert('Could not reschedule', apiErrorMessage(e));
    } finally {
      setSaving(false);
    }
  }

  return (
    <Sheet
      visible
      onClose={onClose}
      title="Reschedule appointment"
      footer={<PrimaryButton title={time ? `Move to ${time12(time)}` : 'Pick a new time'} onPress={save} loading={saving} disabled={!time} />}
    >
      <Text style={styles.sheetNote}>
        {appointment.patient?.name ?? 'Patient'} · currently {longDate(parseYmd(appointment.date))} at {time12(appointment.start_time)}
      </Text>
      {clinicId ? (
        <SlotPicker load={loadSlots} date={date} onDateChange={setDate} time={time} onTimeChange={setTime} />
      ) : (
        <EmptyNote text="This appointment has no clinic, so it can't be rescheduled here." />
      )}
    </Sheet>
  );
}

function NewAppointmentSheet({ initialDate, onClose, onDone }: { initialDate: Date; onClose: () => void; onDone: (date: string) => void }) {
  const [patients, setPatients] = useState<DoctorPatient[] | null>(null);
  const [clinics, setClinics] = useState<ClinicLite[]>([]);
  const [services, setServices] = useState<ServiceItem[]>([]);
  const [query, setQuery] = useState('');
  const [patient, setPatient] = useState<DoctorPatient | null>(null);
  const [clinicId, setClinicId] = useState<number | null>(null);
  const [serviceId, setServiceId] = useState<number | null>(null);
  const [visitType, setVisitType] = useState<'clinic' | 'telemed'>('clinic');
  const [date, setDate] = useState(ymd(initialDate));
  const [time, setTime] = useState<string | null>(null);
  const [reason, setReason] = useState('');
  const [saving, setSaving] = useState(false);

  React.useEffect(() => {
    Promise.all([api.get('/doctor/patients'), api.get('/doctor/settings'), api.get('/doctor/services')])
      .then(([p, o, sv]) => {
        setPatients(p.data.data);
        setClinics(o.data.clinics);
        setClinicId(o.data.clinics[0]?.id ?? null);
        setServices((sv.data.data as ServiceItem[]).filter((x) => x.is_active));
      })
      .catch((e) => {
        setPatients([]);
        Alert.alert('Could not load', apiErrorMessage(e));
      });
  }, []);

  const loadSlots = useCallback(
    async (d: string): Promise<SlotSchedule> => {
      if (!clinicId) return { slots: [], sessions: [] };
      const { data } = await api.get('/doctor/slots', { params: { clinic_id: clinicId, date: d } });
      return data;
    },
    [clinicId]
  );

  async function save() {
    if (!patient || !clinicId || !time) return;
    setSaving(true);
    try {
      await api.post('/doctor/appointments', {
        patient_profile_id: patient.id,
        clinic_id: clinicId,
        service_id: serviceId ?? undefined,
        date,
        start_time: time,
        visit_type: visitType,
        reason: reason.trim() || undefined,
      });
      Alert.alert('Appointment booked', `${patient.name} is booked for ${longDate(parseYmd(date))} at ${time12(time)}. They have been notified.`);
      onDone(date);
    } catch (e) {
      Alert.alert('Could not book', apiErrorMessage(e));
    } finally {
      setSaving(false);
    }
  }

  const filtered = (patients ?? []).filter((p) => !query.trim() || (p.name ?? '').toLowerCase().includes(query.trim().toLowerCase()) || (p.phone ?? '').includes(query.trim()));
  const clinicServices = services.filter((sv) => sv.clinic_id === clinicId);

  return (
    <Sheet
      visible
      onClose={onClose}
      title="New appointment"
      footer={<PrimaryButton title="Book appointment" onPress={save} loading={saving} disabled={!patient || !clinicId || !time} />}
    >
      <Text style={styles.stepLabel}>1. Patient</Text>
      {patient ? (
        <Pressable onPress={() => setPatient(null)} style={[styles.pickedRow]}>
          <PaAvatar name={patient.name} size={34} />
          <View style={{ flex: 1 }}>
            <Text style={styles.cardName}>{patient.name}</Text>
            <Text style={styles.cardSub}>{patient.phone ?? `${patient.visits} visit(s)`}</Text>
          </View>
          <Text style={{ fontFamily: paFonts.bold, color: pa.info, fontSize: 13 }}>Change</Text>
        </Pressable>
      ) : patients === null ? (
        <ActivityIndicator color={pa.signal} style={{ marginVertical: 12 }} />
      ) : patients.length === 0 ? (
        <EmptyNote icon="people-outline" text="You can book for patients who have seen you before. New patients book themselves in the app." />
      ) : (
        <>
          <View style={styles.searchWrap}>
            <Ionicons name="search" size={16} color={pa.muted} />
            <TextInput value={query} onChangeText={setQuery} placeholder="Search your patients" placeholderTextColor={pa.muted2} style={styles.searchInput} />
          </View>
          {filtered.slice(0, 6).map((p) => (
            <Pressable key={p.id} onPress={() => setPatient(p)} style={styles.patientRow}>
              <PaAvatar name={p.name} size={30} />
              <Text style={[styles.cardName, { flex: 1 }]} numberOfLines={1}>{p.name}</Text>
              <Text style={styles.cardSub}>{p.last_visit ?? ''}</Text>
            </Pressable>
          ))}
          {filtered.length === 0 ? <Text style={styles.sheetNote}>No matching patients.</Text> : null}
        </>
      )}

      {patient ? (
        <>
          {clinics.length > 1 ? (
            <>
              <Text style={styles.stepLabel}>2. Clinic</Text>
              <View style={styles.chipRow}>
                {clinics.map((c) => <Chip key={c.id} label={c.name} active={clinicId === c.id} onPress={() => { setClinicId(c.id); setServiceId(null); setTime(null); }} />)}
              </View>
            </>
          ) : null}

          <Text style={styles.stepLabel}>{clinics.length > 1 ? '3' : '2'}. Service & visit</Text>
          <View style={styles.chipRow}>
            <Chip label="Consultation" active={serviceId === null} onPress={() => setServiceId(null)} />
            {clinicServices.map((sv) => <Chip key={sv.id} label={sv.name} active={serviceId === sv.id} onPress={() => setServiceId(sv.id)} />)}
          </View>
          <View style={styles.chipRow}>
            <Chip label="In person" active={visitType === 'clinic'} onPress={() => setVisitType('clinic')} />
            <Chip label="Video" active={visitType === 'telemed'} onPress={() => setVisitType('telemed')} />
          </View>

          <Text style={styles.stepLabel}>{clinics.length > 1 ? '4' : '3'}. Date & time</Text>
          {clinicId ? (
            <SlotPicker load={loadSlots} reloadKey={clinicId} date={date} onDateChange={setDate} time={time} onTimeChange={setTime} />
          ) : (
            <EmptyNote icon="business-outline" text="You are not linked to a clinic yet." />
          )}

          <Field label="Note (optional)" value={reason} onChangeText={setReason} placeholder="Reason for visit" maxLength={1000} />
        </>
      ) : null}
    </Sheet>
  );
}

const styles = StyleSheet.create({
  monthHeader: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 12 },
  monthTitle: { fontFamily: paFonts.black, fontSize: 16, color: pa.ink },
  weekRow: { flexDirection: 'row', justifyContent: 'space-between', marginBottom: 4 },
  weekday: { flex: 1, textAlign: 'center', fontFamily: paFonts.bold, fontSize: 11, color: pa.muted },
  dayCell: { flex: 1, aspectRatio: 1, alignItems: 'center', justifyContent: 'center' },
  dayCircle: { width: 34, height: 34, borderRadius: 17, alignItems: 'center', justifyContent: 'center' },
  dayText: { fontFamily: paFonts.regular, fontSize: 13, color: pa.inkSoft },
  dayBar: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8, backgroundColor: pa.surface, borderRadius: R.pill, paddingVertical: 10, marginTop: 16, marginBottom: 16, ...softShadow },
  dayBarText: { fontFamily: paFonts.bold, fontSize: 14, color: pa.ink },
  listHead: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 10 },
  listTitle: { fontFamily: paFonts.black, fontSize: 16, color: pa.ink },
  card: { backgroundColor: pa.surface, borderRadius: R.card, padding: 14, marginBottom: 10, ...softShadow },
  cardName: { fontFamily: paFonts.bold, fontSize: 15, color: pa.ink },
  cardSub: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted, marginTop: 2 },
  cardTime: { fontFamily: paFonts.black, fontSize: 14, color: pa.ink },
  actions: { flexDirection: 'row', borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: pa.line, marginTop: 12, paddingTop: 8 },
  action: { flex: 1, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 5, paddingVertical: 6 },
  actionText: { fontFamily: paFonts.bold, fontSize: 12 },
  sheetNote: { fontFamily: paFonts.regular, fontSize: 13, color: pa.muted, marginBottom: 12 },
  stepLabel: { fontFamily: paFonts.black, fontSize: 13, color: pa.ink, marginTop: 6, marginBottom: 8 },
  chipRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginBottom: 12 },
  pickedRow: { flexDirection: 'row', alignItems: 'center', gap: 10, backgroundColor: pa.paper, borderRadius: R.input, padding: 10, marginBottom: 12 },
  searchWrap: { flexDirection: 'row', alignItems: 'center', gap: 8, backgroundColor: pa.paper, borderRadius: R.input, paddingHorizontal: 12, height: 42, marginBottom: 6 },
  searchInput: { flex: 1, fontFamily: paFonts.regular, fontSize: 14, color: pa.ink, paddingVertical: 0 },
  patientRow: { flexDirection: 'row', alignItems: 'center', gap: 10, paddingVertical: 9, borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: pa.lineSoft },
});
