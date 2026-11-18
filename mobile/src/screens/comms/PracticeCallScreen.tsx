import React, { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Alert, PermissionsAndroid, Platform, Pressable, ScrollView, StyleSheet, Switch, Text, TextInput, View } from 'react-native';
import { WebView, WebViewMessageEvent } from 'react-native-webview';
import * as Location from 'expo-location';
import { useNavigation, useRoute } from '@react-navigation/native';
import { apiErrorMessage } from '../../api/client';
import { PaButton, PaCard, PaSectionLabel } from '../../components/pa';
import PaymentSheet from '../../components/payments/PaymentSheet';
import { setActivePracticeCall } from '../../components/PracticeCallHost';
import {
  acceptPracticeCall,
  bookOnCall,
  chooseShuttle,
  declinePracticeCall,
  endPracticeCall,
  fmtDuration,
  getCallSlots,
  getPracticeCall,
  offerShuttle,
  PracticeCallState,
  practiceCallPresence,
  ringPatientAgain,
  sharePracticeCallLocation,
  SlotDay,
} from '../../services/practiceCalls';
import { pa, paFonts } from '../../theme';

const money = (n: number | null | undefined) => `R ${Number(n ?? 0).toFixed(2)}`;
const addDays = (iso: string, d: number) => {
  const t = new Date(iso + 'T12:00:00');
  t.setDate(t.getDate() + d);
  return t.toISOString().slice(0, 10);
};

/**
 * Orange practice call (both sides). The in-app VOICE call runs in a small
 * WebView (our server's Jitsi audio page, single-use link); around it:
 *  - accept / decline while ringing, the recording notice, hang up;
 *  - practice: book the appointment (GP, date, free slot, in-person / video,
 *    reason, notes) and offer a shuttle; call the patient again;
 *  - patient: the booking card, share location, choose a shuttle and pay.
 */
