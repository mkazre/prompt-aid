import React, { useEffect, useMemo } from 'react';
import { Linking, ScrollView, StyleSheet, Text, View } from 'react-native';
import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import { api } from '../../api/client';
import { pa, paFonts, type } from '../../theme';
import { PaButton, PaCard } from '../../components/pa';
import { PaVerdictBanner } from '../../components/pa/triage-extras';
import { assess, TriageLevel } from '../../services/triage';
import type { TriageStackParamList } from './TriageStartScreen';

type Props = NativeStackScreenProps<TriageStackParamList, 'TriageResult'>;

const ADVICE: Record<TriageLevel, string[]> = {
  red: [
    'Call 10177 now, or 112 from a mobile.',
    'Do not drive yourself.',
    'Stay with the patient and keep them still.',
    'If they stop breathing, start chest compressions — push hard and fast in the centre of the chest.',
  ],
  orange: [
    'Get to an emergency department within 10 minutes.',
    'Do not eat or drink anything in case a procedure is needed.',
    'Bring your ID, medical aid card and any medicine you take.',
    'For burns, run cool water over the area for 20 minutes. No ice, no butter, no toothpaste.',
  ],
  yellow: [
    'You should be seen within the hour.',
    'A clinic or GP can handle this — an emergency department will keep you waiting behind the red and orange cases.',
    'Write down when the symptoms started and anything that makes them worse.',
  ],
  green: [
    'This can be booked normally, today or tomorrow.',
    'A pharmacist can advise on minor complaints without an appointment.',
    'Come back to this tool if anything changes — it only takes a minute.',
  ],
};

/**
 * Fire-and-forget submission to the real backend endpoint built this
 * session. Never awaited by the caller and never allowed to block or delay
 * the verdict render — errors are swallowed. Mirrors
 * backend/resources/views/pages/emergency.blade.php#submitTriageResult.
 */
function submitTriageResult(params: {
  level: TriageLevel;
  ageBand: Props['route']['params']['ageBand'];
  pregnant: boolean;
  symptoms: string[];
  discriminators: string[];
  observations: Props['route']['params']['observations'];
  reasons: string[];
  facilityTypes: string[];
}) {
  try {
    void api
      .post('/triage/submit', {
        level: params.level,
        age_band: params.ageBand,
        pregnant: !!params.pregnant,
        symptoms: params.symptoms,
        discriminators: params.discriminators,
        observations: params.observations,
        reasons: params.reasons,
        facility_types: params.facilityTypes,
      })
      .catch(() => {
        /* fire-and-forget: never blocks or interrupts the patient */
      });
  } catch {
    /* fire-and-forget: never blocks or interrupts the patient */
  }
}

export default function TriageResultScreen({ navigation, route }: Props) {
  const { selfReported, ageBand, pregnant, symptoms, discriminators, observations } = route.params;

  const result = useMemo(
    () => assess({ selfReported, ageBand, pregnant, symptoms, discriminators, observations }),
    [selfReported, ageBand, pregnant, symptoms, discriminators, observations]
  );

  useEffect(() => {
    submitTriageResult({
      level: result.level,
      ageBand,
      pregnant,
      symptoms,
      discriminators,
      observations,
      reasons: result.reasons,
      facilityTypes: result.facilityTypes,
    });
    // Submit once per computed verdict — result is memoised on the same inputs.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [result.level]);

  const advice = ADVICE[result.level];

  return (
    <ScrollView style={styles.container} contentContainerStyle={{ paddingBottom: 40 }}>
      <PaVerdictBanner
        level={result.level}
        eyebrow={`South African Triage Scale · ${result.reference}`}
        title={`${result.meta.label} — ${result.meta.name}`}
        message={`Target time to be seen: ${result.meta.targetLabel}. Show reference ${result.reference} at reception.`}
      />

      <View style={{ padding: 16 }}>
        {result.callAmbulance ? (
          <PaButton title="Call an ambulance now · 10177" onPress={() => Linking.openURL('tel:10177')} style={{ marginBottom: 16, backgroundColor: pa.signal }} />
        ) : null}

        <PaCard style={{ marginBottom: 16 }}>
          <View style={styles.cardHeadRow}>
            <Text style={styles.cardHead}>What to do right now</Text>
            <Text style={styles.countdown}>{result.meta.target === 0 ? 'NOW' : `${result.meta.target} min`}</Text>
          </View>
          {advice.map((a, i) => (
            <Text key={i} style={styles.adviceLine}>
              {i + 1}. {a}
            </Text>
          ))}
        </PaCard>

        <PaCard style={{ marginBottom: 16 }}>
          <Text style={styles.cardHead}>Why this colour</Text>
          {(result.reasons.length ? result.reasons : ['Nothing urgent reported']).map((r, i) => (
            <Text key={i} style={styles.reasonLine}>
              • {r}
            </Text>
          ))}
          <Text style={styles.facilityNote}>
            Facilities that can treat this: <Text style={styles.facilityNoteBold}>{result.facilityTypes.join(', ') || 'general practice'}</Text>. We rank by
            capability first, distance second.
          </Text>
        </PaCard>

        <PaButton
          title="Re-triage · tap if anything changes"
          variant="ghost"
          onPress={() => navigation.navigate('TriageStart', { selfReported: undefined })}
        />
      </View>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper },
  cardHeadRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 10 },
  cardHead: { ...type.h3, color: pa.ink },
  countdown: { fontFamily: paFonts.black, fontSize: 20, color: pa.ink },
  adviceLine: { fontFamily: paFonts.regular, fontSize: 14, color: pa.inkSoft, lineHeight: 22, marginBottom: 2 },
  reasonLine: { fontFamily: paFonts.regular, fontSize: 13, color: pa.inkSoft, lineHeight: 20 },
  facilityNote: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted, marginTop: 12, lineHeight: 18 },
  facilityNoteBold: { fontFamily: paFonts.bold, color: pa.ink },
});
