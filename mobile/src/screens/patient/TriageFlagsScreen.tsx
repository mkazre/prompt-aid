import React, { useMemo, useState } from 'react';
import { Linking, ScrollView, StyleSheet, Text, View } from 'react-native';
import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import { pa, paFonts, type } from '../../theme';
import { PaButton, PaCard, PaEyebrow, PaSatsChip } from '../../components/pa';
import { PaCheckRow, PaChoiceChip, PaPainScale } from '../../components/pa/triage-extras';
import {
  BreathingValue,
  DISCRIMINATORS,
  MobilityValue,
  Observations,
  TriageLevel,
} from '../../services/triage';
import type { TriageStackParamList } from './TriageStartScreen';

type Props = NativeStackScreenProps<TriageStackParamList, 'TriageFlags'>;

const MOBILITY_OPTIONS: { key: MobilityValue; label: string }[] = [
  { key: 'walking', label: 'Walking' },
  { key: 'with_help', label: 'Needs help' },
  { key: 'cannot_walk', label: 'Cannot walk' },
];

const BREATHING_OPTIONS: { key: BreathingValue; label: string }[] = [
  { key: 'normal', label: 'Breathing normally' },
  { key: 'short_on_effort', label: 'Short of breath' },
  { key: 'struggling', label: 'Struggling' },
];

const DISCRIMINATOR_LEVELS: TriageLevel[] = ['red', 'orange', 'yellow'];

export default function TriageFlagsScreen({ navigation, route }: Props) {
  const { selfReported, ageBand, pregnant, symptoms } = route.params;
  const [discriminators, setDiscriminators] = useState<string[]>([]);
  const [mobility, setMobility] = useState<MobilityValue>('walking');
  const [breathing, setBreathing] = useState<BreathingValue>('normal');
  const [pain, setPain] = useState(0);

  const grouped = useMemo(
    () =>
      DISCRIMINATOR_LEVELS.map((level) => ({
        level,
        items: DISCRIMINATORS.filter((d) => d.level === level),
      })),
    []
  );

  function toggleDiscriminator(id: string) {
    setDiscriminators((prev) => (prev.includes(id) ? prev.filter((d) => d !== id) : [...prev, id]));
  }

  function continueToResult() {
    const observations: Observations = { mobility, breathing, pain };
    navigation.navigate('TriageResult', {
      selfReported,
      ageBand,
      pregnant,
      symptoms,
      discriminators,
      observations,
    });
  }

  return (
    <ScrollView style={styles.container} contentContainerStyle={{ padding: 16, paddingBottom: 40 }}>
      <View style={styles.headRow}>
        <Text style={styles.headTitle}>Triage</Text>
        <Text style={styles.callLink} onPress={() => Linking.openURL('tel:10177')}>
          10177
        </Text>
      </View>

      <PaEyebrow>Step 2 of 3</PaEyebrow>
      <Text style={styles.question}>Is any of this true now?</Text>
      <Text style={styles.hint}>One is enough to move you up the queue.</Text>

      {grouped.map((group) => (
        <View key={group.level} style={{ marginBottom: 14 }}>
          <View style={{ marginBottom: 6 }}>
            <PaSatsChip level={group.level} />
          </View>
          <PaCard style={{ paddingVertical: 2, paddingHorizontal: 14 }}>
            {group.items.map((d) => (
              <PaCheckRow key={d.id} label={d.label} on={discriminators.includes(d.id)} onPress={() => toggleDiscriminator(d.id)} />
            ))}
          </PaCard>
        </View>
      ))}

      <Text style={[styles.question, { fontSize: 17, marginTop: 6 }]}>How are they now?</Text>
      <View style={styles.chipRow}>
        {MOBILITY_OPTIONS.map((opt) => (
          <PaChoiceChip key={opt.key} label={opt.label} on={mobility === opt.key} onPress={() => setMobility(opt.key)} />
        ))}
      </View>
      <View style={styles.chipRow}>
        {BREATHING_OPTIONS.map((opt) => (
          <PaChoiceChip key={opt.key} label={opt.label} on={breathing === opt.key} onPress={() => setBreathing(opt.key)} />
        ))}
      </View>

      <View style={{ marginTop: 14 }}>
        <PaPainScale value={pain} onChange={setPain} />
      </View>

      <PaButton title="Get my triage colour" onPress={continueToResult} style={{ marginTop: 8 }} />
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper },
  headRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 12 },
  headTitle: { ...type.h2, color: pa.ink },
  callLink: { fontFamily: paFonts.black, fontSize: 13, color: pa.signal },
  question: { fontFamily: paFonts.black, fontSize: 19, color: pa.ink, marginBottom: 4, marginTop: 4 },
  hint: { fontFamily: paFonts.regular, fontSize: 13, color: pa.muted, marginBottom: 14 },
  chipRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginTop: 8, marginBottom: 4 },
});
