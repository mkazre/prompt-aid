import React, { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Alert, Linking, Pressable, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect, useNavigation, useRoute } from '@react-navigation/native';
import { apiErrorMessage } from '../../api/client';
import { ConsultCard, getConsultCard, markCalling, recordOutcome, switchToVideo, CallOutcome } from '../../services/consults';
import { pa, paFonts, paRadius } from '../../theme';

type Params = { appointmentId: number };

const LEVEL_COLOURS: Record<string, string> = { red: '#D0211C', orange: '#E4701E', yellow: '#D9A400', green: '#1E8E3E' };

function hhmm(iso?: string | null) {
  if (!iso) return '';
  const d = new Date(iso);
  return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
}

/**
 * Doctor's call-back consult card (orange triage "request a call back", or
 * a booked "call back" appointment): patient + triage summary, the number,
 * Call (tel:) / WhatsApp (wa.me — opens the chat; WhatsApp has no link that
 * starts a call directly, so tap the call icon there), "Start video
 * instead" and the outcome buttons. Every tap is recorded for SLA reports.
 */
export default function ConsultCardScreen() {
  const navigation = useNavigation<any>();
  const { appointmentId } = useRoute<any>().params as Params;
  const [card, setCard] = useState<ConsultCard | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setCard(await getConsultCard(appointmentId));
    } catch (e) {
      Alert.alert('Could not open the consult', apiErrorMessage(e));
    } finally {
      setLoading(false);
    }
  }, [appointmentId]);

  useEffect(() => {
    navigation.setOptions?.({ title: 'Call-back consult' });
  }, [navigation]);

  useFocusEffect(
    useCallback(() => {
      load();
    }, [load]),
  );

  async function run(key: string, fn: () => Promise<ConsultCard>, done?: string) {
    setBusy(key);
    try {
      const c = await fn();
      setCard(c);
      if (done) Alert.alert(done);
    } catch (e) {
      Alert.alert('Not saved', apiErrorMessage(e));
    } finally {
      setBusy(null);
    }
  }

  function call(via: 'phone' | 'whatsapp') {
    const cb = card?.callback;
    const url = via === 'phone' ? cb?.tel_url : cb?.whatsapp_url;
    if (!url) return;
    Linking.openURL(url).catch(() => Alert.alert('Could not open', via === 'whatsapp' ? 'Is WhatsApp installed?' : 'Your phone could not start the call.'));
    markCalling(appointmentId, via).then(setCard).catch(() => {});
  }

  function outcome(o: CallOutcome) {
    const labels: Record<CallOutcome, [string, string]> = {
      completed: ['Mark consult done?', 'The consult is closed and you become available again.'],
      no_answer: ['No answer?', 'The patient is told you will try again.'],
      escalated: ['Advise an ambulance?', 'The patient is told to call an ambulance now and the consult is closed.'],
    };
    Alert.alert(labels[o][0], labels[o][1], [
      { text: 'Back', style: 'cancel' },
      { text: 'Confirm', style: o === 'escalated' ? 'destructive' : 'default', onPress: () => run(o, () => recordOutcome(appointmentId, o)) },
    ]);
  }

  function startVideo() {
    Alert.alert('Start a video consult instead?', "The patient's phone will ring in the Prompt Aid app.", [
      { text: 'Back', style: 'cancel' },
      {
        text: 'Start video',
        onPress: () =>
          run('video', async () => {
            const c = await switchToVideo(appointmentId);
            navigation.navigate('VideoCall', { appointmentId, name: c.patient.name });
            return c;
          }),
      },
    ]);
  }

  if (loading && !card) {
    return (
      <View style={styles.center}>
        <ActivityIndicator color={pa.signal} />
      </View>
    );
  }
  if (!card) {
    return (
      <View style={styles.center}>
        <Text style={styles.muted}>This consult could not be loaded.</Text>
      </View>
    );
  }

  const cb = card.callback;
  const t = card.triage;
  const who = [card.patient.age != null ? `${card.patient.age} yrs` : null, card.patient.sex].filter(Boolean).join(' · ');
  const presenting = t ? [...t.symptoms, ...t.discriminators] : [];

  return (
    <ScrollView style={styles.container} contentContainerStyle={{ padding: 16, paddingBottom: 48 }} refreshControl={<RefreshControl refreshing={false} onRefresh={load} />}>
      {!card.is_open ? (
        <View style={[styles.box, { backgroundColor: '#F3F1EC' }]}>
          <Text style={styles.boxText}>
            This consult is closed ({card.status.replace(/_/g, ' ')}
            {cb?.outcome ? ` · ${cb.outcome.replace(/_/g, ' ')}` : ''}).
          </Text>
        </View>
      ) : null}

      <View style={styles.box}>
        <Text style={styles.label}>
          {card.mode === 'callback' ? 'Call back' : card.mode === 'video' ? 'Video consult' : 'In person'}
          {card.booking_ref ? ` · ${card.booking_ref}` : ''}
        </Text>
        <Text style={styles.name}>{card.patient.name}</Text>
        {who ? <Text style={styles.muted}>{who}</Text> : null}
        {t ? (
          <View style={{ marginTop: 12 }}>
            <View style={[styles.level, { backgroundColor: LEVEL_COLOURS[t.level] ?? pa.muted }]}>
              <Text style={styles.levelText}>{t.level_label}</Text>
            </View>
            {presenting.length ? <Text style={styles.body}>Presenting: {presenting.join(', ')}</Text> : null}
            {t.reasons.length ? <Text style={styles.body}>Why: {t.reasons.join('; ')}</Text> : null}
            {t.pregnant ? <Text style={styles.body}>Pregnant</Text> : null}
            <Text style={styles.small}>Triage {t.reference}</Text>
          </View>
        ) : null}
        {card.patient.allergies ? <Text style={styles.body}>Allergies: {card.patient.allergies}</Text> : null}
        {card.patient.chronic_conditions ? <Text style={styles.body}>Chronic: {card.patient.chronic_conditions}</Text> : null}
      </View>

      {cb ? (
        <View style={styles.box}>
          <Text style={styles.label}>Call the patient</Text>
          <Text style={styles.phone}>{cb.phone_display ?? 'No number on file'}</Text>
          <Text style={styles.body}>
            Prefers: {cb.channel_label}
            {cb.due_at ? ` · call by ${hhmm(cb.due_at)}` : ''}
            {cb.overdue ? ' (overdue)' : ''}
          </Text>
          {cb.note ? <Text style={styles.note}>Patient's note: {cb.note}</Text> : null}

          {card.is_open && cb.tel_url ? (
            <>
              <View style={styles.row}>
                <Pressable style={[styles.btn, { backgroundColor: '#1E8E3E' }]} onPress={() => call('phone')}>
                  <Text style={styles.btnText}>Call</Text>
                </Pressable>
                <Pressable style={[styles.btn, { backgroundColor: '#1FA855' }]} onPress={() => call('whatsapp')}>
                  <Text style={styles.btnText}>WhatsApp</Text>
                </Pressable>
              </View>
              <Text style={styles.small}>{cb.whatsapp_note}</Text>
              {card.can_start_video ? (
                <Pressable style={[styles.btn, { backgroundColor: pa.ink, marginTop: 10 }]} onPress={startVideo} disabled={busy === 'video'}>
                  <Text style={styles.btnText}>{busy === 'video' ? 'Starting…' : 'Start video instead'}</Text>
                </Pressable>
              ) : null}
            </>
          ) : null}

          <Text style={[styles.small, { marginTop: 12 }]}>
            {cb.called_at
              ? `First call ${hhmm(cb.called_at)} · ${cb.call_attempts} attempt${cb.call_attempts === 1 ? '' : 's'}`
              : `Not called yet — the patient expects your call within ${cb.target_minutes} minutes.`}
            {cb.outcome ? ` · last outcome: ${cb.outcome.replace(/_/g, ' ')}` : ''}
          </Text>
        </View>
      ) : null}

      {cb && card.is_open ? (
        <View style={styles.box}>
          <Text style={styles.label}>After the call</Text>
          <Pressable style={[styles.btn, { backgroundColor: '#1E8E3E' }]} onPress={() => outcome('completed')} disabled={!!busy}>
            <Text style={styles.btnText}>Called — consult done</Text>
          </Pressable>
          <Pressable style={[styles.btn, { backgroundColor: '#F3F1EC', marginTop: 8 }]} onPress={() => outcome('no_answer')} disabled={!!busy}>
            <Text style={[styles.btnText, { color: pa.ink }]}>No answer — retry</Text>
          </Pressable>
          <Pressable style={[styles.btn, { backgroundColor: '#D0211C', marginTop: 8 }]} onPress={() => outcome('escalated')} disabled={!!busy}>
            <Text style={styles.btnText}>Escalate: advise ambulance</Text>
          </Pressable>
        </View>
      ) : null}

      {card.mode === 'video' && card.is_open ? (
        <Pressable style={[styles.btn, { backgroundColor: '#1E8E3E' }]} onPress={() => navigation.navigate('VideoCall', { appointmentId, name: card.patient.name })}>
          <Text style={styles.btnText}>Open the video call</Text>
        </Pressable>
      ) : null}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: pa.paper },
  box: { backgroundColor: pa.surface, borderRadius: 16, borderWidth: 1, borderColor: pa.line, padding: 16, marginBottom: 14 },
  boxText: { fontFamily: paFonts.bold, fontSize: 14, color: pa.ink },
  label: { fontFamily: paFonts.black, fontSize: 11, letterSpacing: 0.8, textTransform: 'uppercase', color: pa.muted, marginBottom: 4 },
  name: { fontFamily: paFonts.black, fontSize: 22, color: pa.ink },
  muted: { fontFamily: paFonts.regular, fontSize: 14, color: pa.muted },
  body: { fontFamily: paFonts.regular, fontSize: 14, color: pa.ink, marginTop: 6, lineHeight: 20 },
  small: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted, marginTop: 6, lineHeight: 17 },
  note: { fontFamily: paFonts.regular, fontSize: 14, color: pa.ink, backgroundColor: '#FFF7E8', borderRadius: 10, padding: 10, marginTop: 10 },
  level: { alignSelf: 'flex-start', borderRadius: 999, paddingHorizontal: 12, paddingVertical: 5 },
  levelText: { color: '#fff', fontFamily: paFonts.black, fontSize: 12 },
  phone: { fontFamily: paFonts.black, fontSize: 28, color: pa.ink, letterSpacing: 0.5 },
  row: { flexDirection: 'row', gap: 10, marginTop: 14 },
  btn: { flex: 1, minHeight: 52, borderRadius: paRadius.sm, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 12 },
  btnText: { color: '#fff', fontFamily: paFonts.black, fontSize: 16 },
});
