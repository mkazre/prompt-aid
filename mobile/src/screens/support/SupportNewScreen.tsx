import React, { useEffect, useState } from 'react';
import { Alert, KeyboardAvoidingView, Platform, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { Feather } from '@expo/vector-icons';
import * as DocumentPicker from 'expo-document-picker';
import { useNavigation } from '@react-navigation/native';
import { apiErrorMessage } from '../../api/client';
import { createTicket, fetchSupportMeta, PickedFile } from '../../api/support';
import { PaButton } from '../../components/pa';
import { pa, paFonts } from '../../theme';

const FALLBACK_CATEGORIES = [
  { key: 'booking', label: 'Booking' },
  { key: 'payment', label: 'Payment' },
  { key: 'shuttle', label: 'Shuttle' },
  { key: 'technical', label: 'Technical' },
  { key: 'other', label: 'Other' },
];

/** Open a new help & support ticket: subject, category, message, optional attachment. */
export default function SupportNewScreen() {
  const navigation = useNavigation<any>();
  const [categories, setCategories] = useState(FALLBACK_CATEGORIES);
  const [category, setCategory] = useState('booking');
  const [subject, setSubject] = useState('');
  const [message, setMessage] = useState('');
  const [file, setFile] = useState<PickedFile | null>(null);
  const [sending, setSending] = useState(false);

  useEffect(() => {
    fetchSupportMeta().then((m) => m.categories?.length && setCategories(m.categories)).catch(() => undefined);
  }, []);

  async function pick() {
    const res = await DocumentPicker.getDocumentAsync({ type: ['application/pdf', 'image/*'], copyToCacheDirectory: true });
    if (res.canceled || !res.assets?.[0]) return;
    const a = res.assets[0];
    if (a.size && a.size > 10 * 1024 * 1024) {
      Alert.alert('File too large', 'Attachments can be up to 10 MB.');
      return;
    }
    setFile({ uri: a.uri, name: a.name, mimeType: a.mimeType });
  }

  async function send() {
    if (sending) return;
    if (!subject.trim() || !message.trim()) {
      Alert.alert('Almost there', 'Please add a subject and describe the problem.');
      return;
    }
    setSending(true);
    try {
      const t = await createTicket({ subject: subject.trim(), category, message: message.trim() }, file);
      navigation.replace('SupportThread', { ticketId: t.id });
    } catch (e) {
      Alert.alert('Could not send', apiErrorMessage(e));
      setSending(false);
    }
  }

  return (
    <KeyboardAvoidingView style={{ flex: 1, backgroundColor: pa.paper }} behavior={Platform.OS === 'ios' ? 'padding' : undefined} keyboardVerticalOffset={90}>
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 48 }} keyboardShouldPersistTaps="handled">
        <Text style={styles.label}>Category</Text>
        <View style={styles.chips}>
          {categories.map((c) => (
            <Pressable key={c.key} onPress={() => setCategory(c.key)} style={[styles.chip, category === c.key && styles.chipOn]}>
              <Text style={[styles.chipText, category === c.key && { color: '#fff' }]}>{c.label}</Text>
            </Pressable>
          ))}
        </View>

        <Text style={styles.label}>Subject</Text>
        <TextInput style={styles.input} value={subject} onChangeText={setSubject} maxLength={150} placeholder="e.g. I was charged twice" placeholderTextColor={pa.muted2} />

        <Text style={styles.label}>Message</Text>
        <TextInput
          style={[styles.input, { minHeight: 140, textAlignVertical: 'top' }]}
          value={message}
          onChangeText={setMessage}
          maxLength={5000}
          multiline
          placeholder="Tell us what happened. Include a booking or trip reference if you have one."
          placeholderTextColor={pa.muted2}
        />

        <Pressable style={styles.attach} onPress={file ? () => setFile(null) : pick}>
          <Feather name={file ? 'x' : 'paperclip'} size={16} color={pa.ink} />
          <Text style={styles.attachText} numberOfLines={1}>{file ? file.name : 'Attach a screenshot or PDF (optional)'}</Text>
        </Pressable>

        <Text style={styles.hint}>Please don't send clinical details here — message your doctor instead.</Text>
        <PaButton title="Send ticket" onPress={send} loading={sending} />
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  label: { fontFamily: paFonts.black, fontSize: 11, letterSpacing: 1.2, textTransform: 'uppercase', color: pa.muted, marginBottom: 6, marginTop: 6 },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginBottom: 12 },
  chip: { borderWidth: 1, borderColor: pa.line, borderRadius: 999, paddingHorizontal: 14, paddingVertical: 8, backgroundColor: pa.surface },
  chipOn: { backgroundColor: pa.ink, borderColor: pa.ink },
  chipText: { fontFamily: paFonts.bold, fontSize: 13, color: pa.ink },
  input: { borderWidth: 1, borderColor: pa.line, borderRadius: 8, backgroundColor: pa.surface, paddingHorizontal: 14, paddingVertical: 12, fontFamily: paFonts.regular, fontSize: 15, color: pa.ink, marginBottom: 12 },
  attach: { flexDirection: 'row', alignItems: 'center', gap: 8, borderWidth: 1, borderStyle: 'dashed', borderColor: pa.line, borderRadius: 8, padding: 12, marginBottom: 12, backgroundColor: pa.surface },
  attachText: { flex: 1, fontFamily: paFonts.bold, fontSize: 13, color: pa.ink },
  hint: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted, marginBottom: 14 },
});