export default function PracticeCallScreen() {
  const navigation = useNavigation<any>();
  const { callId } = useRoute<any>().params as { callId: number };
  const [s, setS] = useState<PracticeCallState | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [uri, setUri] = useState<string | null>(null);
  const [now, setNow] = useState(Date.now());
  const inCall = useRef(false);
  // booking form (practice)
  const [doctorId, setDoctorId] = useState<number | null>(null);
  const [date, setDate] = useState<string>('');
  const [mode, setMode] = useState<'in_person' | 'video'>('in_person');
  const [slots, setSlots] = useState<SlotDay | null>(null);
  const [slot, setSlot] = useState<string | null>(null);
  const [outside, setOutside] = useState(false);
  const [outsideTime, setOutsideTime] = useState('');
  const [reason, setReason] = useState('');
  const [notes, setNotes] = useState('');
  const [returnLeg, setReturnLeg] = useState(false);
  const [pay, setPay] = useState<{ id: number; amount: number } | null>(null);
  const formInit = useRef(false);

  const load = useCallback(async () => {
    try {
      const st = await getPracticeCall(callId);
      setS(st);
      setError(null);
      if (st.join?.embed_url) setUri((u) => u ?? st.join!.embed_url!);
      if (st.booking_options && !formInit.current) {
        formInit.current = true;
        setDoctorId(st.booking?.doctor_id ?? st.booking_options.default_doctor_id);
        setDate(st.booking?.date ?? st.booking_options.today);
        setMode(st.booking?.mode ?? st.booking_options.default_mode);
        setReason(st.booking?.reason ?? '');
      }
      if (st.newer_call_id && !st.connected && st.status !== 'ringing') navigation.replace('PracticeCall', { callId: st.newer_call_id });
    } catch (e) {
      setError(apiErrorMessage(e, 'Could not load the call.'));
    }
  }, [callId, navigation]);

  useEffect(() => {
    setActivePracticeCall(callId);
    load();
    const t = setInterval(load, 3000);
    const c = setInterval(() => setNow(Date.now()), 1000);
    return () => {
      clearInterval(t);
      clearInterval(c);
      setActivePracticeCall(null);
      if (inCall.current) practiceCallPresence(callId, 'out');
    };
  }, [callId, load]);

  // Microphone permission before the call page asks for it.
  useEffect(() => {
    if (uri && Platform.OS === 'android') PermissionsAndroid.request(PermissionsAndroid.PERMISSIONS.RECORD_AUDIO).catch(() => undefined);
  }, [uri]);

  useEffect(() => {
    if (!doctorId || !date || !s?.booking_options) return;
    getCallSlots(callId, doctorId, date)
      .then((d) => {
        setSlots(d);
        setSlot(s?.booking && s.booking.date === date && s.booking.doctor_id === doctorId ? s.booking.start_time : null);
      })
      .catch(() => setSlots(null));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [doctorId, date, callId, !!s?.booking_options]);

  async function act(fn: () => Promise<PracticeCallState>, ok?: string) {
    if (busy) return;
    setBusy(true);
    try {
      const st = await fn();
      setS(st);
      if (st.join?.embed_url && !uri) setUri(st.join.embed_url);
      if (ok) Alert.alert(ok);
    } catch (e) {
      Alert.alert('Not done', apiErrorMessage(e));
    } finally {
      setBusy(false);
    }
  }

  function onMessage(e: WebViewMessageEvent) {
    try {
      const m = JSON.parse(e.nativeEvent.data);
      if (m.type === 'joined') {
        inCall.current = true;
        practiceCallPresence(callId, 'in');
      } else if (m.type === 'ended') {
        inCall.current = false;
        practiceCallPresence(callId, 'out');
      }
    } catch {
      /* ignore */
    }
  }

  async function shareLocation() {
    try {
      const perm = await Location.requestForegroundPermissionsAsync();
      if (perm.status !== 'granted') {
        Alert.alert('Location needed', 'Allow location access to share where the shuttle should pick you up.');
        return;
      }
      const pos = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Balanced });
      await sharePracticeCallLocation(callId, pos.coords.latitude, pos.coords.longitude);
      Alert.alert('Location shared with the practice');
    } catch (e) {
      Alert.alert('Not shared', apiErrorMessage(e, 'Could not get your location.'));
    }
  }

  function saveBooking() {
    if (!doctorId) return;
    const time = outside ? outsideTime.trim() : slot;
    if (!time || !/^\d{2}:\d{2}$/.test(time)) {
      Alert.alert('Choose a time', outside ? 'Enter the time as HH:MM.' : 'Tap a free time.');
      return;
    }
    act(() => bookOnCall(callId, { doctor_profile_id: doctorId, date, start_time: time, mode, reason, notes, outside_sessions: outside }), 'Booked — it is on the doctor’s and the patient’s calendars.');
  }

  if (!s) {
    return <View style={styles.center}>{error ? <Text style={styles.muted}>{error}</Text> : <ActivityIndicator color={pa.signal} />}</View>;
  }
  const practice = s.role === 'practice';
  const ended = ['ended', 'declined', 'missed', 'cancelled'].includes(s.status);
  const elapsed = s.connected_at && !ended ? Math.max(0, Math.floor((now - Date.parse(s.connected_at)) / 1000)) : s.duration_seconds;
  const opts = s.booking_options;
  const sh = s.shuttle;

  return (
    <ScrollView style={{ flex: 1, backgroundColor: pa.paper }} contentContainerStyle={{ padding: 16, paddingBottom: 48 }} keyboardShouldPersistTaps="handled">
      <PaCard style={{ borderLeftWidth: 6, borderLeftColor: s.connected ? pa.go : ended ? pa.muted : pa.sats.orange }}>
        <Text style={styles.eyebrow}>{practice ? 'Urgent patient call' : 'Call with your practice'}</Text>
        <Text style={styles.title}>{practice ? s.patient.name : s.practice.name}</Text>
        {!practice && s.doctor ? <Text style={styles.muted}>For {s.doctor.name}</Text> : null}
        {practice && s.patient.phone ? <Text style={styles.muted}>{s.patient.phone}</Text> : null}
        <Text style={[styles.body, { marginTop: 6 }]}>
          {s.status_text}
          {s.connected || (ended && s.duration_seconds) ? ` · ${fmtDuration(elapsed)}` : ''}
        </Text>
        {s.recorded && s.notice ? (
          <View style={styles.notice}>
            <Text style={styles.noticeText}>● REC — {s.notice}</Text>
          </View>
        ) : null}
        {practice && s.patient.brief?.length ? s.patient.brief.map((l, i) => <Text key={i} style={styles.muted}>• {l}</Text>) : null}

        {s.needs_my_accept ? (
          <View style={styles.row}>
            <PaButton title="Accept call" onPress={() => act(() => acceptPracticeCall(callId))} loading={busy} style={{ flex: 1, backgroundColor: '#1E8E3E', borderColor: '#1E8E3E' }} />
            <PaButton title="Decline" variant="ghost" onPress={() => act(() => declinePracticeCall(callId))} style={{ flex: 1 }} />
          </View>
        ) : null}

        {uri && !ended ? (
          <View style={styles.webWrap}>
            <WebView
              source={{ uri }}
              style={{ flex: 1, backgroundColor: '#0b0b0d' }}
              originWhitelist={['https://*', 'about:*', 'blob:*', 'data:*']}
              javaScriptEnabled
              domStorageEnabled
              thirdPartyCookiesEnabled
              sharedCookiesEnabled
              allowsInlineMediaPlayback
              mediaPlaybackRequiresUserAction={false}
              mediaCapturePermissionGrantType="grant"
              setSupportMultipleWindows={false}
              scrollEnabled={false}
              onMessage={onMessage}
            />
          </View>
        ) : null}
        {s.join?.error ? <Text style={styles.err}>{s.join.error}</Text> : null}

        <View style={styles.row}>
          {!ended && !s.needs_my_accept ? (
            <PaButton
              title="Hang up"
              onPress={() =>
                practice && !s.booking && s.connected
                  ? Alert.alert('Hang up', 'No booking was made.', [
                      { text: 'Keep the request open', onPress: () => act(() => endPracticeCall(callId, false)) },
                      { text: 'Close without booking', style: 'destructive', onPress: () => act(() => endPracticeCall(callId, true)) },
                      { text: 'Cancel', style: 'cancel' },
                    ])
                  : act(() => endPracticeCall(callId))
              }
              style={{ flex: 1 }}
            />
          ) : null}
          {practice && s.can_ring_again ? <PaButton title="Call the patient again" onPress={() => act(() => ringPatientAgain(callId))} style={{ flex: 1 }} /> : null}
        </View>
        {!practice && !ended ? <PaButton title="Share my location (for a shuttle)" variant="ghost" onPress={shareLocation} style={{ marginTop: 8 }} /> : null}
      </PaCard>

      {s.booking ? (
        <PaCard style={{ marginTop: 12, backgroundColor: pa.goWash }}>
          <Text style={styles.eyebrow}>{practice ? 'Booked' : 'Your booking'}</Text>
          <Text style={styles.cardTitle}>
            {s.booking.mode_label} · {s.booking.when_label}
          </Text>
          <Text style={styles.muted}>
            {s.booking.doctor_name}
            {s.booking.practice_name ? ` · ${s.booking.practice_name}` : ''}
          </Text>
          {s.booking.mode === 'in_person' && s.booking.practice_address ? <Text style={styles.muted}>{s.booking.practice_address}</Text> : null}
          <Text style={styles.muted}>Ref {s.booking.booking_ref} — {practice ? 'on the doctor’s and the patient’s calendars' : 'in your Appointments and calendar'}</Text>
        </PaCard>
      ) : !practice ? (
        <Text style={[styles.muted, { marginTop: 12 }]}>The practice books your visit with you during the call — it appears here straight away. No consultation fee is charged in the app.</Text>
      ) : null}

      {practice && opts ? (
        <PaCard style={{ marginTop: 12 }}>
          <PaSectionLabel>{s.booking ? 'Change the booking' : 'Book the appointment'}</PaSectionLabel>
          <Text style={styles.label}>Doctor</Text>
          <View style={styles.wrapRow}>
            {opts.doctors.map((d) => (
              <Pressable key={d.id} onPress={() => setDoctorId(d.id)} style={[styles.chip, doctorId === d.id && styles.chipOn]}>
                <Text style={[styles.chipText, doctorId === d.id && { color: '#fff' }]}>{d.name}</Text>
              </Pressable>
            ))}
          </View>
          <Text style={styles.label}>Date</Text>
          <View style={styles.wrapRow}>
            <Pressable onPress={() => date > opts.today && setDate(addDays(date, -1))} style={styles.chip}>
              <Text style={styles.chipText}>‹</Text>
            </Pressable>
            <Text style={[styles.cardTitle, { paddingHorizontal: 8 }]}>{date}</Text>
            <Pressable onPress={() => setDate(addDays(date, 1))} style={styles.chip}>
              <Text style={styles.chipText}>›</Text>
            </Pressable>
          </View>
          <Text style={styles.label}>Consult type</Text>
          <View style={styles.wrapRow}>
            {opts.modes.map((m) => (
              <Pressable key={m} onPress={() => setMode(m)} style={[styles.chip, mode === m && styles.chipOn]}>
                <Text style={[styles.chipText, mode === m && { color: '#fff' }]}>{m === 'video' ? 'Video consult' : 'In person'}</Text>
              </Pressable>
            ))}
          </View>
          <Text style={styles.label}>Free times</Text>
          {slots?.time_off ? <Text style={styles.muted}>The doctor is off: {slots.time_off}</Text> : null}
          <View style={styles.wrapRow}>
            {(slots?.sessions ?? []).flatMap((ss) => ss.slots).map((sl) => (
              <Pressable key={sl.time} disabled={!sl.available} onPress={() => setSlot(sl.time)} style={[styles.chip, slot === sl.time && styles.chipOn, !sl.available && { opacity: 0.35 }]}>
                <Text style={[styles.chipText, slot === sl.time && { color: '#fff' }, !sl.available && { textDecorationLine: 'line-through' }]}>{sl.time}</Text>
              </Pressable>
            ))}
            {slots && !slots.sessions.length && !slots.time_off ? <Text style={styles.muted}>No sessions this day.</Text> : null}
          </View>
          <View style={[styles.rowBetween, { marginTop: 8 }]}>
            <Text style={styles.body}>Outside the doctor’s sessions</Text>
            <Switch value={outside} onValueChange={setOutside} />
          </View>
          {outside ? <TextInput value={outsideTime} onChangeText={setOutsideTime} placeholder="HH:MM" style={styles.input} keyboardType="numbers-and-punctuation" maxLength={5} /> : null}
          <TextInput value={reason} onChangeText={setReason} placeholder="Reason for the visit" style={styles.input} maxLength={500} />
          <TextInput value={notes} onChangeText={setNotes} placeholder="Notes for the practice (patient does not see)" style={styles.input} maxLength={1000} multiline />
          <PaButton title={s.booking ? 'Update booking' : 'Save booking'} onPress={saveBooking} loading={busy} style={{ marginTop: 10 }} />
        </PaCard>
      ) : null}

      {practice && opts?.shuttle ? (
        <PaCard style={{ marginTop: 12 }}>
          <PaSectionLabel>Shuttle</PaSectionLabel>
          {sh?.status === 'chosen' ? (
            <Text style={styles.body}>
              {sh.vehicle_type?.replace(/_/g, ' ')} · {money(sh.fare)} · pickup {sh.pickup_at ? new Date(sh.pickup_at).toLocaleString() : 'as soon as possible'} · payment {sh.payment?.state ?? '—'}
            </Text>
          ) : (
            <>
              <Text style={styles.muted}>Pickup: {s.patient.location ? `${s.patient.location.label} (shared from the call)` : 'the patient’s saved address'} → your practice.</Text>
              <View style={[styles.rowBetween, { marginTop: 6 }]}>
                <Text style={styles.body}>Include the return trip</Text>
                <Switch value={returnLeg} onValueChange={setReturnLeg} />
              </View>
              <PaButton title={sh ? 'Re-price & resend options' : 'Book a shuttle for the patient'} variant="ghost" onPress={() => act(() => offerShuttle(callId, returnLeg), 'Shuttle options sent to the patient.')} style={{ marginTop: 8 }} />
              {sh?.quotes.map((q) => (
                <Text key={q.vehicle_type} style={styles.muted}>
                  {q.label}: {money(q.fare)} · {q.distance_km.toFixed(1)} km · ~{q.eta_minutes} min
                </Text>
              ))}
            </>
          )}
        </PaCard>
      ) : null}

      {!practice && sh ? (
        <PaCard style={{ marginTop: 12 }}>
          <PaSectionLabel>{sh.status === 'offered' ? 'Choose your shuttle & pay' : 'Your shuttle'}</PaSectionLabel>
          {sh.status === 'offered' ? (
            <>
              <Text style={styles.muted}>
                {sh.pickup?.address} → {sh.dropoff?.address}
                {sh.return_leg ? ' (and back)' : ''}
              </Text>
              {sh.quotes.map((q) => (
                <Pressable
                  key={q.vehicle_type}
                  style={styles.quote}
                  onPress={() =>
                    Alert.alert(`${q.label} · ${money(q.fare)}`, 'Book this shuttle and pay now?', [
                      { text: 'Cancel', style: 'cancel' },
                      {
                        text: 'Book & pay',
                        onPress: () =>
                          act(async () => {
                            const st = await chooseShuttle(callId, q.vehicle_type);
                            const p = st.shuttle?.payment;
                            if (p?.required && st.shuttle?.ride) setPay({ id: st.shuttle.ride.id, amount: p.amount });
                            return st;
                          }),
                      },
                    ])
                  }
                >
                  <Text style={styles.cardTitle}>{q.label}</Text>
                  <Text style={styles.cardTitle}>{money(q.fare)}</Text>
                  <Text style={[styles.muted, { width: '100%' }]}>
                    {q.distance_km.toFixed(1)} km · ~{q.eta_minutes} min{q.surge_label ? ` · ${q.surge_label}` : ''}
                  </Text>
                </Pressable>
              ))}
            </>
          ) : (
            <>
              <Text style={styles.body}>
                {sh.vehicle_type?.replace(/_/g, ' ')} · {money(sh.fare)} · pickup {sh.pickup_at ? new Date(sh.pickup_at).toLocaleString() : 'as soon as possible'}
              </Text>
              {sh.payment?.required && sh.ride ? <PaButton title="Pay now" onPress={() => setPay({ id: sh.ride!.id, amount: sh.payment!.amount })} style={{ marginTop: 8 }} /> : <Text style={styles.muted}>Payment: {sh.payment?.state ?? '—'}</Text>}
              {sh.ride ? <PaButton title="Track trip" variant="ghost" onPress={() => navigation.navigate('Main', { screen: 'Rides', params: { screen: 'RideTrack', params: { rideId: sh.ride!.id } } })} style={{ marginTop: 8 }} /> : null}
            </>
          )}
        </PaCard>
      ) : null}

      {pay ? (
        <PaymentSheet
          visible
          payable={{ type: 'ride', id: pay.id }}
          amount={pay.amount}
          title="Pay for your shuttle"
          onPaid={() => {
            setPay(null);
            load();
          }}
          onClose={() => setPay(null)}
        />
      ) : null}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: pa.paper, padding: 24 },
  eyebrow: { fontFamily: paFonts.bold, fontSize: 11, letterSpacing: 1.4, textTransform: 'uppercase', color: pa.muted },
  title: { fontFamily: paFonts.black, fontSize: 22, color: pa.ink, marginTop: 2 },
  cardTitle: { fontFamily: paFonts.black, fontSize: 16, color: pa.ink },
  body: { fontFamily: paFonts.regular, fontSize: 15, color: pa.ink },
  muted: { fontFamily: paFonts.regular, fontSize: 13, color: pa.muted, marginTop: 2 },
  err: { fontFamily: paFonts.bold, fontSize: 13, color: pa.signal, marginTop: 8 },
  label: { fontFamily: paFonts.bold, fontSize: 13, color: pa.ink, marginTop: 10, marginBottom: 4 },
  notice: { backgroundColor: '#FFF7ED', borderColor: '#FDBA74', borderWidth: 1, padding: 8, marginTop: 8 },
  noticeText: { fontFamily: paFonts.bold, fontSize: 12, color: '#7C2D12' },
  row: { flexDirection: 'row', gap: 8, marginTop: 12 },
  rowBetween: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  wrapRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 6, alignItems: 'center' },
  chip: { borderWidth: 1, borderColor: pa.line, paddingHorizontal: 10, paddingVertical: 7, backgroundColor: pa.surface },
  chipOn: { backgroundColor: pa.info, borderColor: pa.info },
  chipText: { fontFamily: paFonts.bold, fontSize: 13, color: pa.ink },
  input: { borderWidth: 1, borderColor: pa.line, backgroundColor: pa.surface, padding: 10, marginTop: 8, fontFamily: paFonts.regular, fontSize: 15, color: pa.ink },
  webWrap: { height: 170, marginTop: 12, backgroundColor: '#0b0b0d', overflow: 'hidden' },
  quote: { flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'space-between', borderWidth: 1, borderColor: pa.line, padding: 12, marginTop: 8, backgroundColor: pa.surface },
});
