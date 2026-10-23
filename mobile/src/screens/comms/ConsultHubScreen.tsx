import React, { useCallback, useEffect, useRef, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  AppState,
  FlatList,
  Image,
  KeyboardAvoidingView,
  Linking,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import * as DocumentPicker from 'expo-document-picker';
import * as ImagePicker from 'expo-image-picker';
import { useFocusEffect, useNavigation, useRoute } from '@react-navigation/native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { apiErrorMessage } from '../../api/client';
import { ChatMessage, clockTime, fetchMessages, PickedFile, sendMessage } from '../../api/comms';
import { initials } from '../../components/pa';
import {
  ConsultHub,
  getHub,
  HubCallChannel,
  hubCalling,
  hubConnected,
  hubEnd,
  hubRequestCallback,
  hubStartCall,
} from '../../services/consults';
import { pa, paFonts } from '../../theme';

type Params = { appointmentId: number; autoStart?: HubCallChannel };
type Tab = 'consult' | 'chat' | 'patient';

const STATE_MS = 5000;
const CHAT_MS = 4000;
const MAX_BYTES = 10 * 1024 * 1024;

function fmt(sec: number) {
  const s = Math.max(0, Math.floor(sec));
  const h = Math.floor(s / 3600);
  const m = Math.floor((s % 3600) / 60);
  const r = s % 60;
  const mm = String(m).padStart(2, '0');
  const ss = String(r).padStart(2, '0');
  return h > 0 ? `${h}:${mm}:${ss}` : `${mm}:${ss}`;
}

/**
 * Consult hub — both sides, opened as soon as a doctor accepts (orange
 * instant consult) or from Join / Start on a booked video / call-back
 * consult. In-app first: Video (default) → Voice (in-app, uses data, no
 * phone number exchanged) → Chat; phone / WhatsApp only as fallback.
 * Switching channel keeps the same consult (server ConsultSession) and the
 * consult timer. Doctors also get the patient summary and End consult.
 */
export default function ConsultHubScreen() {
  const insets = useSafeAreaInsets();
  const navigation = useNavigation<any>();
  const { appointmentId, autoStart } = (useRoute<any>().params ?? {}) as Params;

  const [hub, setHub] = useState<ConsultHub | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [tab, setTab] = useState<Tab>('consult');
  const [busy, setBusy] = useState<string | null>(null);
  const [now, setNow] = useState(Date.now());
  const [skew, setSkew] = useState(0);
  const [cbPhone, setCbPhone] = useState('');
  const [outcome, setOutcome] = useState('completed');
  const [notes, setNotes] = useState('');
  const autoDone = useRef(false);
  const focused = useRef(false);

  const apply = useCallback((h: ConsultHub) => {
    setHub(h);
    setError(null);
    if (h.server_time) setSkew(Date.parse(h.server_time) - Date.now());
    setCbPhone((p) => p || (h.callback?.phone_display ?? ''));
  }, []);

  const load = useCallback(async () => {
    try {
      apply(await getHub(appointmentId));
    } catch (e) {
      setError(apiErrorMessage(e, 'Could not open this consult.'));
    }
  }, [appointmentId, apply]);

  // Poll while the screen is focused and the app is in the foreground.
  useFocusEffect(
    useCallback(() => {
      focused.current = true;
      load();
      const t = setInterval(() => {
        if (AppState.currentState === 'active') load();
      }, STATE_MS);
      return () => {
        focused.current = false;
        clearInterval(t);
      };
    }, [load]),
  );

  useEffect(() => {
    const t = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(t);
  }, []);

  useEffect(() => {
    navigation.setOptions?.({ title: hub?.role === 'doctor' ? hub.patient?.name ?? 'Consult' : 'Your consult' });
  }, [navigation, hub?.role, hub?.patient?.name]);

  const callName = hub ? (hub.role === 'doctor' ? hub.patient?.name ?? 'Patient' : hub.doctor.name) : '';

  const startChannel = useCallback(
    async (channel: HubCallChannel, h: ConsultHub | null = hub) => {
      if (!h) return;
      if (!h.channels[channel].available) {
        Alert.alert('Not available yet', h.channels[channel].reason ?? 'This option is not available right now.');
        return;
      }
      if (h.role === 'doctor') {
        setBusy(channel);
        try {
          apply(await hubStartCall(appointmentId, channel)); // rings the patient with this channel
        } catch (e) {
          Alert.alert('Could not start the call', apiErrorMessage(e));
          return;
        } finally {
          setBusy(null);
        }
      }
      navigation.navigate('VideoCall', { appointmentId, audioOnly: channel === 'voice', name: h.role === 'doctor' ? h.patient?.name ?? 'Patient' : h.doctor.name });
    },
    [hub, appointmentId, apply, navigation],
  );

  // Arrived with a channel (doctor accepted a video consult / Join video / Join by voice).
  useEffect(() => {
    if (!hub || autoDone.current || !autoStart || !hub.is_open) return;
    autoDone.current = true;
    if (hub.channels[autoStart].available) startChannel(autoStart, hub);
  }, [hub, autoStart, startChannel]);

  async function run(key: string, fn: () => Promise<ConsultHub>, done?: string) {
    setBusy(key);
    try {
      apply(await fn());
      if (done) Alert.alert(done);
    } catch (e) {
      Alert.alert('Not saved', apiErrorMessage(e));
    } finally {
      setBusy(null);
    }
  }

  function doctorCall(via: 'phone' | 'whatsapp') {
    const url = via === 'phone' ? hub?.fallback?.tel_url : hub?.fallback?.whatsapp_url;
    if (!url) return;
    Linking.openURL(url).catch(() => Alert.alert('Could not open', via === 'whatsapp' ? 'Is WhatsApp installed?' : 'Your phone could not start the call.'));
    hubCalling(appointmentId, via).then(apply).catch(() => undefined);
  }

  function endConsult() {
    const label = hub?.outcomes?.find((o) => o.value === outcome)?.label ?? outcome;
    Alert.alert('End this consult?', `Outcome: ${label}. This closes the consult for you and the patient.`, [
      { text: 'Back', style: 'cancel' },
      { text: 'End consult', style: 'destructive', onPress: () => run('end', () => hubEnd(appointmentId, outcome, notes.trim())) },
    ]);
  }

  if (!hub) {
    return (
      <View style={[styles.center, { paddingBottom: insets.bottom }]}>
        {error ? (
          <>
            <Text style={styles.body}>{error}</Text>
            <Pressable onPress={load} style={[styles.btn, styles.btnInk, { marginTop: 16 }]}>
              <Text style={styles.btnText}>Try again</Text>
            </Pressable>
          </>
        ) : (
          <ActivityIndicator color={pa.ink} />
        )}
      </View>
    );
  }

  const isDoctor = hub.role === 'doctor';
  const connectedAt = hub.consult.connected_at ? Date.parse(hub.consult.connected_at) : null;
  const timer = hub.consult.state === 'ended' ? fmt(hub.consult.connected_seconds) : connectedAt ? fmt((now + skew - connectedAt) / 1000) : '--:--';
  const photo = !isDoctor ? hub.doctor.photo_url : null;
  const sub = isDoctor
    ? [hub.patient?.age != null ? `${hub.patient.age} yrs` : null, hub.patient?.sex, hub.booking_ref].filter(Boolean).join(' · ')
    : [hub.doctor.specialization, hub.doctor.rating_count > 0 ? `★ ${hub.doctor.rating} (${hub.doctor.rating_count})` : null].filter(Boolean).join(' · ');
  const tabs: { key: Tab; label: string }[] = [
    { key: 'consult', label: 'Connect' },
    { key: 'chat', label: 'Chat' },
    ...(isDoctor ? [{ key: 'patient' as Tab, label: 'Patient' }] : []),
  ];

  const header = (
    <View style={styles.head}>
      <View style={styles.headRow}>
        <View style={styles.photo}>
          {photo ? <Image source={{ uri: photo }} style={styles.photoImg} /> : <Text style={styles.photoText}>{initials(callName)}</Text>}
        </View>
        <View style={{ flex: 1, minWidth: 0 }}>
          <Text style={styles.name} numberOfLines={1}>
            {callName}
          </Text>
          {sub ? (
            <Text style={styles.sub} numberOfLines={1}>
              {sub}
            </Text>
          ) : null}
        </View>
        <Text style={styles.timer} accessibilityLabel="Consult time">
          {timer}
        </Text>
      </View>
      <View style={[styles.status, hub.consult.live_call ? styles.statusLive : null, !hub.is_open ? styles.statusClosed : null]}>
        <Text style={[styles.statusText, hub.consult.live_call ? { color: pa.go } : null]} accessibilityLiveRegion="polite">
          {hub.status_text}
        </Text>
      </View>
      <View style={styles.tabs} accessibilityRole="tablist">
        {tabs.map((t) => (
          <Pressable key={t.key} onPress={() => setTab(t.key)} style={[styles.tab, tab === t.key && styles.tabOn]} accessibilityRole="tab" accessibilityState={{ selected: tab === t.key }}>
            <Text style={[styles.tabText, tab === t.key && styles.tabTextOn]}>{t.label}</Text>
          </Pressable>
        ))}
      </View>
    </View>
  );

  if (tab === 'chat') {
    return (
      <View style={styles.flex}>
        {header}
        {hub.channels.chat.available && hub.channels.chat.conversation_id ? (
          <HubChat conversationId={hub.channels.chat.conversation_id} open={hub.is_open} bottomInset={insets.bottom} />
        ) : (
          <View style={styles.center}>
            <Text style={styles.body}>Chat is not available for this consult.</Text>
          </View>
        )}
      </View>
    );
  }

  return (
    <View style={styles.flex}>
      {header}
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: insets.bottom + 24 }} keyboardShouldPersistTaps="handled">
        {tab === 'patient' && hub.patient ? (
          <View style={styles.card}>
            <Row k="Reason" v={hub.patient.reason || '—'} />
            {hub.triage ? <Row k="Triage" v={`${hub.triage.level_label} · ${hub.triage.reference}`} /> : null}
            {hub.triage && (hub.triage.symptoms.length || hub.triage.discriminators.length) ? (
              <Row k="Presenting" v={[...hub.triage.symptoms, ...hub.triage.discriminators].join(', ')} />
            ) : null}
            {hub.triage?.reasons?.length ? <Row k="Why this colour" v={hub.triage.reasons.join('; ')} /> : null}
            {hub.triage?.pregnant ? <Row k="Pregnant" v="Yes" /> : null}
            <Row k="Allergies" v={hub.patient.allergies || 'None recorded'} />
            <Row k="Chronic conditions" v={hub.patient.chronic_conditions || 'None recorded'} />
            <Row k="Medications" v={hub.patient.medications.length ? hub.patient.medications.join(', ') : 'None recorded'} />
            {hub.patient.blood_group ? <Row k="Blood group" v={hub.patient.blood_group} /> : null}
            {hub.patient.emergency_contact ? <Row k="Emergency contact" v={hub.patient.emergency_contact} /> : null}
          </View>
        ) : (
          <>
            <View style={styles.channels}>
              <ChannelButton
                icon="videocam"
                title={isDoctor ? 'Start video' : 'Video call'}
                sub={isDoctor ? 'Rings the patient' : 'Recommended'}
                primary
                disabled={!hub.is_open || !hub.channels.video.available}
                busy={busy === 'video'}
                current={hub.consult.live_call && hub.consult.current_channel === 'video'}
                onPress={() => startChannel('video')}
              />
              <ChannelButton
                icon="call"
                title={isDoctor ? 'Start voice' : 'Voice call'}
                sub="In-app · uses data"
                disabled={!hub.is_open || !hub.channels.voice.available}
                busy={busy === 'voice'}
                current={hub.consult.live_call && hub.consult.current_channel === 'voice'}
                onPress={() => startChannel('voice')}
              />
              <ChannelButton icon="chatbubble-ellipses" title="Chat" sub="Photos & PDFs" disabled={!hub.channels.chat.available} onPress={() => setTab('chat')} />
            </View>
            {!hub.is_open ? (
              <Text style={styles.note}>{hub.consult.outcome_label ? `This consult has ended: ${hub.consult.outcome_label}.` : 'This consult has ended.'}</Text>
            ) : !isDoctor && !hub.channels.video.available && hub.channels.video.reason ? (
              <Text style={styles.note}>{hub.channels.video.reason}</Text>
            ) : hub.ringing && !isDoctor ? (
              <Pressable onPress={() => startChannel(hub.ringing ?? 'video')} style={[styles.btn, styles.btnGo, { marginTop: 12 }]}>
                <Text style={styles.btnText}>Answer {hub.ringing === 'voice' ? 'voice call' : 'video call'}</Text>
              </Pressable>
            ) : null}

            {hub.consult.channels.length ? (
              <View style={[styles.card, { marginTop: 16 }]}>
                <Text style={styles.label}>This consult</Text>
                {hub.consult.channels.map((c, i) => (
                  <Text key={`${c.channel}-${i}`} style={styles.small}>
                    {c.label} · {clockTime(c.started_at)}
                    {c.channel === 'chat' ? '' : ` · ${fmt(c.seconds)}`}
                    {c.ended_at ? '' : ' · now'}
                  </Text>
                ))}
              </View>
            ) : null}

            {/* Fallback: phone / WhatsApp */}
            {hub.is_open ? (
              <View style={[styles.card, { marginTop: 16 }]}>
                <Text style={styles.label}>{isDoctor ? 'Phone / WhatsApp fallback' : 'Connection problems?'}</Text>
                {isDoctor ? (
                  hub.fallback?.tel_url ? (
                    <>
                      <Text style={styles.small}>Use only if the in-app call doesn't work. Patient: {hub.fallback.phone_display}</Text>
                      <View style={styles.row}>
                        <Pressable onPress={() => doctorCall('phone')} style={[styles.btn, styles.btnGo, styles.flex1]}>
                          <Text style={styles.btnText}>Call</Text>
                        </Pressable>
                        <Pressable onPress={() => doctorCall('whatsapp')} style={[styles.btn, styles.btnWa, styles.flex1]}>
                          <Text style={styles.btnText}>WhatsApp</Text>
                        </Pressable>
                      </View>
                      <View style={styles.row}>
                        <Pressable onPress={() => run('cp', () => hubConnected(appointmentId, 'phone'), 'Recorded: connected by phone.')} style={[styles.btn, styles.btnGhost, styles.flex1]} disabled={!!busy}>
                          <Text style={styles.btnGhostText}>Answered (phone)</Text>
                        </Pressable>
                        <Pressable onPress={() => run('cw', () => hubConnected(appointmentId, 'whatsapp'), 'Recorded: connected on WhatsApp.')} style={[styles.btn, styles.btnGhost, styles.flex1]} disabled={!!busy}>
                          <Text style={styles.btnGhostText}>Answered (WhatsApp)</Text>
                        </Pressable>
                      </View>
                      <Text style={styles.small}>{hub.fallback.whatsapp_note}</Text>
                    </>
                  ) : (
                    <Text style={styles.small}>The patient has no phone number on file. Use video, voice or chat.</Text>
                  )
                ) : (
                  <>
                    <Text style={styles.small}>Video and voice work over Wi-Fi or mobile data. If they keep failing, ask the doctor to phone you instead.</Text>
                    <TextInput
                      value={cbPhone}
                      onChangeText={setCbPhone}
                      placeholder="Number the doctor should call"
                      placeholderTextColor={pa.muted2}
                      keyboardType="phone-pad"
                      autoComplete="tel"
                      maxLength={32}
                      style={styles.input}
                      accessibilityLabel="Number the doctor should call"
                    />
                    <View style={styles.row}>
                      <Pressable onPress={() => run('rp', () => hubRequestCallback(appointmentId, 'phone', cbPhone.trim()), 'Your doctor has been asked to phone you. Keep your phone close.')} style={[styles.btn, styles.btnGhost, styles.flex1]} disabled={!!busy}>
                        <Text style={styles.btnGhostText}>Doctor, phone me</Text>
                      </Pressable>
                      <Pressable onPress={() => run('rw', () => hubRequestCallback(appointmentId, 'whatsapp', cbPhone.trim()), 'Your doctor has been asked to WhatsApp-call you.')} style={[styles.btn, styles.btnGhost, styles.flex1]} disabled={!!busy}>
                        <Text style={styles.btnGhostText}>WhatsApp call me</Text>
                      </Pressable>
                    </View>
                    {hub.emergency?.tel_url ? (
                      <Pressable onPress={() => Linking.openURL(hub.emergency!.tel_url!)}>
                        <Text style={[styles.small, { marginTop: 8 }]}>
                          Getting worse? Call emergency services: <Text style={{ fontFamily: paFonts.bold, color: pa.signal }}>{hub.emergency.phone}</Text> or 112.
                        </Text>
                      </Pressable>
                    ) : null}
                  </>
                )}
              </View>
            ) : null}

            {/* Doctor: end consult */}
            {isDoctor && hub.can_end ? (
              <View style={[styles.card, { marginTop: 16 }]}>
                <Text style={styles.label}>End consult</Text>
                {(hub.outcomes ?? []).map((o) => (
                  <Pressable key={o.value} onPress={() => setOutcome(o.value)} style={styles.radio} accessibilityRole="radio" accessibilityState={{ checked: outcome === o.value }}>
                    <Ionicons name={outcome === o.value ? 'radio-button-on' : 'radio-button-off'} size={20} color={outcome === o.value ? pa.ink : pa.muted} />
                    <Text style={[styles.body, { flex: 1 }]}>{o.label}</Text>
                  </Pressable>
                ))}
                <TextInput
                  value={notes}
                  onChangeText={setNotes}
                  placeholder="Notes (optional, kept on the consult record)"
                  placeholderTextColor={pa.muted2}
                  multiline
                  maxLength={2000}
                  style={[styles.input, { minHeight: 64, textAlignVertical: 'top' }]}
                />
                <Pressable onPress={endConsult} style={[styles.btn, styles.btnEnd, { marginTop: 10 }]} disabled={busy === 'end'}>
                  {busy === 'end' ? <ActivityIndicator color="#fff" /> : <Text style={styles.btnText}>End consult</Text>}
                </Pressable>
              </View>
            ) : null}
          </>
        )}
      </ScrollView>
    </View>
  );
}

