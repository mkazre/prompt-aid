import React, { useCallback, useState } from 'react';
import { Pressable, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { api } from '../../api/client';
import { Appointment, LabRequest, PrescriptionUpload } from '../../api/types';
import { PaAvatar, PaBadge, PaQuickAction } from '../../components/pa';
import { pa, paFonts } from '../../theme';
import { useAuth } from '../../context/AuthContext';

export default function HomeScreen({ navigation }: any) {
  const { user } = useAuth();
  const [nextAppt, setNextAppt] = useState<Appointment | null>(null);
  const [labResults, setLabResults] = useState<LabRequest[]>([]);
  const [prescriptions, setPrescriptions] = useState<PrescriptionUpload[]>([]);
  const [loading, setLoading] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const [apptRes, labRes, presRes] = await Promise.all([
        api.get('/appointments'),
        api.get('/lab-requests').catch(() => ({ data: { data: [] } })),
        api.get('/prescriptions').catch(() => ({ data: [] })),
      ]);
      const upcoming = (apptRes.data.data as Appointment[])
        .filter((a) => !['completed', 'cancelled', 'no_show'].includes(a.status) && a.date >= new Date().toISOString().slice(0, 10))
        .sort((a, b) => a.date.localeCompare(b.date));
      setNextAppt(upcoming[0] ?? null);
      const results = (labRes.data.data as LabRequest[]).filter((l) => l.status === 'completed' && l.results?.length);
      setLabResults(results.slice(0, 2));
      const pending = (Array.isArray(presRes.data) ? presRes.data : presRes.data.data ?? []) as PrescriptionUpload[];
      setPrescriptions(pending.filter((p) => p.status === 'pending_review').slice(0, 1));
    } finally {
      setLoading(false);
    }
  }, []);

  useFocusEffect(useCallback(() => { load(); }, [load]));

  return (
    <ScrollView
      style={styles.container}
      contentContainerStyle={{ padding: 16, paddingTop: 56, paddingBottom: 40 }}
      refreshControl={<RefreshControl refreshing={loading} onRefresh={load} />}
    >
      <View style={styles.greetRow}>
        <View>
          <Text style={styles.greetSmall}>Good {timeOfDay()}</Text>
          <Text style={styles.greetName}>{user?.name?.split(' ')[0]}</Text>
        </View>
        <Pressable onPress={() => navigation.getParent()?.navigate('Profile')}>
          <PaAvatar name={user?.name} size={44} />
        </Pressable>
      </View>

      <Pressable style={styles.emergencyBanner} onPress={() => navigation.navigate('TriageStart')}>
        <View style={styles.blip} />
        <View style={{ flex: 1 }}>
          <Text style={styles.emergencyTitle}>Emergency? Start triage</Text>
          <Text style={styles.emergencySub}>Finds the nearest place that can treat it</Text>
        </View>
        <Text style={styles.emergencyArrow}>→</Text>
      </Pressable>

      {nextAppt ? (
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
            <Pressable style={styles.ghostBtn} onPress={() => navigation.getParent()?.navigate('Profile', { screen: 'Appointments' })}>
              <Text style={styles.ghostBtnText}>Details</Text>
            </Pressable>
          </View>
        </View>
      ) : null}

      <View style={styles.grid2}>
        <PaQuickAction number="01" label="Find a doctor" onPress={() => navigation.getParent()?.navigate('Care')} />
        <PaQuickAction number="02" label="Order medicine" onPress={() => navigation.getParent()?.navigate('Pharmacy')} />
        <PaQuickAction number="03" label="Book a shuttle" onPress={() => navigation.getParent()?.navigate('Rides')} />
        <PaQuickAction number="04" label="Upload a script" onPress={() => navigation.getParent()?.navigate('Pharmacy', { screen: 'ScriptUpload' })} />
      </View>

      {(labResults.length || prescriptions.length) ? (
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
  container: { flex: 1, backgroundColor: pa.paper },
  greetRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 18 },
  greetSmall: { fontSize: 13, color: pa.muted, fontFamily: paFonts.regular },
  greetName: { fontSize: 24, fontFamily: paFonts.black, color: pa.ink },
  emergencyBanner: { flexDirection: 'row', gap: 12, alignItems: 'center', backgroundColor: '#C8102E', padding: 15, marginBottom: 14, minHeight: 56 },
  blip: { width: 8, height: 8, borderRadius: 4, backgroundColor: '#fff' },
  emergencyTitle: { fontFamily: paFonts.black, fontSize: 15, color: '#fff' },
  emergencySub: { fontFamily: paFonts.regular, fontSize: 12, color: 'rgba(255,255,255,0.85)', marginTop: 2 },
  emergencyArrow: { color: '#fff', fontSize: 16 },
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
