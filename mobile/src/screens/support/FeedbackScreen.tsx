import React, { useEffect, useState } from 'react';
import { ActivityIndicator, Alert, KeyboardAvoidingView, Platform, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { Feather } from '@expo/vector-icons';
import { useNavigation, useRoute } from '@react-navigation/native';
import { apiErrorMessage } from '../../api/client';
import { FeedbackMeta, FeedbackSurvey, fetchFeedback, submitFeedback } from '../../api/support';
import { PaButton } from '../../components/pa';
import { pa, paFonts } from '../../theme';

const scoreColor = (n: number) => (n >= 9 ? pa.go : n >= 7 ? pa.beacon : pa.signal);

/**
 * "How was your visit?" — 0–10 score + optional comment. Opened from the
 * notification and from the Home-screen card (which can pre-select a score).
 */
export default function FeedbackScreen() {
  const navigation = useNavigation<any>();
  const route = useRoute<any>();
  const token: string = route.params?.token;
  const [survey, setSurvey] = useState<FeedbackSurvey | null>(null);
  const [meta, setMeta] = useState<FeedbackMeta | null>(null);
  const [score, setScore] = useState<number | null>(typeof route.params?.score === 'number' ? route.params.score : null);
  const [comment, setComment] = useState('');
  const [sending, setSending] = useState(false);
  const [done, setDone] = useState(false);

  useEffect(() => {
    fetchFeedback(token)
      .then((r) => {
        setSurvey(r.survey);
        setMeta(r.meta);
        if (r.survey.score !== null && typeof route.params?.score !== 'number') setScore(r.survey.score);
        if (r.survey.comment) setComment(r.survey.comment);
      })
      .catch((e) => Alert.alert('Survey unavailable', apiErrorMessage(e), [{ text: 'OK', onPress: () => navigation.goBack() }]));
  }, [token]);

  async function send() {
    if (score === null || sending) return;
    setSending(true);
    try {
      await submitFeedback(token, score, comment);
      setDone(true);
    } catch (e) {
      Alert.alert('Could not send', apiErrorMessage(e));
    } finally {
      setSending(false);
    }
  }

  if (!survey) return <View style={styles.center}><ActivityIndicator color={pa.signal} /></View>;

  if (done) {
    return (
      <View style={[styles.center, { padding: 24 }]}>
        <Feather name="heart" size={40} color={pa.signal} />
        <Text style={styles.thanks}>Thank you!</Text>
        <Text style={styles.thanksSub}>Your feedback helps us improve PromptAid.</Text>
        <PaButton title="Done" onPress={() => navigation.goBack()} style={{ alignSelf: 'stretch', marginTop: 20 }} />
      </View>
    );
  }

  return (
    <KeyboardAvoidingView style={{ flex: 1, backgroundColor: pa.paper }} behavior={Platform.OS === 'ios' ? 'padding' : undefined} keyboardVerticalOffset={90}>
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }} keyboardShouldPersistTaps="handled">
        <Text style={styles.type}>{survey.type_label}</Text>
        <Text style={styles.title}>{meta?.title ?? 'How was your visit?'}</Text>
        {survey.label ? <Text style={styles.label}>{survey.label}</Text> : null}
        <Text style={styles.body}>{meta?.body}</Text>

        <View style={styles.scores}>
          {Array.from({ length: 11 }, (_, i) => {
            const on = score === i;
            return (
              <Pressable key={i} onPress={() => setScore(i)} style={[styles.score, on && { backgroundColor: scoreColor(i), borderColor: scoreColor(i) }]} accessibilityLabel={`Score ${i}`}>
                <Text style={[styles.scoreText, on && { color: i >= 7 && i <= 8 ? pa.ink : '#fff' }]}>{i}</Text>
              </Pressable>
            );
          })}
        </View>
        <View style={styles.ends}>
          <Text style={styles.endText}>Not likely</Text>
          <Text style={styles.endText}>Extremely likely</Text>
        </View>

        <Text style={styles.inputLabel}>Anything you'd like to tell us? (optional)</Text>
        <TextInput style={styles.input} value={comment} onChangeText={setComment} multiline maxLength={2000} placeholder="What went well, or what could be better?" placeholderTextColor={pa.muted2} />

        <PaButton title={survey.answered ? 'Update my feedback' : 'Send feedback'} onPress={send} loading={sending} disabled={score === null} />
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: pa.paper },
  type: { fontFamily: paFonts.black, fontSize: 11, letterSpacing: 1.4, textTransform: 'uppercase', color: pa.muted },
  title: { fontFamily: paFonts.black, fontSize: 24, color: pa.ink, marginTop: 4 },
  label: { fontFamily: paFonts.bold, fontSize: 13, color: pa.muted, marginTop: 4 },
  body: { fontFamily: paFonts.regular, fontSize: 15, color: pa.inkSoft, marginTop: 12, marginBottom: 14, lineHeight: 21 },
  scores: { flexDirection: 'row', flexWrap: 'wrap', gap: 6 },
  score: { width: 46, height: 46, borderWidth: 1, borderColor: pa.line, borderRadius: 8, backgroundColor: pa.surface, alignItems: 'center', justifyContent: 'center' },
  scoreText: { fontFamily: paFonts.black, fontSize: 16, color: pa.ink },
  ends: { flexDirection: 'row', justifyContent: 'space-between', marginTop: 6, marginBottom: 18 },
  endText: { fontFamily: paFonts.regular, fontSize: 11, color: pa.muted },
  inputLabel: { fontFamily: paFonts.black, fontSize: 11, letterSpacing: 1.2, textTransform: 'uppercase', color: pa.muted, marginBottom: 6 },
  input: { minHeight: 110, textAlignVertical: 'top', borderWidth: 1, borderColor: pa.line, borderRadius: 8, backgroundColor: pa.surface, padding: 12, fontFamily: paFonts.regular, fontSize: 14, color: pa.ink, marginBottom: 16 },
  thanks: { fontFamily: paFonts.black, fontSize: 24, color: pa.ink, marginTop: 12 },
  thanksSub: { fontFamily: paFonts.regular, fontSize: 14, color: pa.muted, marginTop: 6, textAlign: 'center' },
});
