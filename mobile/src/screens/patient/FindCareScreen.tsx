import React, { useCallback, useEffect, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useFocusEffect } from '@react-navigation/native';
import { api } from '../../api/client';
import { Doctor, Partner } from '../../api/types';
import { PaAvatar, PaBadge, PaEmptyState, PaInput } from '../../components/pa';
import { TriagePromptModal } from '../../components/pa/triage-extras';
import { PaLiveStatus, navigateForEmergencyAction } from '../../components/pa/care-extras';
import { CareTab, useSiteContent } from '../../services/siteContent';
import { pa, paFonts, paRadius } from '../../theme';

type Group = CareTab | 'video';

const DOCTOR_GROUPS: Group[] = ['gp', 'dentists', 'specialists', 'video'];
const TABS: CareTab[] = ['gp', 'dentists', 'specialists', 'allied', 'radiology', 'laboratories'];

/**
 * Find care — one tab per landing page, mirroring the website:
 * General Practitioners, Dentists, Specialists (GET /doctors?group=…) and
 * Allied, Radiology, Laboratories (GET /partners?group=…). `video` is the
 * Virtual consult list. Search is by name and/or speciality only.
 */
export default function FindCareScreen({ navigation, route }: any) {
  const insets = useSafeAreaInsets();
  const content = useSiteContent();
  const [group, setGroup] = useState<Group>(route?.params?.group ?? 'gp');
  const [search, setSearch] = useState('');
  const [doctors, setDoctors] = useState<Doctor[]>([]);
  const [partners, setPartners] = useState<Partner[]>([]);
  const [loading, setLoading] = useState(false);
  const [scaleOpen, setScaleOpen] = useState(false);

  useEffect(() => {
    if (route?.params?.group) setGroup(route.params.group);
  }, [route?.params?.group]);

  const isDoctors = DOCTOR_GROUPS.includes(group);

  const load = useCallback(async (q: string, g: Group) => {
    setLoading(true);
    try {
      const params: Record<string, string> = { group: g };
      if (q) params.search = q;
      if (DOCTOR_GROUPS.includes(g)) {
        const { data } = await api.get('/doctors', { params });
        setDoctors(data.data);
      } else {
        const { data } = await api.get('/partners', { params });
        setPartners(data.data);
      }
    } finally {
      setLoading(false);
    }
  }, []);

  useFocusEffect(useCallback(() => { load(search, group); }, [load, group]));

  const heading = group === 'video' ? content.care.virtual_heading : content.care.tabs[group].heading;
  const count = isDoctors ? doctors.length : partners.length;

  return (
    <View style={styles.container}>
      <TriagePromptModal visible={scaleOpen} onDismiss={() => setScaleOpen(false)} onAction={(a) => navigateForEmergencyAction(navigation, a)} />
      <View style={[styles.head, { paddingTop: insets.top + 14 }]}>
        <Text style={styles.title}>Find care</Text>
      </View>
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }}>
        <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.tabs}>
          {TABS.map((t) => (
            <Pressable key={t} onPress={() => setGroup(t)} style={[styles.tab, group === t && styles.tabOn]}>
              <Text style={[styles.tabText, group === t && { color: '#fff' }]}>{content.care.tabs[t].label}</Text>
            </Pressable>
          ))}
          <Pressable onPress={() => setGroup('video')} style={[styles.tab, group === 'video' && styles.tabOn]}>
            <Text style={[styles.tabText, group === 'video' && { color: '#fff' }]}>Virtual consult</Text>
          </Pressable>
        </ScrollView>

        <Text style={styles.heading}>{heading}</Text>

        <PaInput
          placeholder={isDoctors ? 'Name or speciality, e.g. Dr Smith or cardiologist' : 'Search by name'}
          value={search}
          onChangeText={setSearch}
          onSubmitEditing={() => load(search, group)}
          returnKeyType="search"
        />

        {isDoctors ? (
          <View style={styles.seeNow}>
            <View style={{ flex: 1 }}>
              <Text style={styles.seeNowTitle}>{content.care.see_now_title}</Text>
              <Text style={styles.seeNowText}>{content.care.see_now_text}</Text>
            </View>
            <Pressable onPress={() => setScaleOpen(true)} style={styles.seeNowBtn}>
              <Text style={styles.seeNowBtnText}>{content.care.see_now_button}</Text>
            </Pressable>
          </View>
        ) : null}

        <Text style={styles.count}><Text style={styles.countStrong}>{count}</Text> results{loading ? ' · loading…' : ''}</Text>

        {count === 0 && !loading ? (
          <PaEmptyState message="Nothing listed here yet." />
        ) : isDoctors ? (
          doctors.map((d) => (
            <Pressable key={d.id} onPress={() => navigation.navigate('DoctorDetail', { doctorId: d.id })} style={styles.card}>
              <View style={styles.cardTop}>
                <PaAvatar name={d.name} size={44} />
                <View style={{ flex: 1 }}>
                  <Text style={styles.name} numberOfLines={1}>{d.name}</Text>
                  <Text style={styles.spec}>{d.specialization}</Text>
                </View>
              </View>
              <View style={styles.cardFooter}>
                <PaLiveStatus status={d.live_status} label={d.live_status_label} />
                <Text style={styles.fee}>R {Math.round(d.consultation_fee)}</Text>
              </View>
            </Pressable>
          ))
        ) : (
          partners.map((p) => (
            <View key={p.id} style={styles.card}>
              <View style={styles.cardTop}>
                <PaAvatar name={p.company_name} size={44} round={false} />
                <View style={{ flex: 1 }}>
                  <Text style={styles.name} numberOfLines={1}>{p.company_name}</Text>
                  <Text style={styles.spec}>{p.service_type ?? p.category}</Text>
                </View>
              </View>
              <View style={styles.cardFooter}>
                <PaBadge tone={p.accepts_walk_ins ? 'go' : 'ink'} label={p.accepts_walk_ins ? 'Walk-in' : 'By appointment'} />
                {p.rating_count ? <Text style={styles.spec}>★ {p.rating_avg.toFixed(1)} ({p.rating_count})</Text> : null}
              </View>
              <Pressable
                onPress={() => navigation.getParent()?.navigate('Rides', { screen: 'ShuttleRequest', params: { to: p.company_name } })}
                style={styles.shuttle}
              >
                <Text style={styles.shuttleText}>Add a shuttle →</Text>
              </Pressable>
            </View>
          ))
        )}
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper },
  head: { paddingBottom: 14, paddingHorizontal: 16, backgroundColor: pa.surface, borderBottomWidth: 1, borderBottomColor: pa.line },
  title: { fontFamily: paFonts.black, fontSize: 20, color: pa.ink, textAlign: 'center' },
  tabs: { gap: 6, paddingBottom: 12 },
  tab: { borderWidth: 1, borderColor: pa.line, backgroundColor: pa.surface, borderRadius: paRadius.sm, paddingHorizontal: 12, paddingVertical: 9 },
  tabOn: { backgroundColor: pa.ink, borderColor: pa.ink },
  tabText: { fontFamily: paFonts.bold, fontSize: 13, color: pa.ink },
  heading: { fontFamily: paFonts.black, fontSize: 18, color: pa.ink, marginBottom: 10 },
  seeNow: { flexDirection: 'row', alignItems: 'center', gap: 10, backgroundColor: pa.ink, padding: 14, marginBottom: 12 },
  seeNowTitle: { fontFamily: paFonts.black, fontSize: 14, color: '#fff' },
  seeNowText: { fontFamily: paFonts.regular, fontSize: 12, color: '#B8B3A8', marginTop: 2 },
  seeNowBtn: { backgroundColor: pa.beacon, paddingHorizontal: 12, paddingVertical: 10 },
  seeNowBtnText: { fontFamily: paFonts.black, fontSize: 13, color: pa.ink },
  count: { fontSize: 12, color: pa.muted, marginTop: 4, marginBottom: 10 },
  countStrong: { color: pa.ink, fontFamily: paFonts.bold },
  card: { backgroundColor: pa.surface, borderWidth: 1, borderColor: pa.line, borderRadius: paRadius.sm, padding: 14, marginBottom: 10 },
  cardTop: { flexDirection: 'row', gap: 12, alignItems: 'center', marginBottom: 10 },
  name: { fontFamily: paFonts.bold, fontSize: 15, color: pa.ink },
  spec: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted, marginTop: 2 },
  cardFooter: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', borderTopWidth: 1, borderTopColor: pa.lineSoft, paddingTop: 10 },
  fee: { fontFamily: paFonts.black, fontSize: 17, color: pa.ink },
  shuttle: { marginTop: 10 },
  shuttleText: { fontFamily: paFonts.bold, fontSize: 13, color: pa.signal },
});
