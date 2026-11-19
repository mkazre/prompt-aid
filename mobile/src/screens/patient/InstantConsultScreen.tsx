import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Alert, Linking, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import * as Location from 'expo-location';
import { useFocusEffect } from '@react-navigation/native';
import { api, apiErrorMessage } from '../../api/client';
import { PaButton, PaCard, PaSectionLabel } from '../../components/pa';
import { pa, paFonts, paRadius, type } from '../../theme';
import { telUrl, useSiteContent, whatsappUrl } from '../../services/siteContent';
import { useAuth } from '../../context/AuthContext';
import { CallbackChannel, CallbackInfo, displayPhone, RingPayload } from '../../services/consults';
import { showIncomingCall } from '../../components/PatientCallHost';
import PaymentSheet from '../../components/payments/PaymentSheet';
import { getScenario, goToTarget, ScenarioLevel, ScenarioPlan, ScenarioStep } from '../../services/scenarios';
import type { ChannelChoice } from '../../services/consults';
import type { TriageLevel } from '../../services/triage';

type Mode = 'video' | 'callback' | 'in_person' | 'voice';

/** Shape of DoctorMatchService::patientPayload() (GET /doctor-match/current). */
interface MatchPayload {
  id: number;
  appointment_id: number;
  status: 'searching' | 'matched' | 'expired' | 'cancelled';
  booking_ref: string | null;
  specialty: string;
  mode: Mode;
  price: number;
  seconds_left: number;
  online_doctors: number;
  alerted_doctors?: number;
  broadcast?: boolean;
  outcome_message: string | null;
  doctor: { name: string; specialization: string } | null;
  clinic: { name: string; address: string | null; directions_url?: string } | null;
  video_url: string | null;
  can_join_video: boolean;
  can_cancel: boolean;
  cancel_fee_now: number;
  ring?: RingPayload | null;
  callback?: CallbackInfo | null;
  /** Consult hub (video / voice / chat + fallback) once matched — video & call back. */
  hub_url?: string | null;
  appointment_status?: string | null;
  dispatch_phone?: string | null;
  dispatch_tel?: string | null;
  /** Patient request scenario additions (absent on older servers). */
  payment?: { required: boolean; state: string; amount: number } | null;
  scenario?: { level: TriageLevel; version: number | null; channel_timing: string | null } | null;
  /** 'practice' = orange practice call: the practice calls in the app and books the visit (no payment). */
  flow?: 'practice' | 'doctor';
  consult_payments?: boolean;
  practice_call?: {
    call_id: number | null;
    call_status: string | null;
    ringing: boolean;
    practice_name: string | null;
    booked: boolean;
    booking: { mode_label: string; when_label: string; doctor_name: string | null; practice_name: string | null; practice_address: string | null; booking_ref: string; mode: string } | null;
  } | null;
  channel_choice?: ChannelChoice | null;
  chosen_channel?: string | null;
  fallback?: { action: 'retry' | 'call' | 'book'; label: string; tel?: string | null; url?: string | null; radius_km?: number | null } | null;
  alternatives?: ScenarioStep[];
}

/**
 * Orange on the PromptAid Emergency Scale: "speak to a doctor now".
 * Two options — Video consult now, or Request a call back (number from the
 * profile, editable; normal / WhatsApp / either; optional note). In person
 * stays available as a smaller third option. The request goes to EVERY
 * available doctor at once (broadcast); the first to accept takes it.
 * Video: when the doctor is ready the phone rings (PatientCallHost) and
 * joins the in-app VideoCallScreen. Polls GET /doctor-match/current.
 */
