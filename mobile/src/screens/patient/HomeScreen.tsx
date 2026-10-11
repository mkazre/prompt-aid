import React, { useCallback, useEffect, useState } from 'react';
import { Linking, Pressable, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useFocusEffect } from '@react-navigation/native';
import { api } from '../../api/client';
import { Appointment, LabRequest, LatestTriage, PrescriptionUpload } from '../../api/types';
import { PaAvatar, PaBadge, PaQuickAction, PaSatsChip } from '../../components/pa';
import { pa, paFonts } from '../../theme';
import { useAuth } from '../../context/AuthContext';
import { META } from '../../services/triage';
import WidgetHost from '../../widgets/WidgetHost';
import CommsHeaderIcons from '../../components/CommsHeaderIcons';
import { TriagePromptModal } from '../../components/pa/triage-extras';
import { navigateForEmergencyAction } from '../../components/pa/care-extras';
import { STATUS_COLOURS, useSiteContent, websiteUrl } from '../../services/siteContent';

interface TopService { kind: string; group: string; id: number; name: string; rating_avg: number; rating_count: number }

// The PromptAid Emergency Scale opens once per app session, like the website popup.
let emergencyScaleShown = false;

export default function HomeScreen({ navigation }: any) {
  const insets = useSafeAreaInsets();
  const { user } = useAuth();
  const [nextAppt, setNextAppt] = useState<Appointment | null>(null);
  const [labResults, setLabResults] = useState<LabRequest[]>([]);
  const [prescriptions, setPrescriptions] = useState<PrescriptionUpload[]>([]);
  const [latestTriage, setLatestTriage] = useState<LatestTriage | null>(null);
  const [loading, setLoading] = useState(false);
  const [refreshKey, setRefreshKey] = useState(0);
  // Keys of admin-configured widgets that rendered; null while loading. A
  // hardcoded block below is hidden when the widget that replaces it is on.
  const [widgetKeys, setWidgetKeys] = useState<string[] | null>(null);
  const widgetOn = (key: string) => widgetKeys === null || widgetKeys.includes(key);
  const [scaleOpen, setScaleOpen] = useState(false);
  const [topServices, setTopServices] = useState<TopService[]>([]);
  const content = useSiteContent();

  useEffect(() => {
    if (!emergencyScaleShown) {
      emergencyScaleShown = true;
      setScaleOpen(true);
    }
  }, []);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      api.get('/top-rated').then(({ data }) => setTopServices(data.services ?? [])).catch(() => {});
      const [apptRes, labRes, presRes, triageRes] = await Promise.all([
        api.get('/appointments'),
        api.get('/lab-requests').catch(() => ({ data: { data: [] } })),
        api.get('/prescriptions').catch(() => ({ data: [] })),
        api.get('/triage/latest').catch(() => ({ data: { data: null } })),
      ]);
      const upcoming = (apptRes.data.data as Appointment[])
        .filter((a) => !['completed', 'cancelled', 'no_show'].includes(a.status) && a.date >= new Date().toISOString().slice(0, 10))
        .sort((a, b) => a.date.localeCompare(b.date));
      setNextAppt(upcoming[0] ?? null);
      const results = (labRes.data.data as LabRequest[]).filter((l) => l.status === 'completed' && l.results?.length);
      setLabResults(results.slice(0, 2));
      const pending = (Array.isArray(presRes.data) ? presRes.data : presRes.data.data ?? []) as PrescriptionUpload[];
      setPrescriptions(pending.filter((p) => p.status === 'pending_review').slice(0, 1));
      setLatestTriage((triageRes.data?.data as LatestTriage | null) ?? null);
    } finally {
      setLoading(false);
    }
  }, []);

  const triageIsRecent = latestTriage && Date.now() - new Date(latestTriage.created_at).getTime() < 24 * 60 * 60 * 1000;

  useFocusEffect(useCallback(() => { load(); }, [load]));

  return (
    <ScrollView
      style={styles.container}
      contentContainerStyle={{ padding: 16, paddingTop: insets.top + 16, paddingBottom: 40 }}
      refreshControl={<RefreshControl refreshing={loading} onRefresh={() => { load(); setRefreshKey((k) => k + 1); }} />}
    >
      <View style={styles.greetRow}>
        <View>
          <Text style={styles.greetSmall}>Good {timeOfDay()}</Text>
          <Text style={styles.greetName}>{user?.name?.split(' ')[0]}</Text>
        </View>
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 10 }}>
          <CommsHeaderIcons showMessages />
          <Pressable onPress={() => navigation.getParent()?.navigate('Profile')}>
            <PaAvatar name={user?.name} size={44} />
          </Pressable>
        </View>
      </View>

      <TriagePromptModal
        visible={scaleOpen}
        onDismiss={() => setScaleOpen(false)}
        onAction={(action) => navigateForEmergencyAction(navigation, action)}
      />

      <Text style={styles.slogan}>{content.home.eyebrow}</Text>
      <Text style={styles.headline}>{content.home.title}</Text>
      {content.home.lead ? <Text style={styles.lead}>{content.home.lead}</Text> : null}

      <Pressable style={styles.emergencyBanner} onPress={() => setScaleOpen(true)}>
        <View style={styles.blip} />
        <View style={{ flex: 1 }}>
          <Text style={styles.emergencyTitle}>Emergency?</Text>
          <Text style={styles.emergencySub}>{content.emergency.eyebrow} — get help, a doctor now, or book care</Text>
        </View>
        <Text style={styles.emergencyArrow}>→</Text>
      </Pressable>

      <WidgetHost screen="home" refreshKey={refreshKey} onLoaded={setWidgetKeys} style={{ marginBottom: 14 }} />

      {!widgetOn('patient_latest_triage') && triageIsRecent && latestTriage ? (
        <Pressable style={styles.triageCard} onPress={() => navigation.navigate('TriageStart', { selfReported: undefined })}>
          <View style={{ flex: 1 }}>
            <Text style={styles.triageEyebrow}>Your last triage result</Text>
            <Text style={styles.triageMeta}>{META[latestTriage.level].name} · {new Date(latestTriage.created_at).toLocaleDateString()}</Text>
          </View>
          <PaSatsChip level={latestTriage.level} />
        </Pressable>
      ) : null}

      {!widgetOn('patient_next_appointment') && nextAppt ? (
        <View style={styles.apptCard}>
          <View style={styles.apptEyebrowRow}>
            <View style={styles.beaconDot} />
            <Text style={styles.apptEyebrow}>Next appointment</Text>
          </View>
          <Text style={styles.apptDoctor}>{nextAppt.doctor?.name ?? 'Doctor'}</Text>
          <Text style={styles.apptMeta}>{nextAppt.date} {nextAppt.start_time?.slice(0, 5)} · {nextAppt.clinic?.name ?? 'Video consult'}</Text>
          <View style={styles.apptActions}>
            <Pressable style={styles.beaconBtn} onPress={() => navigation.getParent()?.navigate('Rides')}>
              <Text style={styles.beaconBtnText}>Track shuttle</Text>
            </Pressable>
            <Pressable style={styles.ghostBtn} onPress={() => navigation.navigate('Chat', { appointmentId: nextAppt.id, title: `Dr. ${nextAppt.doctor?.name ?? ''}` })}>
              <Text style={styles.ghostBtnText}>Message</Text>
            </Pressable>
            <Pressable style={styles.ghostBtn} onPress={() => navigation.getParent()?.navigate('Profile', { screen: 'Appointments' })}>
              <Text style={styles.ghostBtnText}>Details</Text>
            </Pressable>
          </View>
        </View>
      ) : null}

      {!widgetOn('patient_search_shortcuts') ? (
      <View style={styles.grid2}>
        <PaQuickAction number="01" label="Find a doctor" onPress={() => navigation.getParent()?.navigate('Care')} />
        <PaQuickAction number="02" label="Order medicine" onPress={() => navigation.getParent()?.navigate('Pharmacy')} />
        <PaQuickAction number="03" label="Book a shuttle" onPress={() => navigation.getParent()?.navigate('Rides')} />
        <PaQuickAction number="04" label="Upload a script" onPress={() => navigation.getParent()?.navigate('Pharmacy', { screen: 'ScriptUpload' })} />
      </View>
      ) : null}

      {!widgetOn('patient_pending_lab_results') && (labResults.length || prescriptions.length) ? (
        <>
          <Text style={styles.sectionLabel}>Waiting for you</Text>
          {labResults.map((l) => (
            <Pressable key={l.id} style={styles.row} onPress={() => navigation.getParent()?.navigate('Profile', { screen: 'LabResults' })}>
              <View style={{ flex: 1 }}>
                <Text style={styles.rowTitle}>Lab result ready</Text>
                <Text style={styles.rowSub}>{l.items?.[0]?.test_name ?? l.request_ref}</Text>
              </View>
              <PaBadge tone="go" label="New" />
            </Pressable>
          ))}
          {prescriptions.map((p) => (
            <View key={p.id} style={styles.row}>
              <View style={{ flex: 1 }}>
                <Text style={styles.rowTitle}>Script under review</Text>
                <Text style={styles.rowSub}>Uploaded {new Date(p.created_at).toLocaleDateString()}</Text>
              </View>
              <PaBadge tone="wait" label="Pending" />
            </View>
          ))}
        </>
      ) : null}
      <View style={styles.statusNote}>
        <View style={{ flexDirection: 'row', gap: 5, marginBottom: 6 }}>
          {(['green', 'orange', 'red'] as const).map((c) => <View key={c} style={[styles.statusDot, { backgroundColor: STATUS_COLOURS[c].fg }]} />)}
        </View>
        <Text style={styles.statusNoteText}>{content.home.status_note}</Text>
      </View>

      {topServices.length ? (
        <>
          <Text style={styles.sectionLabel}>{content.home.top_services_heading}</Text>
          {topServices.map((sv) => (
            <Pressable
              key={sv.kind}
              style={styles.row}
              onPress={() => sv.group === 'pharmacy' ? navigation.getParent()?.navigate('Pharmacy') : navigation.getParent()?.navigate('Care', { screen: 'FindCare', params: { group: sv.group } })}
            >
              <View style={{ flex: 1 }}>
                <Text style={styles.rowTitle}>{sv.name}</Text>
                <Text style={styles.rowSub}>{sv.kind}{sv.rating_count ? ` · ★ ${sv.rating_avg.toFixed(1)} (${sv.rating_count})` : ''}</Text>
              </View>
              <Text style={styles.rowArrow}>→</Text>
            </Pressable>
          ))}
        </>
      ) : null}

      <Text style={styles.sectionLabel}>{content.home.benefits_heading}</Text>
      {content.benefits.map((b, i) => (
        <Pressable key={i} style={styles.benefit} onPress={() => Linking.openURL(websiteUrl(`/about#benefit-${i + 1}`))}>
          <Text style={styles.benefitNum}>{String(i + 1).padStart(2, '0')}</Text>
          <View style={{ flex: 1 }}>
            <Text style={styles.rowTitle}>{b.title}</Text>
            {b.summary ? <Text style={styles.rowSub}>{b.summary}</Text> : null}
            <Text style={styles.learnMore}>Learn more →</Text>
          </View>
        </Pressable>
      ))}

      {content.topic ? (
        <View style={styles.topicCard}>
          <Text style={styles.topicEyebrow}>{content.home.topic_heading}</Text>
          <Text style={styles.topicTitle}>{content.topic.title ?? 'A new health topic is coming soon.'}</Text>
          <View style={styles.apptActions}>
            <Pressable style={styles.topicBtn} onPress={() => Linking.openURL(content.topic!.url)}>
              <Text style={styles.topicBtnText}>{content.home.topic_read_label}</Text>
            </Pressable>
            <Pressable style={[styles.ghostBtn, { borderColor: '#fff' }]} onPress={() => Linking.openURL(content.topic!.testimonial_url)}>
              <Text style={styles.ghostBtnText}>{content.home.topic_testimonial_label}</Text>
            </Pressable>
          </View>
        </View>
      ) : null}

    </ScrollView>
  );
}

