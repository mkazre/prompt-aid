import React, { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Alert, KeyboardAvoidingView, Linking, Platform, Pressable, RefreshControl, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { Feather } from '@expo/vector-icons';
import * as DocumentPicker from 'expo-document-picker';
import { useFocusEffect, useNavigation, useRoute } from '@react-navigation/native';
import { apiErrorMessage } from '../../api/client';
import { fetchTicket, PickedFile, replyToTicket, setTicketStatus, SupportTicket } from '../../api/support';
import { pa, paFonts } from '../../theme';
import { formatWhen, TICKET_STATUS } from './SupportScreen';

/** One support ticket: the thread with staff, a reply box and resolve / reopen. */
export default function SupportThreadScreen() {
  const navigation = useNavigation<any>();
  const route = useRoute<any>();
  const ticketId: number = Number(route.params?.ticketId ?? route.params?.ticket_id);
  const [ticket, setTicket] = useState<SupportTicket | null>(null);
  const [loading, setLoading] = useState(true);
  const [reply, setReply] = useState('');
  const [file, setFile] = useState<PickedFile | null>(null);
  const [sending, setSending] = useState(false);
  const scroll = useRef<ScrollView>(null);

  const load = useCallback(async () => {
    try {
      const t = await fetchTicket(ticketId);
      setTicket(t);
      navigation.setOptions({ title: t.reference });
    } catch (e) {
      Alert.alert('Could not load ticket', apiErrorMessage(e));
    } finally {
      setLoading(false);
    }
  }, [ticketId, navigation]);

  useFocusEffect(useCallback(() => { load(); }, [load]));

  // Light polling while the thread is open (no websockets on shared hosting).
  useEffect(() => {
    const id = setInterval(() => { fetchTicket(ticketId).then(setTicket).catch(() => undefined); }, 30000);
    return () => clearInterval(id);
  }, [ticketId]);

  async function pick() {
    const res = await DocumentPicker.getDocumentAsync({ type: ['application/pdf', 'image/*'], copyToCacheDirectory: true });
    if (res.canceled || !res.assets?.[0]) return;
    const a = res.assets[0];
    setFile({ uri: a.uri, name: a.name, mimeType: a.mimeType });
  }

  async function send() {
    if (sending || !reply.trim()) return;
    setSending(true);
    try {
      const t = await replyToTicket(ticketId, reply.trim(), file);
      setTicket(t);
      setReply('');
      setFile(null);
      setTimeout(() => scroll.current?.scrollToEnd({ animated: true }), 100);
    } catch (e) {
      Alert.alert('Could not send', apiErrorMessage(e));
    } finally {
      setSending(false);
    }
  }

  async function toggleResolved() {
    if (!ticket) return;
    try {
      setTicket(await setTicketStatus(ticket.id, ticket.status === 'resolved' ? 'open' : 'resolved'));
    } catch (e) {
      Alert.alert('Could not update', apiErrorMessage(e));
    }
  }

  if (loading && !ticket) {
    return <View style={styles.center}><ActivityIndicator color={pa.signal} /></View>;
  }
  if (!ticket) return null;

  const st = TICKET_STATUS[ticket.status] ?? TICKET_STATUS.open;

  return (
    <KeyboardAvoidingView style={{ flex: 1, backgroundColor: pa.paper }} behavior={Platform.OS === 'ios' ? 'padding' : undefined} keyboardVerticalOffset={90}>
      <ScrollView
        ref={scroll}
        contentContainerStyle={{ padding: 16, paddingBottom: 24 }}
        refreshControl={<RefreshControl refreshing={false} onRefresh={load} />}
        onContentSizeChange={() => scroll.current?.scrollToEnd({ animated: false })}
      >
        <Text style={styles.subject}>{ticket.subject}</Text>
        <View style={styles.metaRow}>
          <Text style={styles.meta}>{ticket.category_label} · opened {formatWhen(ticket.created_at)}</Text>
          <View style={[styles.pill, { backgroundColor: st.bg }]}><Text style={[styles.pillText, { color: st.fg }]}>{ticket.status_label}</Text></View>
        </View>

        {(ticket.replies ?? []).map((r) => (
          <View key={r.id} style={[styles.bubble, r.is_staff ? styles.staff : styles.me]}>
            <Text style={styles.author}>{r.is_staff ? 'PromptAid support' : 'You'} · {formatWhen(r.created_at)}</Text>
            <Text style={styles.body}>{r.body}</Text>
            {r.attachment?.url ? (
              <Pressable onPress={() => Linking.openURL(r.attachment!.url!)} style={styles.file}>
                <Feather name="paperclip" size={14} color={pa.info} />
                <Text style={styles.fileText} numberOfLines={1}>{r.attachment.name}</Text>
              </Pressable>
            ) : null}
          </View>
        ))}

        {ticket.status !== 'closed' ? (
          <Pressable onPress={toggleResolved} style={styles.resolve}>
            <Feather name={ticket.status === 'resolved' ? 'rotate-ccw' : 'check-circle'} size={14} color={pa.ink} />
            <Text style={styles.resolveText}>{ticket.status === 'resolved' ? 'Reopen ticket' : 'My issue is resolved'}</Text>
          </Pressable>
        ) : (
          <Text style={styles.closed}>This ticket is closed. Open a new one if you need more help.</Text>
        )}
      </ScrollView>

      {ticket.can_reply ? (
        <View style={styles.composer}>
          {file ? (
            <Pressable onPress={() => setFile(null)} style={styles.fileChip}>
              <Feather name="x" size={12} color={pa.ink} />
              <Text style={styles.fileChipText} numberOfLines={1}>{file.name}</Text>
            </Pressable>
          ) : null}
          <View style={styles.inputRow}>
            <Pressable onPress={pick} hitSlop={8} accessibilityLabel="Attach a file"><Feather name="paperclip" size={20} color={pa.muted} /></Pressable>
            <TextInput style={styles.input} value={reply} onChangeText={setReply} placeholder="Write a reply…" placeholderTextColor={pa.muted2} multiline maxLength={5000} />
            <Pressable onPress={send} disabled={sending || !reply.trim()} style={[styles.send, (!reply.trim() || sending) && { opacity: 0.5 }]} accessibilityLabel="Send reply">
              {sending ? <ActivityIndicator color="#fff" size="small" /> : <Feather name="send" size={18} color="#fff" />}
            </Pressable>
          </View>
        </View>
      ) : null}
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: pa.paper },
  subject: { fontFamily: paFonts.black, fontSize: 20, color: pa.ink },
  metaRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 8, marginTop: 6, marginBottom: 16 },
  meta: { flex: 1, fontFamily: paFonts.regular, fontSize: 12, color: pa.muted },
  pill: { borderRadius: 999, paddingHorizontal: 10, paddingVertical: 4 },
  pillText: { fontFamily: paFonts.black, fontSize: 11 },
  bubble: { borderRadius: 12, padding: 12, marginBottom: 10, maxWidth: '90%', borderWidth: 1 },
  me: { alignSelf: 'flex-end', backgroundColor: pa.surface2, borderColor: pa.line },
  staff: { alignSelf: 'flex-start', backgroundColor: '#EAF1F8', borderColor: '#C9DAEA' },
  author: { fontFamily: paFonts.bold, fontSize: 11, color: pa.muted, marginBottom: 4 },
  body: { fontFamily: paFonts.regular, fontSize: 14, color: pa.ink, lineHeight: 20 },
  file: { flexDirection: 'row', alignItems: 'center', gap: 6, marginTop: 8 },
  fileText: { fontFamily: paFonts.bold, fontSize: 12, color: pa.info, flexShrink: 1 },
  resolve: { flexDirection: 'row', alignItems: 'center', alignSelf: 'center', gap: 6, borderWidth: 1, borderColor: pa.line, borderRadius: 999, paddingHorizontal: 14, paddingVertical: 8, marginTop: 8, backgroundColor: pa.surface },
  resolveText: { fontFamily: paFonts.bold, fontSize: 12, color: pa.ink },
  closed: { fontFamily: paFonts.regular, fontSize: 13, color: pa.muted, textAlign: 'center', marginTop: 10 },
  composer: { borderTopWidth: 1, borderTopColor: pa.line, backgroundColor: pa.surface, padding: 10, paddingBottom: 18 },
  inputRow: { flexDirection: 'row', alignItems: 'flex-end', gap: 10 },
  input: { flex: 1, maxHeight: 120, borderWidth: 1, borderColor: pa.line, borderRadius: 18, paddingHorizontal: 14, paddingVertical: 8, fontFamily: paFonts.regular, fontSize: 14, color: pa.ink },
  send: { width: 38, height: 38, borderRadius: 19, backgroundColor: pa.signal, alignItems: 'center', justifyContent: 'center' },
  fileChip: { flexDirection: 'row', alignItems: 'center', gap: 6, alignSelf: 'flex-start', backgroundColor: pa.lineSoft, borderRadius: 999, paddingHorizontal: 10, paddingVertical: 4, marginBottom: 8, maxWidth: '80%' },
  fileChipText: { fontFamily: paFonts.bold, fontSize: 12, color: pa.ink },
});