export default function InstantConsultScreen({ navigation, route }: any) {
  const { emergency } = useSiteContent();
  const { user } = useAuth();
  const [specialties, setSpecialties] = useState<string[]>([]);
  const [specialty, setSpecialty] = useState<string | null>(null);
  const [mode, setMode] = useState<Mode>(route?.params?.mode === 'callback' ? 'callback' : route?.params?.mode === 'in_person' ? 'in_person' : 'video');
  const [phone, setPhone] = useState<string>(displayPhone((user as any)?.phone ?? ''));
  const [channel, setChannel] = useState<CallbackChannel>('either');
  const [note, setNote] = useState('');
  const [match, setMatch] = useState<MatchPayload | null>(null);
  const [sending, setSending] = useState(false);
  const [cancelling, setCancelling] = useState(false);
  const lastBody = useRef<Record<string, unknown> | null>(null);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const triageReference: string | undefined = route?.params?.triageReference;
  const hubOpened = useRef<Record<number, true>>({});
  // Patient request scenario (Staff → Triage): orange default = find the doctor first,
  // the patient picks video / voice / WhatsApp only after a doctor accepted.
  const level: ScenarioLevel | undefined = route?.params?.level;
  const practiceOpened = useRef<Record<number, true>>({});
  const [plan, setPlan] = useState<ScenarioPlan | null>(null);
  const [planLoaded, setPlanLoaded] = useState(!level);
  const loadedOnce = useRef(false);
  // State (not only a ref) so the auto-start effect re-runs once the first status check is back,
  // even when it returns 'no request' (setMatch(null) alone would not re-render).
  const [matchLoaded, setMatchLoaded] = useState(false);
  const autoDone = useRef(false);
  const [payFor, setPayFor] = useState<MatchPayload | null>(null);
  const payShown = useRef<Record<number, true>>({});
  const findFirst = !!plan && plan.has_consult && plan.channel_timing !== 'before';
  const preChannels = plan && plan.channel_timing === 'before' ? plan.channels : null;

  const refresh = useCallback(async () => {
    try {
      const { data } = await api.get('/doctor-match/current');
      const m: MatchPayload | null = data.data ?? null;
      setMatch(m);
      loadedOnce.current = true;
      setMatchLoaded(true);
      if (m?.ring) showIncomingCall(m.ring);
      // Upfront payment: doctors are only alerted once it is paid.
      if (m && m.status === 'searching' && m.payment?.required && !payShown.current[m.id]) {
        payShown.current[m.id] = true;
        setPayFor(m);
      }
      // Orange practice call: the practice accepted and is calling — open the call (once per call).
      if (m && m.status === 'matched' && m.flow === 'practice') {
        const pc = m.practice_call;
        if (pc?.ringing && pc.call_id && !practiceOpened.current[pc.call_id]) {
          practiceOpened.current[pc.call_id] = true;
          navigation.navigate('PracticeCall', { callId: pc.call_id });
        }
        return m;
      }
      // Doctor accepted: straight to the connection options (once per consult).
      if (m && m.status === 'matched' && m.hub_url && (m.mode === 'video' || m.mode === 'callback') && !hubOpened.current[m.appointment_id]) {
        hubOpened.current[m.appointment_id] = true;
        navigation.navigate('ConsultHub', { appointmentId: m.appointment_id });
      }
      return m;
    } catch {
      setMatchLoaded(true);
      return undefined; /* keep last state */
    }
  }, []);

  useEffect(() => {
    api
      .get('/doctor-match/specialties')
      .then(({ data }) => {
        const list: string[] = data.data ?? [];
        setSpecialties(list);
        setSpecialty((cur) => cur ?? list.find((s) => /general|\bgp\b|family/i.test(s)) ?? list[0] ?? null);
      })
      .catch(() => {});
  }, []);

  useEffect(() => {
    if (route?.params?.mode === 'callback' || route?.params?.mode === 'video') setMode(route.params.mode);
  }, [route?.params?.mode]);

  useEffect(() => {
    if (!level) return;
    let alive = true;
    getScenario(level).then((sc) => {
      if (!alive) return;
      const p = sc?.plan?.has_consult ? sc.plan : null;
      setPlan(p);
      if (p?.specialty) setSpecialty(p.specialty);
      if (p && p.channel_timing === 'before') {
        const ch = p.channels;
        setMode(ch.includes('video') ? 'video' : ch.includes('voice') ? 'voice' : 'callback');
        if (ch.includes('whatsapp') && !ch.includes('phone')) setChannel('whatsapp');
        if (ch.includes('phone') && !ch.includes('whatsapp')) setChannel('phone');
      }
      setPlanLoaded(true);
    });
    return () => {
      alive = false;
    };
  }, [level]);

  // Tapping the colour again (screen still in the stack) must start a new request too.
  useEffect(() => {
    if (route?.params?.auto) autoDone.current = false;
  }, [route?.params?.auto]);

  // Tapping the colour starts the request straight away (no channel question first).
  useEffect(() => {
    if (!route?.params?.auto || autoDone.current || !planLoaded || !findFirst || !matchLoaded) return;
    if (match && (match.status === 'searching' || match.status === 'matched')) {
      autoDone.current = true;
      return;
    }
    autoDone.current = true;
    navigation.setParams?.({ auto: undefined });
    submit({ mode: 'any', level, ...(triageReference ? { triage_reference: triageReference } : {}), ...(plan?.specialty ? { specialty: plan.specialty } : {}) });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [route?.params?.auto, planLoaded, findFirst, match, matchLoaded]);

  // Faster while searching / waiting for the doctor to ring; slower otherwise.
  useFocusEffect(
    useCallback(() => {
      let alive = true;
      const loop = async () => {
        const m = await refresh();
        if (!alive) return;
        const fast = m && (m.status === 'searching' || (m.status === 'matched' && m.mode !== 'in_person'));
        timer.current = setTimeout(loop, fast ? 3000 : 8000);
      };
      loop();
      return () => {
        alive = false;
        if (timer.current) clearTimeout(timer.current);
      };
    }, [refresh]),
  );

  async function submit(body: Record<string, unknown>) {
    setSending(true);
    lastBody.current = body;
    try {
      const { data } = await api.post('/request-doctor', body);
      setMatch(data.match ?? null);
    } catch (e: any) {
      if (e?.response?.data?.match) setMatch(e.response.data.match);
      Alert.alert('Could not request a doctor', apiErrorMessage(e));
    } finally {
      setSending(false);
    }
  }

  async function send() {
    if (findFirst) {
      submit({ mode: 'any', level, ...(triageReference ? { triage_reference: triageReference } : {}), ...(specialty ? { specialty } : {}) });
      return;
    }
    const body: Record<string, unknown> = { mode: mode === 'voice' ? 'video' : mode };
    if (mode === 'voice') body.channel = 'voice';
    if (level) body.level = level;
    if (specialty) body.specialty = specialty;
    if (triageReference) body.triage_reference = triageReference;
    if (mode === 'callback') {
      if (!phone.trim()) {
        Alert.alert('Phone number needed', 'Enter the number the doctor should call.');
        return;
      }
      body.callback_phone = phone.trim();
      body.callback_channel = channel;
      if (note.trim()) body.callback_note = note.trim();
    }
    if (mode === 'in_person') {
      const perm = await Location.requestForegroundPermissionsAsync();
      if (!perm.granted) {
        Alert.alert('Location needed', 'To see a doctor in person we need your location to find the nearest one. You can choose a video consult or a call back instead.');
        return;
      }
      const pos = await Location.getCurrentPositionAsync({});
      body.lat = pos.coords.latitude;
      body.lng = pos.coords.longitude;
    }
    submit(body);
  }

  function cancel() {
    if (!match) return;
    const fee = match.cancel_fee_now > 0 ? ` A cancellation fee of R ${Math.round(match.cancel_fee_now)} applies.` : '';
    Alert.alert('Cancel this request?', `The doctor will be released.${fee}`, [
      { text: 'Keep it', style: 'cancel' },
      {
        text: 'Cancel request',
        style: 'destructive',
        onPress: async () => {
          setCancelling(true);
          try {
            const { data } = await api.post(`/doctor-match/${match.id}/cancel`, {});
            setMatch(data.data ?? null);
          } catch (e) {
            Alert.alert('Could not cancel', apiErrorMessage(e));
          } finally {
            setCancelling(false);
          }
        },
      },
    ]);
  }

  const active = match && (match.status === 'searching' || match.status === 'matched');
  const modeLabel = (m: Mode, p?: MatchPayload | null) =>
    p?.scenario?.channel_timing === 'after' && !p.chosen_channel ? 'You choose video, voice or WhatsApp once a doctor accepts' : m === 'video' ? 'Video consult' : m === 'voice' ? 'Voice call' : m === 'callback' ? 'Call back' : 'In person';
  const dispatchPhone = match?.dispatch_phone || emergency.dispatch_phone;

  function searchingLine(m: MatchPayload) {
    if (m.broadcast !== false && (m.alerted_doctors ?? 0) > 0) {
      return `Sent to ${m.alerted_doctors} available doctor${m.alerted_doctors === 1 ? '' : 's'} — the first to accept will take your consult.`;
    }
    return m.online_doctors > 0 ? 'Alerting available doctors…' : `Waiting for a ${m.specialty} to come online…`;
  }

  function matchedBody(m: MatchPayload) {
    const dr = m.doctor?.name ?? 'Your doctor';
    if (m.channel_choice?.pending) {
      return `${dr} accepted. Choose how you would like to talk: ${m.channel_choice.options.map((o) => o.label.toLowerCase()).join(', ')}.`;
    }
    if (m.channel_choice?.chosen === 'whatsapp') return `WhatsApp ${dr} — tap “Open WhatsApp”. Your consult starts when the doctor confirms you are connected.`;
    if (m.mode === 'callback' && m.callback) {
      const cb = m.callback;
      if (cb.outcome === 'no_answer') return `${dr} tried to call you. Keep your phone close — they will try again on ${cb.phone_display ?? 'your number'}.`;
      if (cb.called_at) return `${dr} is calling you now on ${cb.phone_display ?? 'your phone'} (${cb.channel_label}). Answer your phone.`;
      return `${dr} will call you on ${cb.phone_display ?? 'your phone'} within ${cb.target_minutes} minutes. ${cb.channel_label}. Keep your phone close and the line free.`;
    }
    if (m.mode === 'video') {
      return m.ring
        ? `${dr} is ready — joining your video consult…`
        : `${dr} accepted. Your phone will ring and join the video consult automatically as soon as the doctor is in the room.`;
    }
    return `In person${m.clinic ? ` at ${m.clinic.name}${m.clinic.address ? `, ${m.clinic.address}` : ''}` : ''}`;
  }

  return (
    <ScrollView style={styles.container} contentContainerStyle={{ padding: 16, paddingBottom: 40 }} keyboardShouldPersistTaps="handled">
      <View style={styles.banner}>
        <Text style={styles.bannerEyebrow}>{emergency.eyebrow} · {level === 'virtual' ? 'Virtual consult' : 'Orange'}</Text>
        <Text style={styles.bannerTitle}>Speak to a doctor now</Text>
        <Text style={styles.bannerText}>{plan?.flow === 'practice' ? 'Your request goes to every available GP practice at once — the first to accept calls you in the app and books your visit. No payment in the app.' : 'Your request goes to every available doctor at once — the first to accept takes your consult.'}</Text>
      </View>

      <Pressable onPress={() => Linking.openURL(telUrl(emergency.dispatch_phone))} style={styles.stop}>
        <Text style={styles.stopText}>Not breathing, chest pain or heavy bleeding? Call dispatch: {emergency.dispatch_phone}</Text>
      </Pressable>

      {active && match.status === 'searching' ? (
        <PaCard>
          <Text style={styles.cardTitle}>{match.mode === 'callback' ? 'Finding a doctor to call you…' : 'Finding your doctor…'}</Text>
          <Text style={styles.body}>{searchingLine(match)}</Text>
          <Text style={styles.muted}>
            {match.specialty} · {modeLabel(match.mode, match)}
            {match.seconds_left ? ` · ${Math.ceil(match.seconds_left / 60)} min left` : ''}
          </Text>
          {match.can_cancel ? <PaButton title="Cancel request" variant="ghost" onPress={cancel} loading={cancelling} style={{ marginTop: 12 }} /> : null}
        </PaCard>
      ) : active && match.status === 'matched' && match.flow === 'practice' ? (
        <PaCard style={{ borderLeftWidth: 6, borderLeftColor: pa.go }}>
          <Text style={[styles.cardTitle, { color: pa.go }]}>{match.practice_call?.booked ? 'Booked' : 'Accepted'}</Text>
          <Text style={styles.cardTitle}>{match.practice_call?.practice_name ?? 'A practice'}</Text>
          {match.practice_call?.booked && match.practice_call.booking ? (
            <Text style={[styles.body, { marginTop: 8 }]}>
              {match.practice_call.booking.mode_label} · {match.practice_call.booking.when_label}
              {match.practice_call.booking.doctor_name ? ` · ${match.practice_call.booking.doctor_name}` : ''}
              {match.practice_call.booking.mode === 'in_person' && match.practice_call.booking.practice_address ? `\n${match.practice_call.booking.practice_address}` : ''}
              {`\nRef ${match.practice_call.booking.booking_ref} — in your Appointments.`}
            </Text>
          ) : (
            <Text style={[styles.body, { marginTop: 8 }]}>
              {match.practice_call?.ringing ? 'The practice is calling you in the app now — answer to book your visit.' : 'Open the call to talk to the practice and book your visit.'}
            </Text>
          )}
          {match.practice_call?.call_id ? (
            <PaButton title={match.practice_call.ringing ? 'Answer the call' : 'Open the call'} onPress={() => navigation.navigate('PracticeCall', { callId: match.practice_call!.call_id })} style={{ marginTop: 12 }} />
          ) : null}
          {match.can_cancel && !match.practice_call?.booked ? <PaButton title="Cancel request" variant="ghost" onPress={cancel} loading={cancelling} style={{ marginTop: 8 }} /> : null}
        </PaCard>
      ) : active && match.status === 'matched' ? (
        <PaCard style={{ borderLeftWidth: 6, borderLeftColor: pa.go }}>
          <Text style={[styles.cardTitle, { color: pa.go }]}>Accepted{match.booking_ref ? ` · ${match.booking_ref}` : ''}</Text>
          <Text style={styles.cardTitle}>{match.doctor?.name ?? 'Your doctor'}</Text>
          {match.doctor?.specialization ? <Text style={styles.muted}>{match.doctor.specialization}</Text> : null}
          <Text style={[styles.body, { marginTop: 8 }]}>{matchedBody(match)}</Text>
          {match.hub_url && (match.mode === 'video' || match.mode === 'callback') ? (
            <PaButton
              title={match.channel_choice?.pending ? 'Choose how to talk to the doctor' : 'Open consult: video, voice & chat'}
              onPress={() => navigation.navigate('ConsultHub', { appointmentId: match.appointment_id })}
              style={{ marginTop: 12 }}
            />
          ) : match.mode === 'video' && match.can_join_video ? (
            <PaButton
              title="Join video now"
              onPress={() => navigation.navigate('VideoCall', { appointmentId: match.appointment_id, name: match.doctor?.name })}
              style={{ marginTop: 12 }}
            />
          ) : null}
          {match.channel_choice?.chosen === 'whatsapp' && match.channel_choice.whatsapp_url ? (
            <PaButton title="Open WhatsApp" onPress={() => Linking.openURL(match.channel_choice!.whatsapp_url!)} style={{ marginTop: 8, backgroundColor: '#1FA855' }} />
          ) : null}
          {match.mode === 'in_person' && match.clinic?.directions_url ? (
            <PaButton title="Directions" variant="ghost" onPress={() => Linking.openURL(match.clinic!.directions_url!)} style={{ marginTop: 8 }} />
          ) : null}
          <PaButton title="Message the doctor" variant="ghost" onPress={() => navigation.navigate('ChatList')} style={{ marginTop: 8 }} />
          {match.can_cancel ? <PaButton title="Cancel" variant="ghost" onPress={cancel} loading={cancelling} style={{ marginTop: 8 }} /> : null}
        </PaCard>
      ) : (
        <>
          {match && (match.status === 'expired' || match.status === 'cancelled') && match.outcome_message ? (
            <PaCard style={{ marginBottom: 14 }}>
              <Text style={styles.cardTitle}>{match.status === 'expired' ? 'No doctor available right now' : 'Request cancelled'}</Text>
              <Text style={styles.muted}>{match.outcome_message}</Text>
              {match.status === 'expired' && match.fallback?.action === 'call' && match.fallback.tel ? (
                <PaButton title={match.fallback.label} onPress={() => Linking.openURL(match.fallback!.tel!)} style={{ marginTop: 12, backgroundColor: pa.signal }} />
              ) : null}
              {match.status === 'expired' && match.fallback?.action === 'book' ? (
                <PaButton title={match.fallback.label} onPress={() => (navigation.getParent?.() ?? navigation).navigate('Care', { screen: 'FindCare', params: { group: 'gp' } })} style={{ marginTop: 12 }} />
              ) : null}
              {match.status === 'expired' ? (
                <>
                  <PaButton
                    title={match.fallback?.action === 'retry' ? match.fallback.label : 'Try again'}
                    variant={match.fallback && match.fallback.action !== 'retry' ? 'ghost' : undefined}
                    onPress={() => {
                      const b = lastBody.current ? { ...lastBody.current } : null;
                      if (b && match.fallback?.radius_km && b.mode === 'in_person') b.radius_km = match.fallback.radius_km;
                      if (b) submit(b);
                      else send();
                    }}
                    loading={sending}
                    style={{ marginTop: 12 }}
                  />
                  <PaButton
                    title={`Call dispatch · ${dispatchPhone}`}
                    variant="ghost"
                    onPress={() => Linking.openURL(match.dispatch_tel || telUrl(emergency.dispatch_phone))}
                    style={{ marginTop: 8 }}
                  />
                </>
              ) : null}
              {(match.alternatives ?? []).map((s) => (
                <PaButton
                  key={s.id}
                  title={s.settings?.label || s.label}
                  variant="ghost"
                  onPress={() => {
                    if (!goToTarget(navigation, s.app)) if (s.url) Linking.openURL(s.url);
                  }}
                  style={{ marginTop: 8 }}
                />
              ))}
              <Pressable onPress={() => Linking.openURL(whatsappUrl(emergency.whatsapp_number))} style={styles.wa}>
                <Text style={styles.waText}>💬  {emergency.ask_doctor_label}</Text>
              </Pressable>
            </PaCard>
          ) : null}

          {triageReference ? <Text style={[styles.muted, { marginBottom: 10 }]}>Linked to your triage result {triageReference} — the doctor sees your answers.</Text> : null}

          {findFirst ? (
            <PaCard style={{ marginBottom: 14 }}>
              <Text style={styles.cardTitle}>{sending ? 'Requesting a doctor…' : plan?.flow === 'practice' ? 'We alert every available practice at once' : 'We alert every available doctor at once'}</Text>
              {plan?.flow === 'practice' ? (
                <Text style={styles.body}>The first practice to accept calls you in the app (you tap Accept) and books your visit — in person or by video — on the call. They can also book you a shuttle.</Text>
              ) : (
              <Text style={styles.body}>
                The first doctor to accept takes your consult. Then you choose how to talk
                {plan?.channels?.length ? `: ${plan.channels.map((c) => ({ video: 'video call', voice: 'in-app voice call', whatsapp: 'WhatsApp call', phone: 'phone call-back', chat: 'chat' } as Record<string, string>)[c] ?? c).join(', ')}` : ''}.
              </Text>
              )}
              {plan?.payment && plan?.payment_note ? <Text style={styles.muted}>{plan.payment_note}</Text> : null}
            </PaCard>
          ) : null}
          {findFirst ? null : (<>
          <PaSectionLabel style={{ marginBottom: 8 }}>How should the doctor reach you?</PaSectionLabel>
          {!preChannels || preChannels.includes('video') ? (
          <Pressable onPress={() => setMode('video')} style={[styles.option, mode === 'video' && styles.optionOn]} accessibilityRole="radio" accessibilityState={{ selected: mode === 'video' }}>
            <Text style={[styles.optionTitle, mode === 'video' && { color: '#fff' }]}>📹  Video consult now</Text>
            <Text style={[styles.optionText, mode === 'video' && { color: '#E8E6E1' }]}>The doctor appears on your screen in minutes — your phone rings and joins automatically.</Text>
          </Pressable>
          ) : null}
          {preChannels?.includes('voice') ? (
            <Pressable onPress={() => setMode('voice')} style={[styles.option, mode === 'voice' && styles.optionOn]} accessibilityRole="radio" accessibilityState={{ selected: mode === 'voice' }}>
              <Text style={[styles.optionTitle, mode === 'voice' && { color: '#fff' }]}>🎙  In-app voice call</Text>
              <Text style={[styles.optionText, mode === 'voice' && { color: '#E8E6E1' }]}>Audio only in the app — uses data, not airtime.</Text>
            </Pressable>
          ) : null}
          {!preChannels || preChannels.includes('phone') || preChannels.includes('whatsapp') ? (
          <Pressable onPress={() => setMode('callback')} style={[styles.option, mode === 'callback' && styles.optionOn]} accessibilityRole="radio" accessibilityState={{ selected: mode === 'callback' }}>
            <Text style={[styles.optionTitle, mode === 'callback' && { color: '#fff' }]}>📞  Request a call back</Text>
            <Text style={[styles.optionText, mode === 'callback' && { color: '#E8E6E1' }]}>A doctor phones you (or WhatsApp-calls you) shortly.</Text>
          </Pressable>
          ) : null}
          {!preChannels ? (
          <Pressable onPress={() => setMode('in_person')} style={styles.inPerson}>
            <Text style={[styles.muted, mode === 'in_person' && { color: pa.ink, fontFamily: paFonts.bold }]}>
              {mode === 'in_person' ? '● ' : '○ '}I'd rather go to a doctor's rooms near me (in person)
            </Text>
          </Pressable>
          ) : null}
          </>)}

          {mode === 'callback' ? (
            <PaCard style={{ marginBottom: 14 }}>
              <Text style={styles.label}>Number the doctor should call</Text>
              <TextInput value={phone} onChangeText={setPhone} keyboardType="phone-pad" autoComplete="tel" style={styles.input} placeholder="082 123 4567" maxLength={32} />
              <Text style={[styles.label, { marginTop: 12 }]}>How should they call?</Text>
              <View style={styles.chipRow}>
                {(
                  [
                    ['phone', 'Normal call'],
                    ['whatsapp', 'WhatsApp call'],
                    ['either', 'Either'],
                  ] as [CallbackChannel, string][]
                ).map(([v, l]) => (
                  <Pressable key={v} onPress={() => setChannel(v)} style={[styles.chip, channel === v && styles.chipOn]}>
                    <Text style={[styles.chipText, channel === v && { color: '#fff' }]}>{l}</Text>
                  </Pressable>
                ))}
              </View>
              <Text style={styles.label}>Anything the doctor should know? (optional)</Text>
              <TextInput value={note} onChangeText={setNote} style={[styles.input, { minHeight: 64, textAlignVertical: 'top' }]} multiline maxLength={500} placeholder="e.g. Burn on my hand 20 minutes ago" />
            </PaCard>
          ) : null}

          {specialties.length > 1 ? (
            <>
              <PaSectionLabel style={{ marginBottom: 8 }}>Doctor</PaSectionLabel>
              <View style={styles.chipRow}>
                {specialties.map((s) => (
                  <Pressable key={s} onPress={() => setSpecialty(s)} style={[styles.chip, specialty === s && styles.chipOn]}>
                    <Text style={[styles.chipText, specialty === s && { color: '#fff' }]}>{s}</Text>
                  </Pressable>
                ))}
              </View>
            </>
          ) : null}

          <PaButton
            title={findFirst ? plan?.button_label || 'Request a doctor now' : mode === 'video' ? 'Start video consult now' : mode === 'voice' ? 'Start voice call now' : mode === 'callback' ? 'Request a call back' : 'Request a doctor now'}
            onPress={send}
            loading={sending}
          />
          <Text style={[styles.muted, { marginTop: 8, textAlign: 'center' }]}>You're only billed once a doctor accepts. Free to cancel until then.</Text>
        </>
      )}
      {payFor ? (
        <PaymentSheet
          visible
          payable={{ type: 'consult', id: payFor.id }}
          amount={payFor.payment?.amount ?? payFor.price}
          title="Pay to connect with a doctor"
          onPaid={() => {
            setPayFor(null);
            refresh();
          }}
          onClose={() => setPayFor(null)}
        />
      ) : null}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper },
  banner: { backgroundColor: '#E4701E', borderRadius: 14, padding: 16, marginBottom: 12 },
  bannerEyebrow: { ...type.label, color: '#FFE7D3', marginBottom: 6 },
  bannerTitle: { fontFamily: paFonts.black, fontSize: 20, color: '#fff', marginBottom: 6 },
  bannerText: { fontFamily: paFonts.regular, fontSize: 13, color: '#FFF3EA', lineHeight: 19 },
  stop: { backgroundColor: '#FBEAEA', borderWidth: 1, borderColor: '#F2C4C4', padding: 12, marginBottom: 16, borderRadius: 10 },
  stopText: { fontFamily: paFonts.bold, fontSize: 13, color: pa.sats.red },
  cardTitle: { fontFamily: paFonts.black, fontSize: 16, color: pa.ink, marginBottom: 6 },
  body: { fontFamily: paFonts.regular, fontSize: 14, color: pa.ink, lineHeight: 20, marginBottom: 4 },
  muted: { fontFamily: paFonts.regular, fontSize: 13, color: pa.muted },
  label: { fontFamily: paFonts.bold, fontSize: 12, color: pa.ink, marginBottom: 6 },
  input: { borderWidth: 1, borderColor: pa.line, borderRadius: paRadius.sm, paddingHorizontal: 12, paddingVertical: 10, fontFamily: paFonts.regular, fontSize: 16, color: pa.ink, backgroundColor: pa.surface },
  option: { borderWidth: 1, borderColor: pa.line, borderRadius: 12, padding: 16, marginBottom: 10, backgroundColor: pa.surface },
  optionOn: { backgroundColor: pa.ink, borderColor: pa.ink },
  optionTitle: { fontFamily: paFonts.black, fontSize: 17, color: pa.ink, marginBottom: 4 },
  optionText: { fontFamily: paFonts.regular, fontSize: 13, color: pa.muted, lineHeight: 18 },
  inPerson: { paddingVertical: 8, marginBottom: 12 },
  chipRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginBottom: 14 },
  chip: { borderWidth: 1, borderColor: pa.line, borderRadius: 999, paddingHorizontal: 12, paddingVertical: 8, backgroundColor: pa.surface },
  chipOn: { backgroundColor: pa.ink, borderColor: pa.ink },
  chipText: { fontFamily: paFonts.bold, fontSize: 12, color: pa.ink },
  wa: { marginTop: 10, backgroundColor: '#1FA855', borderRadius: paRadius.sm, paddingVertical: 14, alignItems: 'center' },
  waText: { fontFamily: paFonts.black, fontSize: 15, color: '#fff' },
});
