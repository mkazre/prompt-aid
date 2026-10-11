import React, { useCallback, useMemo, useRef, useState } from 'react';
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
import {
  ChatMessage,
  clockTime,
  Conversation,
  fetchConversation,
  fetchMessages,
  PickedFile,
  sendMessage,
  startConversation,
} from '../../api/comms';
import { initials } from '../../components/pa';
import { pa, paFonts } from '../../theme';

const POLL_MS = 4000;
const MAX_BYTES = 10 * 1024 * 1024;

type Params = { conversationId?: number; appointmentId?: number; title?: string };

/**
 * One doctor–patient thread. Polls for new messages every 4s while the
 * screen is focused and the app is in the foreground (stops on blur).
 * Sends are optimistic: the bubble appears immediately and is reconciled
 * with the server copy (or marked failed → tap to retry).
 */
export default function ChatScreen() {
  const insets = useSafeAreaInsets();
  const navigation = useNavigation<any>();
  const params = (useRoute<any>().params ?? {}) as Params;

  const [conversation, setConversation] = useState<Conversation | null>(null);
  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [text, setText] = useState('');
  const [file, setFile] = useState<PickedFile | null>(null);

  const convId = useRef<number | null>(params.conversationId ?? null);
  const lastId = useRef(0);
  const polling = useRef(false);

  const mergeIncoming = useCallback((incoming: ChatMessage[], lastReadOwnId: number | null) => {
    setMessages((prev) => {
      const byId = new Map<number, ChatMessage>();
      prev.forEach((m) => byId.set(m.id, m));
      incoming.forEach((m) => byId.set(m.id, m));
      let next = Array.from(byId.values()).sort((a, b) => (a.pending ? 1 : 0) - (b.pending ? 1 : 0) || a.id - b.id);
      if (lastReadOwnId) {
        next = next.map((m) => (m.is_mine && !m.read_at && m.id > 0 && m.id <= lastReadOwnId ? { ...m, read_at: new Date().toISOString() } : m));
      }
      return next;
    });
    incoming.forEach((m) => {
      if (m.id > lastId.current) lastId.current = m.id;
    });
  }, []);

  const initialLoad = useCallback(async () => {
    try {
      let conv: Conversation;
      if (convId.current) {
        conv = await fetchConversation(convId.current);
      } else if (params.appointmentId) {
        conv = await startConversation({ appointment_id: params.appointmentId });
        convId.current = conv.id;
      } else {
        throw new Error('No conversation');
      }
      setConversation(conv);
      const res = await fetchMessages(conv.id);
      lastId.current = 0;
      setMessages([]);
      mergeIncoming(res.data, res.last_read_own_id);
      setError(null);
    } catch (e) {
      setError(apiErrorMessage(e, 'Could not open this conversation.'));
    } finally {
      setLoading(false);
    }
  }, [params.appointmentId, mergeIncoming]);

  const poll = useCallback(async () => {
    if (!convId.current || polling.current || AppState.currentState !== 'active') return;
    polling.current = true;
    try {
      const res = await fetchMessages(convId.current, { after: lastId.current || undefined });
      mergeIncoming(res.data, res.last_read_own_id);
    } catch {
      // transient — next tick retries
    } finally {
      polling.current = false;
    }
  }, [mergeIncoming]);

  useFocusEffect(
    useCallback(() => {
      if (!conversation) {
        initialLoad();
      } else {
        poll();
      }
      const t = setInterval(poll, POLL_MS);
      return () => clearInterval(t);
      // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [conversation?.id]),
  );

  async function doSend(body: string, attachment: PickedFile | null, localKey?: string) {
    if (!convId.current) return;
    const key = localKey ?? `local-${Date.now()}`;
    const optimistic: ChatMessage = {
      id: -Date.now(),
      conversation_id: convId.current,
      sender_user_id: 0,
      sender_name: null,
      is_mine: true,
      body: body.trim() || null,
      attachment: attachment
        ? { name: attachment.name, mime: attachment.mimeType, size: attachment.size ?? null, is_image: attachment.mimeType.startsWith('image/'), url: attachment.uri }
        : null,
      read_at: null,
      created_at: new Date().toISOString(),
      pending: true,
      localKey: key,
    };
    setMessages((prev) => [...prev.filter((m) => m.localKey !== key), optimistic]);

    try {
      const saved = await sendMessage(convId.current, body, attachment);
      setMessages((prev) => {
        const without = prev.filter((m) => m.localKey !== key && m.id !== saved.id);
        return [...without, saved].sort((a, b) => (a.pending ? 1 : 0) - (b.pending ? 1 : 0) || a.id - b.id);
      });
      if (saved.id > lastId.current) lastId.current = saved.id;
    } catch (e) {
      setMessages((prev) => prev.map((m) => (m.localKey === key ? { ...m, pending: false, failed: true } : m)));
      Alert.alert('Message not sent', apiErrorMessage(e, 'Check your connection and tap the message to retry.'));
    }
  }

  function onSend() {
    if (!text.trim() && !file) return;
    if (conversation && !conversation.can_send) return;
    const body = text;
    const attachment = file;
    setText('');
    setFile(null);
    doSend(body, attachment);
  }

  function retry(m: ChatMessage) {
    if (!m.failed) return;
    const attachment = m.attachment ? { uri: m.attachment.url, name: m.attachment.name, mimeType: m.attachment.mime ?? 'application/octet-stream', size: m.attachment.size } : null;
    doSend(m.body ?? '', attachment, m.localKey);
  }

  function acceptFile(f: PickedFile) {
    if (f.size && f.size > MAX_BYTES) {
      Alert.alert('File too large', 'Attachments can be at most 10 MB.');
      return;
    }
    setFile(f);
  }

  async function pickPhoto(fromCamera: boolean) {
    const perm = fromCamera ? await ImagePicker.requestCameraPermissionsAsync() : await ImagePicker.requestMediaLibraryPermissionsAsync();
    if (!perm.granted) {
      Alert.alert('Permission needed', fromCamera ? 'Allow camera access to take a photo.' : 'Allow photo access to choose an image.');
      return;
    }
    const opts: ImagePicker.ImagePickerOptions = { mediaTypes: ['images'], quality: 0.7 };
    const res = fromCamera ? await ImagePicker.launchCameraAsync(opts) : await ImagePicker.launchImageLibraryAsync(opts);
    if (res.canceled || !res.assets?.length) return;
    const a = res.assets[0];
    const mime = a.mimeType ?? 'image/jpeg';
    acceptFile({ uri: a.uri, name: a.fileName ?? `photo-${Date.now()}.${mime.split('/')[1] ?? 'jpg'}`, mimeType: mime, size: a.fileSize ?? null });
  }

  async function pickDocument() {
    const res = await DocumentPicker.getDocumentAsync({ type: ['application/pdf', 'image/*'], copyToCacheDirectory: true });
    if (res.canceled || !res.assets?.length) return;
    const d = res.assets[0];
    acceptFile({ uri: d.uri, name: d.name, mimeType: d.mimeType ?? 'application/pdf', size: d.size ?? null });
  }

  function openAttachMenu() {
    Alert.alert('Attach', 'Images or PDF, up to 10 MB', [
      { text: 'Photo library', onPress: () => pickPhoto(false) },
      { text: 'Take photo', onPress: () => pickPhoto(true) },
      { text: 'Document (PDF)', onPress: pickDocument },
      { text: 'Cancel', style: 'cancel' },
    ]);
  }

  function startCall(audioOnly: boolean) {
    const appt = conversation?.appointment;
    if (!appt) return;
    if (!appt.is_video) {
      Alert.alert('Calls not available', 'Calls are available for video consultations. This appointment is in person.');
      return;
    }
    if (!appt.can_join_video) {
      Alert.alert('Not open yet', 'The consultation room opens 10 minutes before the appointment and closes 30 minutes after it ends.');
      return;
    }
    navigation.navigate('VideoCall', { appointmentId: appt.id, audioOnly, name: conversation?.counterpart.name });
  }

  const inverted = useMemo(() => [...messages].reverse(), [messages]);
  const name = conversation?.counterpart.name ?? params.title ?? 'Conversation';
  const appt = conversation?.appointment;

  return (
    <View style={[styles.container, { paddingTop: insets.top }]}>
      <View style={styles.header}>
        <Pressable onPress={() => navigation.goBack()} hitSlop={10} style={styles.iconBtn} accessibilityLabel="Back">
          <Ionicons name="chevron-back" size={24} color="#1C1C1E" />
        </Pressable>
        <View style={styles.headAvatar}>
          <Text style={styles.headAvatarText}>{initials(name.replace(/^Dr\.\s*/, ''))}</Text>
        </View>
        <View style={{ flex: 1, minWidth: 0 }}>
          <Text style={styles.headName} numberOfLines={1}>
            {name}
          </Text>
          <Text style={styles.headSub} numberOfLines={1}>
            {conversation?.counterpart.subtitle ?? ''}
          </Text>
        </View>
        {appt?.is_video ? (
          <>
            <Pressable onPress={() => startCall(false)} hitSlop={8} style={styles.iconBtn} accessibilityLabel="Start video call">
              <Ionicons name="videocam-outline" size={22} color="#1C1C1E" />
            </Pressable>
            <Pressable onPress={() => startCall(true)} hitSlop={8} style={styles.iconBtn} accessibilityLabel="Start audio call">
              <Ionicons name="call-outline" size={20} color="#1C1C1E" />
            </Pressable>
          </>
        ) : null}
      </View>

      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : 'height'} keyboardVerticalOffset={0}>
        {loading ? (
          <ActivityIndicator style={{ marginTop: 40 }} color={pa.ink} />
        ) : error ? (
          <View style={styles.errorBox}>
            <Ionicons name="alert-circle-outline" size={28} color={pa.signalInk} />
            <Text style={styles.errorText}>{error}</Text>
          </View>
        ) : (
          <FlatList
            data={inverted}
            inverted
            keyExtractor={(m) => m.localKey ?? String(m.id)}
            contentContainerStyle={{ padding: 16 }}
            ListFooterComponent={appt ? <ConsultationCard appt={appt} /> : null}
            renderItem={({ item }) => <Bubble m={item} counterpart={name} onRetry={() => retry(item)} />}
          />
        )}

        {file ? (
          <View style={styles.filePill}>
            <Ionicons name={file.mimeType.startsWith('image/') ? 'image-outline' : 'document-text-outline'} size={16} color="#1C1C1E" />
            <Text style={styles.fileName} numberOfLines={1}>
              {file.name}
            </Text>
            <Pressable onPress={() => setFile(null)} hitSlop={8} accessibilityLabel="Remove attachment">
              <Ionicons name="close-circle" size={18} color="#8A8A8E" />
            </Pressable>
          </View>
        ) : null}

        {conversation && !conversation.can_send ? (
          <Text style={styles.readonly}>Read-only conversation</Text>
        ) : (
          <View style={[styles.inputBar, { paddingBottom: Math.max(insets.bottom, 10) }]}>
            <Pressable onPress={openAttachMenu} hitSlop={8} style={styles.clip} accessibilityLabel="Attach file" disabled={!conversation}>
              <Ionicons name="attach-outline" size={24} color="#1C1C1E" />
            </Pressable>
            <TextInput
              value={text}
              onChangeText={setText}
              placeholder="Type message…"
              placeholderTextColor="#9A9AA0"
              style={styles.input}
              multiline
              maxLength={5000}
              editable={!!conversation}
            />
            <Pressable
              onPress={onSend}
              disabled={!conversation || (!text.trim() && !file)}
              style={({ pressed }) => [styles.send, { opacity: !conversation || (!text.trim() && !file) ? 0.4 : pressed ? 0.8 : 1 }]}
              accessibilityLabel="Send"
            >
              <Text style={styles.sendText}>Send</Text>
            </Pressable>
          </View>
        )}
      </KeyboardAvoidingView>
    </View>
  );
}

function ConsultationCard({ appt }: { appt: NonNullable<Conversation['appointment']> }) {
  const when = appt.date
    ? `${new Date(`${appt.date}T00:00:00`).toLocaleDateString('en-ZA', { weekday: 'short', day: '2-digit', month: 'short' })}${appt.start_time ? ` · ${appt.start_time.slice(0, 5)}` : ''}`
    : '';
  return (
    <View style={styles.consultWrap}>
      <View style={styles.consultCard}>
        <Text style={styles.consultTitle}>Consultation start</Text>
        <Text style={styles.consultSub}>
          {appt.is_video ? 'Video consultation' : 'In-person consultation'}
          {when ? ` · ${when}` : ''}
        </Text>
        {appt.reason ? (
          <Text style={styles.consultReason} numberOfLines={2}>
            {appt.reason}
          </Text>
        ) : null}
      </View>
    </View>
  );
}

function Bubble({ m, counterpart, onRetry }: { m: ChatMessage; counterpart: string; onRetry: () => void }) {
  const mine = m.is_mine;
  const att = m.attachment;

  return (
    <Pressable onPress={m.failed ? onRetry : undefined} style={[styles.msgRow, mine ? styles.msgRowMine : null]}>
      {!mine ? (
        <View style={styles.msgAvatar}>
          <Text style={styles.msgAvatarText}>{initials(counterpart.replace(/^Dr\.\s*/, ''))}</Text>
        </View>
      ) : null}
      <View style={{ maxWidth: '78%', alignItems: mine ? 'flex-end' : 'flex-start' }}>
        <View style={[styles.bubble, mine ? styles.bubbleMine : styles.bubbleTheirs, m.failed && styles.bubbleFailed]}>
          {att ? (
            att.is_image ? (
              <Pressable onPress={() => !m.pending && Linking.openURL(att.url)}>
                <Image source={{ uri: att.url }} style={styles.image} resizeMode="cover" />
              </Pressable>
            ) : (
              <Pressable onPress={() => !m.pending && Linking.openURL(att.url)} style={styles.doc}>
                <Ionicons name="document-text-outline" size={20} color={mine ? '#fff' : '#1C1C1E'} />
                <Text style={[styles.docName, mine && { color: '#fff' }]} numberOfLines={1}>
                  {att.name}
                </Text>
              </Pressable>
            )
          ) : null}
          {m.body ? <Text style={[styles.msgText, mine && { color: '#fff' }]}>{m.body}</Text> : null}
        </View>
        <View style={styles.metaRow}>
          <Text style={styles.metaText}>{m.failed ? 'Not sent · tap to retry' : clockTime(m.created_at)}</Text>
          {mine && !m.failed ? (
            m.pending ? (
              <Ionicons name="time-outline" size={13} color="#9A9AA0" />
            ) : (
              <Ionicons name={m.read_at ? 'checkmark-done' : 'checkmark'} size={15} color={m.read_at ? pa.info : '#9A9AA0'} />
            )
          ) : null}
        </View>
      </View>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#F6F6F8' },
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    paddingHorizontal: 10,
    paddingVertical: 10,
    backgroundColor: '#fff',
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: '#E6E6E9',
  },
  iconBtn: { width: 40, height: 40, borderRadius: 20, alignItems: 'center', justifyContent: 'center' },
  headAvatar: { width: 40, height: 40, borderRadius: 20, backgroundColor: pa.ink, alignItems: 'center', justifyContent: 'center' },
  headAvatarText: { color: '#fff', fontFamily: paFonts.black, fontSize: 14 },
  headName: { fontFamily: paFonts.bold, fontSize: 16, color: '#1C1C1E' },
  headSub: { fontFamily: paFonts.regular, fontSize: 12, color: '#8A8A8E' },
  consultWrap: { alignItems: 'center', marginBottom: 16 },
  consultCard: {
    backgroundColor: '#fff',
    borderRadius: 18,
    paddingVertical: 12,
    paddingHorizontal: 18,
    alignItems: 'center',
    shadowColor: '#000',
    shadowOpacity: 0.05,
    shadowRadius: 8,
    shadowOffset: { width: 0, height: 3 },
    elevation: 1,
    maxWidth: '90%',
  },
  consultTitle: { fontFamily: paFonts.bold, fontSize: 14, color: '#1C1C1E' },
  consultSub: { fontFamily: paFonts.regular, fontSize: 12, color: '#8A8A8E', marginTop: 2 },
  consultReason: { fontFamily: paFonts.regular, fontSize: 12, color: '#6B6B70', marginTop: 6, textAlign: 'center' },
  msgRow: { flexDirection: 'row', alignItems: 'flex-end', gap: 8, marginBottom: 12 },
  msgRowMine: { justifyContent: 'flex-end' },
  msgAvatar: { width: 30, height: 30, borderRadius: 15, backgroundColor: '#DADADF', alignItems: 'center', justifyContent: 'center', marginBottom: 18 },
  msgAvatarText: { color: '#1C1C1E', fontFamily: paFonts.black, fontSize: 11 },
  bubble: { borderRadius: 20, paddingHorizontal: 14, paddingVertical: 10, overflow: 'hidden' },
  bubbleMine: { backgroundColor: pa.ink, borderBottomRightRadius: 6 },
  bubbleTheirs: { backgroundColor: '#fff', borderBottomLeftRadius: 6 },
  bubbleFailed: { opacity: 0.6 },
  msgText: { fontFamily: paFonts.regular, fontSize: 15, lineHeight: 21, color: '#1C1C1E' },
  image: { width: 200, height: 200, borderRadius: 14, marginBottom: 4, backgroundColor: '#DADADF' },
  doc: { flexDirection: 'row', alignItems: 'center', gap: 8, marginBottom: 4, maxWidth: 220 },
  docName: { fontFamily: paFonts.bold, fontSize: 13, color: '#1C1C1E', textDecorationLine: 'underline', flexShrink: 1 },
  metaRow: { flexDirection: 'row', alignItems: 'center', gap: 4, marginTop: 4 },
  metaText: { fontFamily: paFonts.regular, fontSize: 11, color: '#9A9AA0' },
  filePill: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    marginHorizontal: 16,
    marginBottom: 6,
    paddingHorizontal: 12,
    paddingVertical: 8,
    backgroundColor: '#fff',
    borderRadius: 14,
    alignSelf: 'flex-start',
    maxWidth: '90%',
  },
  fileName: { fontFamily: paFonts.regular, fontSize: 12, color: '#1C1C1E', flexShrink: 1 },
  inputBar: {
    flexDirection: 'row',
    alignItems: 'flex-end',
    gap: 8,
    paddingHorizontal: 12,
    paddingTop: 10,
    backgroundColor: '#fff',
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: '#E6E6E9',
  },
  clip: { width: 40, height: 44, alignItems: 'center', justifyContent: 'center' },
  input: {
    flex: 1,
    minHeight: 44,
    maxHeight: 120,
    backgroundColor: '#F2F2F5',
    borderRadius: 22,
    paddingHorizontal: 16,
    paddingTop: 12,
    paddingBottom: 12,
    fontFamily: paFonts.regular,
    fontSize: 15,
    color: '#1C1C1E',
  },
  send: { height: 44, paddingHorizontal: 18, borderRadius: 22, backgroundColor: pa.ink, alignItems: 'center', justifyContent: 'center' },
  sendText: { color: '#fff', fontFamily: paFonts.bold, fontSize: 14 },
  readonly: { textAlign: 'center', padding: 16, fontFamily: paFonts.regular, color: '#8A8A8E' },
  errorBox: { alignItems: 'center', padding: 32, gap: 10 },
  errorText: { fontFamily: paFonts.regular, fontSize: 14, color: '#6B6B70', textAlign: 'center' },
});
