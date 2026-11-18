import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { paFonts, paRadius } from '../../theme';
import { STATUS_COLOURS, isFeatureOn } from '../../services/siteContent';
import { Linking } from 'react-native';
import type { EmergencyScaleAction } from './triage-extras';
import type { TriageLevel } from '../../services/triage';
import { openScenarioEntry } from '../../services/scenarios';
import { getSiteContentSnapshot, telUrl } from '../../services/siteContent';

/**
 * Find care additions (Oct 2026 amendments): the practitioner's live
 * green/orange/red status pill, and the shared navigation for the
 * PromptAid Emergency Scale sheet's actions.
 */
export function PaLiveStatus({ status, label }: { status?: 'green' | 'orange' | 'red'; label?: string }) {
  if (!status) return null;
  const c = STATUS_COLOURS[status];
  return (
    <View style={[styles.pill, { backgroundColor: c.bg, borderColor: c.fg }]}>
      <View style={[styles.dot, { backgroundColor: c.fg }]} />
      <Text style={[styles.text, { color: c.fg }]}>{label ?? status}</Text>
    </View>
  );
}

/**
 * Routes an emergency-scale choice from any tab: orange → the "see a GP in
 * 30 min" screen (Home stack), yellow/green → Find care, blue → Find care
 * filtered to doctors who offer video.
 */
export function navigateForEmergencyAction(navigation: any, action: EmergencyScaleAction) {
  const tabs = navigation.getParent?.() ?? navigation;
  // Switched-off services (Staff → System → Features) fall back to Find care.
  if (action === 'doctorNow' && isFeatureOn('doctor_now')) tabs.navigate('Home', { screen: 'InstantConsult' });
  // Blue "Virtual / video consult" button: the "speak to a doctor now" page (choose video or call back first,
  // then the request goes to the available doctors) — scenario level 'virtual', no payment.
  else if (action === 'video' && isFeatureOn('video') && isFeatureOn('doctor_now')) tabs.navigate('Home', { screen: 'InstantConsult', params: { level: 'virtual', auto: false } });
  else if (action === 'video' && isFeatureOn('video')) tabs.navigate('Care', { screen: 'FindCare', params: { group: 'video' } });
  else tabs.navigate('Care', { screen: 'FindCare', params: { group: 'gp' } });
}

/**
 * A colour tapped on the emergency scale: run its patient request scenario
 * (Staff → Triage). Orange (default) starts the doctor request straight away —
 * the channel is chosen after a doctor accepts. Older servers / offline: the
 * built-in actions (red dials dispatch, orange "see a doctor now", others Find care).
 */
export async function openEmergencyLevel(navigation: any, level: TriageLevel) {
  if (level === 'orange' && !isFeatureOn('doctor_now')) {
    navigateForEmergencyAction(navigation, 'findCare');
    return;
  }
  const ok = await openScenarioEntry(navigation, level);
  if (ok) return;
  if (level === 'red') {
    Linking.openURL(telUrl(getSiteContentSnapshot().emergency.dispatch_phone)).catch(() => undefined);
    return;
  }
  navigateForEmergencyAction(navigation, level === 'orange' ? 'doctorNow' : 'findCare');
}

const styles = StyleSheet.create({
  pill: { flexDirection: 'row', alignItems: 'center', gap: 6, alignSelf: 'flex-start', borderWidth: 1, borderRadius: paRadius.sm, paddingHorizontal: 8, paddingVertical: 4 },
  dot: { width: 8, height: 8, borderRadius: 4 },
  text: { fontFamily: paFonts.black, fontSize: 11, letterSpacing: 0.4, textTransform: 'uppercase' },
});