function timeOfDay() {
  const h = new Date().getHours();
  if (h < 12) return 'morning';
  if (h < 18) return 'afternoon';
  return 'evening';
}

const styles = StyleSheet.create({
  slogan: { fontFamily: paFonts.black, fontSize: 11, letterSpacing: 1.2, color: pa.signal, marginBottom: 10 },
  headline: { fontFamily: paFonts.black, fontSize: 22, lineHeight: 26, color: pa.ink, marginBottom: 6 },
  lead: { fontFamily: paFonts.regular, fontSize: 13, color: pa.inkSoft, marginBottom: 14 },
  statusNote: { backgroundColor: pa.surface, borderWidth: 1, borderColor: pa.line, padding: 14, marginTop: 4, marginBottom: 14 },
  statusDot: { width: 9, height: 9, borderRadius: 5 },
  statusNoteText: { fontFamily: paFonts.regular, fontSize: 12, color: pa.inkSoft, lineHeight: 18 },
  topicCard: { backgroundColor: pa.signal, padding: 18, marginBottom: 14 },
  topicEyebrow: { fontFamily: paFonts.black, fontSize: 11, letterSpacing: 1.2, textTransform: 'uppercase', color: '#FBD5D3', marginBottom: 6 },
  topicTitle: { fontFamily: paFonts.black, fontSize: 17, color: '#fff', marginBottom: 14 },
  topicBtn: { flex: 1, backgroundColor: '#fff', paddingVertical: 12, alignItems: 'center' },
  topicBtnText: { fontFamily: paFonts.bold, fontSize: 13, color: pa.ink },
  rowArrow: { fontSize: 16, color: pa.muted },
  benefit: { flexDirection: 'row', gap: 12, paddingVertical: 12, borderBottomWidth: 1, borderBottomColor: pa.lineSoft },
  benefitNum: { fontFamily: paFonts.black, fontSize: 18, color: pa.signal, width: 28 },
  learnMore: { fontFamily: paFonts.bold, fontSize: 12, color: pa.signal, marginTop: 4 },
  container: { flex: 1, backgroundColor: pa.paper },
  greetRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 18 },
  greetSmall: { fontSize: 13, color: pa.muted, fontFamily: paFonts.regular },
  greetName: { fontSize: 24, fontFamily: paFonts.black, color: pa.ink },
  emergencyBanner: { flexDirection: 'row', gap: 12, alignItems: 'center', backgroundColor: '#C8102E', padding: 15, marginBottom: 14, minHeight: 56 },
  blip: { width: 8, height: 8, borderRadius: 4, backgroundColor: '#fff' },
  emergencyTitle: { fontFamily: paFonts.black, fontSize: 15, color: '#fff' },
  emergencySub: { fontFamily: paFonts.regular, fontSize: 12, color: 'rgba(255,255,255,0.85)', marginTop: 2 },
  emergencyArrow: { color: '#fff', fontSize: 16 },
  triageCard: { flexDirection: 'row', alignItems: 'center', gap: 12, backgroundColor: pa.surface, borderWidth: 1, borderColor: pa.line, padding: 14, marginBottom: 14 },
  triageEyebrow: { fontFamily: paFonts.black, fontSize: 11, letterSpacing: 1.2, textTransform: 'uppercase', color: pa.muted, marginBottom: 3 },
  triageMeta: { fontFamily: paFonts.bold, fontSize: 14, color: pa.ink },
  apptCard: { backgroundColor: pa.ink, padding: 18, marginBottom: 14 },
  apptEyebrowRow: { flexDirection: 'row', alignItems: 'center', gap: 8, marginBottom: 10 },
  beaconDot: { width: 6, height: 6, backgroundColor: pa.beacon },
  apptEyebrow: { fontFamily: paFonts.black, fontSize: 11, letterSpacing: 1.2, textTransform: 'uppercase', color: pa.beacon },
  apptDoctor: { fontFamily: paFonts.bold, fontSize: 17, color: '#fff' },
  apptMeta: { fontFamily: paFonts.regular, fontSize: 13, color: '#8A857C', marginTop: 3, marginBottom: 14 },
  apptActions: { flexDirection: 'row', gap: 8 },
  beaconBtn: { flex: 1, backgroundColor: pa.beacon, paddingVertical: 12, alignItems: 'center' },
  beaconBtnText: { fontFamily: paFonts.bold, fontSize: 13, color: pa.ink },
  ghostBtn: { flex: 1, borderWidth: 1, borderColor: '#4A4A4E', paddingVertical: 12, alignItems: 'center' },
  ghostBtnText: { fontFamily: paFonts.bold, fontSize: 13, color: '#fff' },
  grid2: { flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'space-between' },
  sectionLabel: { fontFamily: paFonts.black, fontSize: 11, letterSpacing: 1.2, textTransform: 'uppercase', color: pa.muted, marginTop: 18, marginBottom: 8 },
  row: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingVertical: 13, borderBottomWidth: 1, borderBottomColor: pa.lineSoft, gap: 10 },
  rowTitle: { fontFamily: paFonts.bold, fontSize: 14, color: pa.ink },
  rowSub: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted, marginTop: 2 },
});
