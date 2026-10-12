import React, { useEffect, useState } from 'react';
import { Alert, Modal, Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import { api, apiErrorMessage } from '../api/client';
import { PaButton } from './pa';
import { pa, paFonts, paRadius } from '../theme';

const DEFAULT_OPTIONS = [15, 30, 45, 60];

/**
 * Doctor "Running late" sheet: pick 15/30/45/60 minutes plus an optional
 * message; POST /doctor/running-late alerts every patient still waiting
 * today (pending/confirmed).
 */
export default function RunningLateModal({ visible, onClose }: { visible: boolean; onClose: () => void }) {
  const [options, setOptions] = useState<number[]>(DEFAULT_OPTIONS);
  const [remaining, setRemaining] = useState<number | null>(null);
  const [minutes, setMinutes] = useState(15);
  const [message, setMessage] = useState('');
  const [sending, setSending] = useState(false);

  useEffect(() => {
    if (!visible) return;
    setRemaining(null);
    api
      .get('/doctor/running-late')
      .then(({ data }) => {
        if (Array.isArray(data.options) && data.options.length) setOptions(data.options);
        setRemaining(typeof data.remaining_patients === 'number' ? data.remaining_patients : null);
      })
      .catch(() => setRemaining(null));
  }, [visible]);

  async function send() {
    setSending(true);
    try {
      const { data } = await api.post('/doctor/running-late', { minutes, message: message.trim() || undefined });
      setMessage('');
      onClose();
      Alert.alert('Patients notified', data.message);
    } catch (e) {
      Alert.alert('Could not notify patients', apiErrorMessage(e));
    } finally {
      setSending(false);
    }
  }

  return (
    <Modal visible={visible} transparent animationType="slide" onRequestClose={onClose}>
      <View style={styles.overlay}>
        <View style={styles.sheet}>
          <Text style={styles.title}>Running late?</Text>
          <Text style={styles.sub}>
            {remaining === null
              ? "We'll alert every patient still waiting today."
              : remaining === 0
                ? 'You have no remaining patients today.'
                : `${remaining} patient${remaining === 1 ? '' : 's'} still waiting today will get an alert.`}
          </Text>

          <View style={styles.chips}>
            {options.map((m) => (
              <Pressable
                key={m}
                onPress={() => setMinutes(m)}
                style={[styles.chip, minutes === m && styles.chipOn]}
                accessibilityRole="button"
                accessibilityState={{ selected: minutes === m }}
              >
                <Text style={[styles.chipText, minutes === m && styles.chipTextOn]}>{m} min</Text>
              </Pressable>
            ))}
          </View>

          <TextInput
            value={message}
            onChangeText={setMessage}
            placeholder="Message (optional), e.g. An emergency came in."
            placeholderTextColor={pa.muted2}
            multiline
            maxLength={500}
            style={styles.input}
          />

          <PaButton title={`Notify patients — ${minutes} min late`} onPress={send} loading={sending} disabled={remaining === 0} />
          <PaButton title="Cancel" variant="ghost" onPress={onClose} style={{ marginTop: 8 }} />
        </View>
      </View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  overlay: { flex: 1, backgroundColor: 'rgba(16,16,18,0.45)', justifyContent: 'flex-end' },
  sheet: { backgroundColor: pa.surface, borderTopWidth: 1, borderTopColor: pa.ink, padding: 18, paddingBottom: 28 },
  title: { fontFamily: paFonts.black, fontSize: 18, color: pa.ink },
  sub: { fontFamily: paFonts.regular, fontSize: 13, color: pa.muted, marginTop: 4, marginBottom: 14 },
  chips: { flexDirection: 'row', gap: 8, marginBottom: 12 },
  chip: { flex: 1, alignItems: 'center', paddingVertical: 10, borderWidth: 1, borderColor: pa.line, borderRadius: paRadius.sm },
  chipOn: { backgroundColor: pa.ink, borderColor: pa.ink },
  chipText: { fontFamily: paFonts.bold, fontSize: 13, color: pa.ink },
  chipTextOn: { color: pa.surface },
  input: {
    borderWidth: 1,
    borderColor: pa.line,
    borderRadius: paRadius.sm,
    padding: 12,
    minHeight: 72,
    textAlignVertical: 'top',
    fontFamily: paFonts.regular,
    fontSize: 14,
    color: pa.ink,
    marginBottom: 12,
  },
});