function Row({ k, v }: { k: string; v: string }) {
  return (
    <View style={{ marginBottom: 10 }}>
      <Text style={styles.label}>{k}</Text>
      <Text style={styles.body}>{v}</Text>
    </View>
  );
}

function ChannelButton({
  icon,
  title,
  sub,
  onPress,
  disabled,
  busy,
  current,
  primary,
}: {
  icon: keyof typeof Ionicons.glyphMap;
  title: string;
  sub: string;
  onPress: () => void;
  disabled?: boolean;
  busy?: boolean;
  current?: boolean;
  primary?: boolean;
}) {
  return (
    <Pressable
      onPress={onPress}
      disabled={disabled || busy}
      accessibilityRole="button"
      accessibilityLabel={`${title}. ${sub}`}
      accessibilityState={{ disabled: !!disabled, selected: !!current }}
      style={({ pressed }) => [styles.ch, primary && styles.chPrimary, current && styles.chCurrent, disabled && { opacity: 0.45 }, pressed && { opacity: 0.75 }]}
    >
      {busy ? <ActivityIndicator color={primary ? '#fff' : pa.ink} /> : <Ionicons name={icon} size={24} color={primary ? '#fff' : pa.ink} />}
      <Text style={[styles.chTitle, primary && { color: '#fff' }]} numberOfLines={1}>
        {title}
      </Text>
      <Text style={[styles.chSub, primary && { color: 'rgba(255,255,255,0.85)' }]} numberOfLines={1}>
        {sub}
      </Text>
    </Pressable>
  );
}

