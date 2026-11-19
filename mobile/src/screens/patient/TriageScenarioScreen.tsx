import React, { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Linking, ScrollView, StyleSheet, Text, View } from 'react-native';
import { PaButton } from '../../components/pa';
import { PaVerdictBanner } from '../../components/pa/triage-extras';
import { ScenarioStepsView } from '../../components/pa/scenario-steps';
import { getScenario, Scenario } from '../../services/scenarios';
import { useEmergencyContacts } from '../../services/emergencyContacts';
import type { TriageLevel } from '../../services/triage';
import { pa, paFonts } from '../../theme';

const TITLES: Record<TriageLevel | 'virtual', string> = {
  virtual: 'Virtual / video consult',
  red: 'Get emergency care now',
  orange: 'Very urgent',
  yellow: 'Be seen today',
  green: 'Routine — can be booked',
};

/**
 * Runs a patient request scenario (Staff → Triage → Patient request
 * scenarios) for a colour: messages, call buttons (with the admin's
 * auto-dial countdown), "request a doctor", booking / find care /
 * pharmacy / advice — in the admin-defined order.
 */
export default function TriageScenarioScreen({ navigation, route }: any) {
  const level: TriageLevel | 'virtual' = route?.params?.level ?? 'yellow';
  const triageReference: string | undefined = route?.params?.triageReference;
  const { primary } = useEmergencyContacts();
  const [scenario, setScenario] = useState<Scenario | null>(null);
  const [failed, setFailed] = useState(false);

  const load = useCallback(async () => {
    setFailed(false);
    const sc = await getScenario(level, { fresh: true });
    if (sc) setScenario(sc);
    else setFailed(true);
  }, [level]);

  useEffect(() => {
    load();
  }, [load]);

  return (
    <ScrollView style={styles.container} contentContainerStyle={{ paddingBottom: 40 }}>
      <PaVerdictBanner level={level} eyebrow={`PromptAid Emergency Scale${triageReference ? ` · ${triageReference}` : ''}`} title={TITLES[level]} message={scenario?.level_label ?? ''} />
      <View style={{ padding: 16 }}>
        {scenario ? (
          // Auto-dial at 0 s already happened when the colour was tapped; countdowns run here.
          <ScenarioStepsView scenario={scenario} navigation={navigation} triageReference={triageReference} autoDial />
        ) : failed ? (
          <View style={{ gap: 10 }}>
            <Text style={styles.muted}>Could not load the next steps. Check your connection.</Text>
            <PaButton title="Try again" onPress={load} />
            <PaButton title={`Call ${primary.phone}`} variant="ghost" onPress={() => Linking.openURL(primary.tel_url)} />
          </View>
        ) : (
          <ActivityIndicator color={pa.ink} style={{ marginTop: 24 }} />
        )}
        <Text style={[styles.muted, { marginTop: 20 }]}>This is a pre-triage aid, not a diagnosis. If it gets worse, call {primary.phone} or 112.</Text>
      </View>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper },
  muted: { fontFamily: paFonts.regular, fontSize: 13, color: pa.muted },
});
