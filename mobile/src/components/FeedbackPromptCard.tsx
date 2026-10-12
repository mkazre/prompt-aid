import React, { useCallback, useState } from 'react';
import { useFocusEffect } from '@react-navigation/native';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { Feather } from '@expo/vector-icons';
import { dismissFeedback, FeedbackMeta, FeedbackSurvey, fetchPendingFeedback } from '../api/support';
import { pa, paFonts } from '../theme';

/**
 * Home-screen card shown while a "How was your visit?" survey is pending.
 * Tapping a score opens the Feedback screen pre-filled; "Not now" dismisses.
 */
export default function FeedbackPromptCard({ navigation, refreshKey }: { navigation: any; refreshKey?: number }) {
  const [survey, setSurvey] = useState<FeedbackSurvey | null>(null);
  const [meta, setMeta] = useState<FeedbackMeta | null>(null);

  // Reload on focus too, so the card disappears after answering.
  useFocusEffect(useCallback(() => {
    let alive = true;
    fetchPendingFeedback()
      .then((r) => {
        if (!alive) return;
        setSurvey(r.survey);
        setMeta(r.meta);
      })
      .catch(() => undefined);
    return () => {
      alive = false;
    };
  }, [refreshKey]));

  if (!survey) return null;

  const open = (score?: number) => navigation.navigate('Feedback', { token: survey.token, score });

  return (
    <View style={styles.card}>
      <View style={styles.head}>
        <Feather name="smile" size={18} color={pa.info} />
        <Text style={styles.title}>{meta?.title ?? 'How was your visit?'}</Text>
        <Pressable
          hitSlop={10}
          onPress={() => {
            dismissFeedback(survey.token).catch(() => undefined);
            setSurvey(null);
          }}
        >
          <Text style={styles.notNow}>Not now</Text>
        </Pressable>
      </View>
      {survey.label ? <Text style={styles.label}>{survey.label}</Text> : null}
      <Text style={styles.body}>{meta?.body}</Text>
      <View style={styles.scores}>
        {Array.from({ length: 11 }, (_, i) => (
          <Pressable key={i} onPress={() => open(i)} style={({ pressed }) => [styles.score, pressed && { backgroundColor: pa.lineSoft }]}>
            <Text style={styles.scoreText}>{i}</Text>
          </Pressable>
        ))}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  card: { backgroundColor: pa.surface, borderWidth: 1, borderColor: pa.line, borderRadius: 12, padding: 14, marginBottom: 14 },
  head: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  title: { flex: 1, fontFamily: paFonts.black, fontSize: 15, color: pa.ink },
  notNow: { fontFamily: paFonts.bold, fontSize: 12, color: pa.muted },
  label: { fontFamily: paFonts.bold, fontSize: 12, color: pa.muted, marginTop: 6 },
  body: { fontFamily: paFonts.regular, fontSize: 13, color: pa.inkSoft, marginTop: 4, marginBottom: 10 },
  scores: { flexDirection: 'row', flexWrap: 'wrap', gap: 5 },
  score: { width: 28, height: 32, borderWidth: 1, borderColor: pa.line, borderRadius: 6, alignItems: 'center', justifyContent: 'center' },
  scoreText: { fontFamily: paFonts.black, fontSize: 13, color: pa.ink },
});