/** Live chat inside the hub (same thread as the Messages screen; 4 s poll; photos / PDFs). */
function HubChat({ conversationId, open, bottomInset }: { conversationId: number; open: boolean; bottomInset: number }) {
  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [text, setText] = useState('');
  const [file, setFile] = useState<PickedFile | null>(null);
  const [sending, setSending] = useState(false);
  const lastId = useRef(0);
  const listRef = useRef<FlatList<ChatMessage>>(null);

  const merge = useCallback((list: ChatMessage[]) => {
    if (!list.length) return;
    setMessages((prev) => {
      const seen = new Set(prev.map((m) => m.id));
      const next = [...prev, ...list.filter((m) => !seen.has(m.id))];
      lastId.current = Math.max(lastId.current, ...next.map((m) => m.id));
      return next;
    });
    setTimeout(() => listRef.current?.scrollToEnd({ animated: true }), 50);
  }, []);

  useFocusEffect(
    useCallback(() => {
      let alive = true;
      const tick = async () => {
        if (AppState.currentState !== 'active') return;
        try {
          const res = await fetchMessages(conversationId, lastId.current ? { after: lastId.current } : {});
          if (alive) merge(res.data);
        } catch {
          /* keep what we have */
        }
      };
      tick();
      const t = setInterval(tick, CHAT_MS);
      return () => {
        alive = false;
        clearInterval(t);
      };
    }, [conversationId, merge]),
  );

  function accept(f: PickedFile) {
    if (f.size && f.size > MAX_BYTES) {
      Alert.alert('File too big', 'Images or PDF up to 10 MB.');
      return;
    }
    setFile(f);
  }

  async function pickPhoto() {
    const perm = await ImagePicker.requestMediaLibraryPermissionsAsync();
    if (!perm.granted) return;
    const res = await ImagePicker.launchImageLibraryAsync({ mediaTypes: ['images'], quality: 0.7 });
    if (res.canceled || !res.assets?.length) return;
    const a = res.assets[0];
    const mime = a.mimeType ?? 'image/jpeg';
    accept({ uri: a.uri, name: a.fileName ?? `photo-${Date.now()}.${mime.split('/')[1] ?? 'jpg'}`, mimeType: mime, size: a.fileSize ?? null });
  }

  async function pickDocument() {
    const res = await DocumentPicker.getDocumentAsync({ type: ['application/pdf', 'image/*'], copyToCacheDirectory: true });
    if (res.canceled || !res.assets?.length) return;
    const d = res.assets[0];
    accept({ uri: d.uri, name: d.name, mimeType: d.mimeType ?? 'application/pdf', size: d.size ?? null });
  }

  async function send() {
    if (sending || (!text.trim() && !file)) return;
    setSending(true);
    try {
      const m = await sendMessage(conversationId, text, file);
      setText('');
      setFile(null);
      merge([m]);
    } catch (e) {
      Alert.alert('Not sent', apiErrorMessage(e));
    } finally {
      setSending(false);
    }
  }

  return (
    <KeyboardAvoidingView style={styles.flex} behavior={Platform.OS === 'ios' ? 'padding' : undefined} keyboardVerticalOffset={Platform.OS === 'ios' ? 100 : 0}>
      <FlatList
        ref={listRef}
        data={messages}
        keyExtractor={(m) => String(m.id)}
        contentContainerStyle={{ padding: 16, gap: 8, flexGrow: 1 }}
        onContentSizeChange={() => listRef.current?.scrollToEnd({ animated: false })}
        ListEmptyComponent={<Text style={[styles.small, { textAlign: 'center', marginTop: 24 }]}>No messages yet. You can also send a photo or PDF.</Text>}
        renderItem={({ item: m }) => (
          <View style={[styles.bubble, m.is_mine ? styles.bubbleMine : null]}>
            {m.body ? <Text style={[styles.body, m.is_mine ? { color: '#fff' } : null]}>{m.body}</Text> : null}
            {m.attachment?.url ? (
              <Pressable onPress={() => Linking.openURL(m.attachment!.url!)}>
                {m.attachment.is_image ? (
                  <Image source={{ uri: m.attachment.url }} style={styles.attImg} />
                ) : (
                  <Text style={[styles.body, { fontFamily: paFonts.bold }, m.is_mine ? { color: '#fff' } : null]}>📎 {m.attachment.name ?? 'Attachment'}</Text>
                )}
              </Pressable>
            ) : null}
            <Text style={[styles.time, m.is_mine ? { color: 'rgba(255,255,255,0.7)' } : null]}>{clockTime(m.created_at)}</Text>
          </View>
        )}
      />
      {file ? (
        <View style={styles.chip}>
          <Text style={[styles.small, { flex: 1 }]} numberOfLines={1}>
            Attached: {file.name}
          </Text>
          <Pressable onPress={() => setFile(null)} hitSlop={10} accessibilityLabel="Remove attachment">
            <Ionicons name="close-circle" size={20} color={pa.muted} />
          </Pressable>
        </View>
      ) : null}
      <View style={[styles.compose, { paddingBottom: bottomInset + 8 }]}>
        <Pressable
          onPress={() =>
            Alert.alert('Attach', 'Images or PDF, up to 10 MB', [
              { text: 'Photo', onPress: pickPhoto },
              { text: 'Document (PDF)', onPress: pickDocument },
              { text: 'Cancel', style: 'cancel' },
            ])
          }
          style={styles.iconBtn}
          disabled={!open}
          accessibilityLabel="Attach a photo or PDF"
        >
          <Ionicons name="attach" size={22} color={pa.ink} />
        </Pressable>
        <TextInput value={text} onChangeText={setText} placeholder="Type a message…" placeholderTextColor={pa.muted2} multiline maxLength={5000} style={styles.composeInput} editable={open} />
        <Pressable onPress={send} style={[styles.iconBtn, styles.sendBtn]} disabled={sending || !open} accessibilityLabel="Send">
          {sending ? <ActivityIndicator color="#fff" /> : <Ionicons name="send" size={18} color="#fff" />}
        </Pressable>
      </View>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1, backgroundColor: pa.paper },
  flex1: { flex: 1 },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 24, backgroundColor: pa.paper },
  head: { backgroundColor: pa.surface, paddingHorizontal: 16, paddingTop: 12, borderBottomWidth: 1, borderBottomColor: pa.line },
  headRow: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  photo: { width: 48, height: 48, borderRadius: 24, backgroundColor: pa.lineSoft, alignItems: 'center', justifyContent: 'center', overflow: 'hidden' },
  photoImg: { width: '100%', height: '100%' },
  photoText: { fontFamily: paFonts.black, fontSize: 18, color: pa.ink },
  name: { fontFamily: paFonts.black, fontSize: 18, color: pa.ink },
  sub: { fontFamily: paFonts.regular, fontSize: 13, color: pa.muted },
  timer: { fontFamily: paFonts.black, fontSize: 15, color: pa.ink, fontVariant: ['tabular-nums'], borderWidth: 1, borderColor: pa.line, borderRadius: 999, paddingHorizontal: 10, paddingVertical: 4 },
  status: { marginTop: 10, backgroundColor: pa.lineSoft, borderRadius: 12, paddingHorizontal: 12, paddingVertical: 8 },
  statusLive: { backgroundColor: pa.goWash },
  statusClosed: { backgroundColor: pa.lineSoft },
  statusText: { fontFamily: paFonts.bold, fontSize: 14, color: pa.ink },
  tabs: { flexDirection: 'row', gap: 6, marginTop: 10 },
  tab: { flex: 1, minHeight: 44, alignItems: 'center', justifyContent: 'center', borderBottomWidth: 3, borderBottomColor: 'transparent' },
  tabOn: { borderBottomColor: pa.ink },
  tabText: { fontFamily: paFonts.bold, fontSize: 14, color: pa.muted },
  tabTextOn: { color: pa.ink },
  channels: { flexDirection: 'row', gap: 8 },
  ch: { flex: 1, minHeight: 84, borderRadius: 14, borderWidth: 2, borderColor: pa.line, backgroundColor: pa.surface, alignItems: 'center', justifyContent: 'center', paddingVertical: 10, paddingHorizontal: 4, gap: 3 },
  chPrimary: { backgroundColor: pa.go, borderColor: pa.go },
  chCurrent: { borderColor: pa.ink, borderWidth: 3 },
  chTitle: { fontFamily: paFonts.black, fontSize: 14, color: pa.ink },
  chSub: { fontFamily: paFonts.regular, fontSize: 11, color: pa.muted },
  note: { fontFamily: paFonts.regular, fontSize: 14, color: pa.muted, marginTop: 12 },
  card: { backgroundColor: pa.surface, borderRadius: 14, borderWidth: 1, borderColor: pa.line, padding: 14 },
  label: { fontFamily: paFonts.black, fontSize: 11, letterSpacing: 1.2, textTransform: 'uppercase', color: pa.muted, marginBottom: 6 },
  body: { fontFamily: paFonts.regular, fontSize: 15, lineHeight: 21, color: pa.ink },
  small: { fontFamily: paFonts.regular, fontSize: 13, lineHeight: 18, color: pa.muted },
  row: { flexDirection: 'row', gap: 8, marginTop: 10 },
  btn: { minHeight: 48, borderRadius: 12, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 12 },
  btnInk: { backgroundColor: pa.ink },
  btnGo: { backgroundColor: pa.go },
  btnWa: { backgroundColor: '#1FA855' },
  btnEnd: { backgroundColor: pa.signal },
  btnGhost: { backgroundColor: pa.lineSoft },
  btnText: { fontFamily: paFonts.black, fontSize: 15, color: '#fff' },
  btnGhostText: { fontFamily: paFonts.bold, fontSize: 14, color: pa.ink, textAlign: 'center' },
  input: { borderWidth: 1, borderColor: pa.line, borderRadius: 10, paddingHorizontal: 12, paddingVertical: 10, fontFamily: paFonts.regular, fontSize: 15, color: pa.ink, marginTop: 10, backgroundColor: pa.surface },
  radio: { flexDirection: 'row', alignItems: 'center', gap: 10, minHeight: 44 },
  bubble: { maxWidth: '85%', alignSelf: 'flex-start', backgroundColor: pa.surface, borderRadius: 14, paddingHorizontal: 12, paddingVertical: 8, borderWidth: 1, borderColor: pa.line },
  bubbleMine: { alignSelf: 'flex-end', backgroundColor: pa.ink, borderColor: pa.ink },
  attImg: { width: 200, height: 200, borderRadius: 10, marginTop: 4 },
  time: { fontFamily: paFonts.regular, fontSize: 11, color: pa.muted, marginTop: 3 },
  chip: { flexDirection: 'row', alignItems: 'center', gap: 8, paddingHorizontal: 16, paddingVertical: 6, backgroundColor: pa.surface },
  compose: { flexDirection: 'row', alignItems: 'flex-end', gap: 8, paddingHorizontal: 12, paddingTop: 8, backgroundColor: pa.surface, borderTopWidth: 1, borderTopColor: pa.line },
  composeInput: { flex: 1, minHeight: 44, maxHeight: 120, borderWidth: 1, borderColor: pa.line, borderRadius: 12, paddingHorizontal: 12, paddingVertical: 10, fontFamily: paFonts.regular, fontSize: 15, color: pa.ink },
  iconBtn: { width: 44, height: 44, borderRadius: 12, alignItems: 'center', justifyContent: 'center', backgroundColor: pa.lineSoft },
  sendBtn: { backgroundColor: pa.go },
});
