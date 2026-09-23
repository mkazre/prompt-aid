import React, { useState } from 'react';
import { Linking, ScrollView, StyleSheet, Text, View } from 'react-native';
import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import { pa, paFonts, type } from '../../theme';
import { PaButton, PaEyebrow } from '../../components/pa';
import { PaChoiceChip, PaSatsBar, PaSymptomTile } from '../../components/pa/triage-extras';
import { AgeBand, SYMPTOMS, TriageLevel } from '../../services/triage';

/**
 * Route params shared by the whole triage flow. The engineer wiring this
 * into RootNavigator / PatientTabs should register a stack with these three
 * screens under these exact names and param shapes:
 *
 *   TriageStart  — entry point. `selfReported` is optional, set when the
 *                  patient tapped a colour on the TriagePromptModal
 *                  (mobile/src/components/pa/triage-extras.tsx) on the home
 *                  screen.
 *   TriageFlags  — step 2, receives everything collected in step 1.
 *   TriageResult — step 3, receives everything collected in steps 1 + 2 and
 *                  runs services/triage.ts#assess() itself.
 */
export type TriageStackParamList = {
  TriageStart: { selfReported?: TriageLevel } | undefined;
  TriageFlags: {
    selfReported?: TriageLevel;
    ageBand: AgeBand;
    pregnant: boolean;
    symptoms: string[];
  };
  TriageResult: {
    selfReported?: TriageLevel;
    ageBand: AgeBand;
    pregnant: boolean;
    symptoms: string[];
    discriminators: string[];
    observations: import('../../services/triage').Observations;
  };
};

type Props = NativeStackScreenProps<TriageStackParamList, 'TriageStart'>;

const WHO_OPTIONS = ['Me', 'Someone with me', 'Someone elsewhere'] as const;
const AGE_OPTIONS: { key: AgeBand; label: string }[] = [
  { key: 'adult', label: 'Adult' },
  { key: 'infant', label: 'Baby' },
  { key: 'child', label: 'Child' },
  { key: 'older', label: '65+' },
];

export default function TriageStartScreen({ navigation, route }: Props) {
  const selfReported = route.params?.selfReported;
  const [who, setWho] = useState<(typeof WHO_OPTIONS)[number]>('Me');
  const [ageBand, setAgeBand] = useState<AgeBand>('adult');
  const [pregnant, setPregnant] = useState(false);
  const [symptoms, setSymptoms] = useState<string[]>([]);

  function toggleSymptom(id: string) {
    setSymptoms((prev) => (prev.includes(id) ? prev.filter((s) => s !== id) : [...prev, id]));
  }

  function continueToFlags() {
    navigation.navigate('TriageFlags', { selfReported, ageBand, pregnant, symptoms });
  }

  return (
    <ScrollView style={styles.container} contentContainerStyle={{ padding: 16, paddingBottom: 40 }}>
      <View style={styles.headRow}>
        <Text style={styles.headTitle}>Triage</Text>
        <Text style={styles.callLink} onPress={() => Linking.openURL('tel:10177')}>
          10177
        </Text>
      </View>

      <PaSatsBar active={selfReported} />

      <View style={{ marginTop: 16 }}>
        <PaEyebrow>Step 1 of 3</PaEyebrow>
        <Text style={styles.question}>Who needs help?</Text>
      </View>

      <View style={styles.chipRow}>
        {WHO_OPTIONS.map((opt) => (
          <PaChoiceChip key={opt} label={opt} on={who === opt} onPress={() => setWho(opt)} />
        ))}
      </View>

      <View style={styles.chipRow}>
        {AGE_OPTIONS.map((opt) => (
          <PaChoiceChip key={opt.key} label={opt.label} on={ageBand === opt.key} onPress={() => setAgeBand(opt.key)} />
        ))}
        <PaChoiceChip label="Pregnant" on={pregnant} onPress={() => setPregnant((p) => !p)} />
      </View>

      <Text style={[styles.question, { marginTop: 20 }]}>What is happening?</Text>
      <View style={styles.symptomGrid}>
        {SYMPTOMS.map((s) => (
          <PaSymptomTile
            key={s.id}
            label={s.label}
            on={symptoms.includes(s.id)}
            critical={s.floor === 'red' || s.floor === 'orange'}
            onPress={() => toggleSymptom(s.id)}
          />
        ))}
      </View>

      <PaButton title="Continue" onPress={continueToFlags} style={{ marginTop: 20 }} />
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper },
  headRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 12 },
  headTitle: { ...type.h2, color: pa.ink },
  callLink: { fontFamily: paFonts.black, fontSize: 13, color: pa.signal },
  question: { fontFamily: paFonts.black, fontSize: 19, color: pa.ink, marginBottom: 10 },
  chipRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginTop: 10 },
  symptomGrid: { flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'space-between' },
});
