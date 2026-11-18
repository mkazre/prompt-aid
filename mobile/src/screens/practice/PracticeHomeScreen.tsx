import React, { useCallback, useEffect, useState } from 'react';
import { Alert, Pressable, RefreshControl, ScrollView, StyleSheet, Switch, Text, View } from 'react-native';
import { useFocusEffect, useNavigation } from '@react-navigation/native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useAuth } from '../../context/AuthContext';
import { PaButton, PaCard, PaSectionLabel } from '../../components/pa';
import { apiErrorMessage, getAvailability, saveAvailability } from '../../services/dispatch';
import { fmtDuration, listPracticeCalls } from '../../services/practiceCalls';
import { pa, paFonts } from '../../theme';

type CallRow = Awaited<ReturnType<typeof listPracticeCalls>>[number];

/**
 * Practice reception (role practice_reception): switch "Available now" on to
 * receive urgent (orange) requests for the practice — they arrive as the
 * full-screen alert (ProviderDispatchHost). Accept → the in-app call with the
 * patient rings (PracticeCallHost) → book on the call (PracticeCall screen).
 */
export default function PracticeHomeScreen() {
  const insets = useSafeAreaInsets();
  const navigation = useNavigation<any>();
  const { user, logout } = useAuth();
  const [online, setOnline] = useState(false);
  const [calls, setCalls] = useState<CallRow[]>([]);
  const [loading, setLoading] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const [av, list] = await Promise.all([getAvailability(), listPracticeCalls()]);
      setOnline(!!av.now);
      setCalls(list);
    } catch {
      /* keep last */
    } finally {
      setLoading(false);
    }
  }, []);

  useFocusEffect(
    useCallback(() => {
      load();
    }, [load]),
  );
  useEffect(() => {
    const t = setInterval(load, 15000);
    return () => clearInterval(t);
  }, [load]);

  async function toggle(v: boolean) {
    setOnline(v);
    try {
      const av = await saveAvailability({ now: v });
      setOnline(!!av.now);
    } catch (e) {
      setOnline(!v);
      Alert.alert('Not changed', apiErrorMessage(e));
    }
  }

  return (
    <ScrollView style={{ flex: 1, backgroundColor: pa.paper }} contentContainerStyle={{ padding: 16, paddingTop: insets.top + 16, paddingBottom: 40 }} refreshControl={<RefreshControl refreshing={loading} onRefresh={load} />}>
      <Text style={styles.eyebrow}>Practice reception</Text>
      <Text style={styles.title}>{user?.name}</Text>

      <PaCard style={{ marginTop: 12, borderLeftWidth: 6, borderLeftColor: online ? pa.go : pa.muted }}>
        <View style={styles.rowBetween}>
          <View style={{ flex: 1, paddingRight: 12 }}>
            <Text style={styles.cardTitle}>{online ? 'Available now' : 'Offline'}</Text>
            <Text style={styles.muted}>{online ? 'Urgent (orange) patients for your practice ring here with sound. The first practice to accept takes the patient.' : 'Switch on to receive urgent requests for your practice.'}</Text>
          </View>
          <Switch value={online} onValueChange={toggle} />
        </View>
      </PaCard>

      <PaSectionLabel style={{ marginTop: 18, marginBottom: 6 }}>Calls</PaSectionLabel>
      {calls.length === 0 ? <Text style={styles.muted}>No calls yet.</Text> : null}
      {calls.map((c) => (
        <Pressable key={c.id} onPress={() => navigation.navigate('PracticeCall', { callId: c.id })}>
          <PaCard style={{ marginBottom: 8 }}>
            <View style={styles.rowBetween}>
              <Text style={styles.cardTitle}>{c.patient_name ?? 'Patient'}</Text>
              <Text style={[styles.badge, { color: c.open ? pa.go : c.booked ? pa.info : pa.muted }]}>{c.open ? 'LIVE' : c.booked ? 'BOOKED' : c.status.toUpperCase()}</Text>
            </View>
            <Text style={styles.muted}>
              {c.ringing_at ? new Date(c.ringing_at).toLocaleString() : ''} · {fmtDuration(c.duration_seconds)} · {c.outcome_label}
            </Text>
          </PaCard>
        </Pressable>
      ))}

      <PaButton title="Sign out" variant="ghost" onPress={() => logout()} style={{ marginTop: 24 }} />
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  eyebrow: { fontFamily: paFonts.bold, fontSize: 11, letterSpacing: 1.4, textTransform: 'uppercase', color: pa.muted },
  title: { fontFamily: paFonts.black, fontSize: 24, color: pa.ink },
  cardTitle: { fontFamily: paFonts.black, fontSize: 16, color: pa.ink },
  muted: { fontFamily: paFonts.regular, fontSize: 13, color: pa.muted, marginTop: 2 },
  badge: { fontFamily: paFonts.black, fontSize: 12 },
  rowBetween: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
});
