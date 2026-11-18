import React, { useEffect, useRef, useState } from 'react';
import { Linking, Pressable, StyleSheet, Text, View } from 'react-native';
import { PaButton, PaCard } from './index';
import { pa, paFonts, type } from '../../theme';
import { goToTarget, Scenario, ScenarioStep } from '../../services/scenarios';

/**
 * Renders a patient request scenario (GET /triage/scenario/{level}) as
 * cards, in the admin-defined order. The doctor request is one button that
 * opens InstantConsult (payment + broadcast + channel choice run there).
 * `autoDial`: honour call_emergency's auto-dial countdown (> 0 s) here.
 */
export function ScenarioStepsView({
  scenario,
  navigation,
  triageReference,
  autoDial = false,
  hideConsult = false,
  only,
}: {
  scenario: Scenario;
  navigation: any;
  triageReference?: string;
  autoDial?: boolean;
  hideConsult?: boolean;
  /** Render only these step types (e.g. alternatives after "no doctor accepted"). */
  only?: string[];
}) {
  const steps = only ? scenario.steps.filter((s) => only.includes(s.type)) : scenario.steps;
  return (
    <View style={{ gap: 12 }}>
      {steps.map((s) => (
        <StepCard key={s.id} step={s} scenario={scenario} navigation={navigation} triageReference={triageReference} autoDial={autoDial} hideConsult={hideConsult} />
      ))}
    </View>
  );
}

function open(url?: string | null) {
  if (url) Linking.openURL(url).catch(() => undefined);
}

function StepCard({ step, scenario, navigation, triageReference, autoDial, hideConsult }: { step: ScenarioStep; scenario: Scenario; navigation: any; triageReference?: string; autoDial: boolean; hideConsult: boolean }) {
  const st = step.settings ?? {};
  switch (step.type) {
    case 'show_message': {
      const tone = st.style === 'danger' ? styles.danger : st.style === 'warning' ? styles.warning : st.style === 'success' ? styles.success : styles.info;
      return (
        <View style={[styles.note, tone]}>
          {st.title ? <Text style={styles.noteTitle}>{st.title}</Text> : null}
          {st.body ? <Text style={styles.noteBody}>{st.body}</Text> : null}
        </View>
      );
    }
    case 'call_emergency':
      return <CallStep step={step} autoDial={autoDial} />;
    case 'offer_ambulance_or_shuttle':
      return (
        <PaCard>
          {st.note ? <Text style={styles.cardTitle}>{st.note}</Text> : null}
          {step.ambulance ? <PaButton title={`Call an ambulance · ${step.ambulance.phone}`} onPress={() => open(step.ambulance!.tel)} style={{ marginTop: 8, backgroundColor: pa.signal }} /> : null}
          {step.app ? <PaButton title="Book a Prompt Aid shuttle" variant="ghost" onPress={() => goToTarget(navigation, step.app)} style={{ marginTop: 8 }} /> : null}
        </PaCard>
      );
    case 'broadcast_to_doctors':
      if (hideConsult) return null;
      return (
        <PaCard style={{ borderWidth: 2, borderColor: pa.sats.orange }}>
          <Text style={styles.cardTitle}>{st.optional ? 'Also need a doctor?' : 'Speak to a doctor now'}</Text>
          <Text style={styles.body}>
            Your request goes to every available doctor at once — the first to accept takes your consult.
            {scenario.plan.channel_timing === 'after' ? ' You choose video, voice or WhatsApp once a doctor has accepted.' : ''}
          </Text>
          <PaButton
            title={st.button_label || 'Request a doctor now'}
            onPress={() => goToTarget(navigation, { tab: 'Home', screen: 'InstantConsult', params: { level: scenario.level, auto: true, ...(triageReference ? { triageReference } : {}) } })}
            style={{ marginTop: 12 }}
          />
        </PaCard>
      );
    case 'book_appointment':
    case 'find_care':
    case 'pharmacy':
    case 'health_article':
      return (
        <PaButton
          title={st.label || step.label}
          variant="ghost"
          onPress={() => {
            if (!goToTarget(navigation, step.app)) open(step.url);
          }}
        />
      );
    case 'self_care_advice':
      return (
        <PaCard>
          <Text style={styles.cardTitle}>{st.title || 'What to do right now'}</Text>
          {(step.lines ?? []).map((l, i) => (
            <Text key={i} style={styles.body}>
              {i + 1}. {l}
            </Text>
          ))}
        </PaCard>
      );
    case 'end':
      return st.message ? <Text style={styles.muted}>{st.message}</Text> : null;
    default:
      return null; // collect_payment / choose_channel / auto_start_channel run inside InstantConsult
  }
}

function CallStep({ step, autoDial }: { step: ScenarioStep; autoDial: boolean }) {
  const st = step.settings ?? {};
  const countdown = Number(st.countdown_seconds ?? 0);
  const [secs, setSecs] = useState<number | null>(autoDial && st.auto_dial && countdown > 0 && step.tel ? countdown : null);
  const timer = useRef<ReturnType<typeof setInterval> | null>(null);

  useEffect(() => {
    if (secs === null) return;
    timer.current = setInterval(() => {
      setSecs((s) => {
        if (s === null) return null;
        if (s <= 1) {
          if (timer.current) clearInterval(timer.current);
          open(step.tel);
          return null;
        }
        return s - 1;
      });
    }, 1000);
    return () => {
      if (timer.current) clearInterval(timer.current);
    };
    // Start once.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  if (!step.tel) return null;
  const others = (step.contacts ?? []).filter((c) => !c.primary);
  return (
    <View>
      <PaButton title={`${st.label || 'Call an ambulance now'} · ${step.phone}`} onPress={() => open(step.tel)} style={{ backgroundColor: pa.signal }} />
      {secs !== null ? (
        <Pressable
          onPress={() => {
            if (timer.current) clearInterval(timer.current);
            setSecs(null);
          }}
          style={{ marginTop: 6 }}
        >
          <Text style={styles.muted}>Dialling in {secs}s… tap to cancel</Text>
        </Pressable>
      ) : null}
      {others.length ? (
        <View style={styles.chips}>
          {others.map((c) => (
            <Pressable key={c.tel} onPress={() => open(c.tel)} style={styles.chip}>
              <Text style={styles.chipText}>
                {c.label} · {c.phone}
              </Text>
            </Pressable>
          ))}
        </View>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  note: { borderRadius: 10, padding: 12, borderWidth: 1 },
  danger: { backgroundColor: '#FBEAEA', borderColor: '#F2C4C4' },
  warning: { backgroundColor: '#FFF7E8', borderColor: '#F5C77E' },
  success: { backgroundColor: pa.goWash, borderColor: pa.go },
  info: { backgroundColor: pa.surface, borderColor: pa.line },
  noteTitle: { fontFamily: paFonts.black, fontSize: 15, color: pa.ink, marginBottom: 2 },
  noteBody: { fontFamily: paFonts.regular, fontSize: 14, color: pa.ink, lineHeight: 20 },
  cardTitle: { ...type.h3, color: pa.ink, marginBottom: 6 },
  body: { fontFamily: paFonts.regular, fontSize: 14, color: pa.inkSoft, lineHeight: 21 },
  muted: { fontFamily: paFonts.regular, fontSize: 13, color: pa.muted },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginTop: 8 },
  chip: { borderWidth: 1, borderColor: pa.line, borderRadius: 999, paddingHorizontal: 12, paddingVertical: 8, backgroundColor: pa.surface },
  chipText: { fontFamily: paFonts.bold, fontSize: 12, color: pa.ink },
});
