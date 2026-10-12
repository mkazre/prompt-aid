import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { paFonts, paRadius } from '../../theme';
import { STATUS_COLOURS, isFeatureOn } from '../../services/siteContent';
import type { EmergencyScaleAction } from './triage-extras';

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
  else if (action === 'video' && isFeatureOn('video')) tabs.navigate('Care', { screen: 'FindCare', params: { group: 'video' } });
  else tabs.navigate('Care', { screen: 'FindCare', params: { group: 'gp' } });
}

const styles = StyleSheet.create({
  pill: { flexDirection: 'row', alignItems: 'center', gap: 6, alignSelf: 'flex-start', borderWidth: 1, borderRadius: paRadius.sm, paddingHorizontal: 8, paddingVertical: 4 },
  dot: { width: 8, height: 8, borderRadius: 4 },
  text: { fontFamily: paFonts.black, fontSize: 11, letterSpacing: 0.4, textTransform: 'uppercase' },
});
