import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Pressable, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useFocusEffect } from '@react-navigation/native';
import { api } from '../../api/client';
import { Doctor, Partner } from '../../api/types';
import { Ionicons } from '@expo/vector-icons';
import { PaAvatar, PaBadge, PaEmptyState } from '../../components/pa';
import { pa, paFonts, paRadius } from '../../theme';
import WidgetHost from '../../widgets/WidgetHost';
import { TriagePromptModal } from '../../components/pa/triage-extras';
import { PaLiveStatus, navigateForEmergencyAction } from '../../components/pa/care-extras';
import { CareTab, useSiteContent } from '../../services/siteContent';

type Group = CareTab | 'video' | 'all';
const DOCTOR_GROUPS: Group[] = ['all', 'gp', 'dentists', 'specialists', 'video'];
const TABS: CareTab[] = ['gp', 'dentists', 'specialists', 'allied', 'radiology', 'laboratories'];

interface Filters {
  search: string;
  specialization: string | null;
  clinic: { id: number; name: string } | null;
}

/**
 * Doctor search. Accepts `search`, `specialization`, `clinicId`/`clinicName`
 * route params (from home widgets and the search bar) and the same filters
 * from the "find_care" widgets on this screen (specialities row, nearby
 * clinics). Active filters show as removable chips.
 *
 * Tabs (General Practitioners, Dentists, Specialists, Allied, Radiology,
 * Laboratories, Virtual consult) mirror the website's Find care landing
 * pages: doctor groups hit GET /doctors?group=…, the others GET /partners.
 * Search is by name and/or speciality only.
 */
export default function FindCareScreen({ navigation, route }: any) {
  const insets = useSafeAreaInsets();
  const [search, setSearch] = useState('');
  const [filters, setFilters] = useState<Filters>({ search: '', specialization: null, clinic: null });
  const [doctors, setDoctors] = useState<Doctor[]>([]);
  const [loading, setLoading] = useState(false);
  const content = useSiteContent();
  const [group, setGroup] = useState<Group>(route?.params?.group ?? 'all');
  const [partners, setPartners] = useState<Partner[]>([]);
  const [scaleOpen, setScaleOpen] = useState(false);
  const isDoctors = DOCTOR_GROUPS.includes(group);
  const [refreshKey, setRefreshKey] = useState(0);
  const scrollRef = useRef<ScrollView>(null);
  const listY = useRef(0);

  const load = useCallback(async (f: Filters, g: Group) => {
    setLoading(true);
    try {
      const params: Record<string, string | number> = {};
      if (f.search) params.search = f.search;
      if (g !== 'all') params.group = g;
      if (DOCTOR_GROUPS.includes(g)) {
        if (f.specialization) params.specialization = f.specialization;
        if (f.clinic) params.clinic_id = f.clinic.id;
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

  useEffect(() => {
    if (route?.params?.group) {
      setGroup(route.params.group);
      navigation.setParams({ group: undefined });
    }
  }, [route?.params?.group]);

  // Params pushed by widgets / the home search bar.
  const p = route?.params ?? {};
  useEffect(() => {
    if (p.search === undefined && p.specialization === undefined && p.clinicId === undefined) return;
    const next: Filters = {
      search: typeof p.search === 'string' ? p.search : '',
      specialization: p.specialization ?? null,
      clinic: p.clinicId ? { id: Number(p.clinicId), name: p.clinicName ?? 'Clinic' } : null,
    };
    setSearch(next.search);
    setFilters(next);
    navigation.setParams({ search: undefined, specialization: undefined, clinicId: undefined, clinicName: undefined });
  }, [p.search, p.specialization, p.clinicId]);

  useFocusEffect(useCallback(() => { load(filters, group); }, [load, filters, group]));

  function apply(next: Partial<Filters>) {
    const merged = { ...filters, ...next };
    setFilters(merged);
    // Jump down to the results so the filter visibly did something.
    setTimeout(() => scrollRef.current?.scrollTo({ y: Math.max(0, listY.current - 12), animated: true }), 150);
  }

  const chips = [
    filters.specialization ? { label: filters.specialization, clear: () => apply({ specialization: null }) } : null,
    filters.clinic ? { label: filters.clinic.name, clear: () => apply({ clinic: null }) } : null,
    filters.search ? { label: `"${filters.search}"`, clear: () => { setSearch(''); apply({ search: '' }); } } : null,
  ].filter(Boolean) as { label: string; clear: () => void }[];

  return (
    <View style={styles.container}>
      <TriagePromptModal visible={scaleOpen} onDismiss={() => setScaleOpen(false)} onAction={(a) => navigateForEmergencyAction(navigation, a)} />
      <View style={[styles.head, { paddingTop: insets.top + 14 }]}>
        <Text style={styles.title}>Find care</Text>
      </View>
      <ScrollView
        ref={scrollRef}
        contentContainerStyle={{ padding: 16, paddingBottom: 40 }}
        refreshControl={<RefreshControl refreshing={loading} onRefresh={() => { load(filters, group); setRefreshKey((k) => k + 1); }} />}
      >
        {/* Opens search-as-you-type; picking a result comes back here with filters. */}
        <Pressable onPress={() => navigation.navigate('Search', { initial: search || undefined })} style={styles.searchBar} accessibilityRole="search" accessibilityLabel="Search">
          <Ionicons name="search" size={18} color={pa.muted} />
          <Text style={[styles.searchText, search ? { color: pa.ink } : null]} numberOfLines={1}>{search || 'Search by doctor name or speciality…'}</Text>
        </Pressable>

        <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.tabs}>
          {TABS.map((t) => (
            <Pressable key={t} onPress={() => setGroup(group === t ? 'all' : t)} style={[styles.tab, group === t && styles.tabOn]}>
              <Text style={[styles.tabText, group === t && { color: '#fff' }]}>{content.care.tabs[t].label}</Text>
            </Pressable>
          ))}
          <Pressable onPress={() => setGroup(group === 'video' ? 'all' : 'video')} style={[styles.tab, group === 'video' && styles.tabOn]}>
            <Text style={[styles.tabText, group === 'video' && { color: '#fff' }]}>Virtual consult</Text>
          </Pressable>
        </ScrollView>

        <View style={styles.seeNow}>
          <View style={{ flex: 1 }}>
            <Text style={styles.seeNowTitle}>{content.care.see_now_title}</Text>
            <Text style={styles.seeNowText}>{content.care.see_now_text}</Text>
          </View>
          <Pressable onPress={() => setScaleOpen(true)} style={styles.seeNowBtn}>
            <Text style={styles.seeNowBtnText}>{content.care.see_now_button}</Text>
          </Pressable>
        </View>

        <WidgetHost
          screen="find_care"
          refreshKey={refreshKey}
          style={{ marginBottom: 18 }}
          context={{
            onSpecialization: (name) => apply({ specialization: name }),
            onClinic: (clinic) => apply({ clinic }),
          }}
        />

        <View onLayout={(e) => { listY.current = e.nativeEvent.layout.y; }}>
          {chips.length ? (
            <View style={styles.chips}>
              {chips.map((c) => (
                <Pressable key={c.label} onPress={c.clear} style={styles.chip}>
                  <Text style={styles.chipText}>{c.label}  ✕</Text>
                </Pressable>
              ))}
            </View>
          ) : null}
          {group !== 'all' ? (
            <Text style={styles.groupHeading}>{group === 'video' ? content.care.virtual_heading : content.care.tabs[group].heading}</Text>
          ) : null}
          <Text style={styles.count}><Text style={styles.countStrong}>{isDoctors ? doctors.length : partners.length}</Text> providers{loading ? ' · loading…' : ''}</Text>

          {isDoctors ? (
            doctors.length === 0 && !loading ? (
              <PaEmptyState message="No providers found." />
            ) : (
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
                    {d.live_status ? (
                      <PaLiveStatus status={d.live_status} label={d.live_status_label} />
                    ) : (
                      <PaBadge tone={d.is_accepting_appointments ? 'go' : 'wait'} label={d.is_accepting_appointments ? 'Accepting bookings' : 'Not accepting'} />
                    )}
                    <Text style={styles.fee}>R {Math.round(d.consultation_fee)}</Text>
                  </View>
                </Pressable>
              ))
            )
          ) : partners.length === 0 && !loading ? (
            <PaEmptyState message="Nothing listed here yet." />
          ) : (
            partners.map((pt) => (
              <View key={pt.id} style={styles.card}>
                <View style={styles.cardTop}>
                  <PaAvatar name={pt.company_name} size={44} round={false} />
                  <View style={{ flex: 1 }}>
                    <Text style={styles.name} numberOfLines={1}>{pt.company_name}</Text>
                    <Text style={styles.spec}>{pt.service_type ?? pt.category}</Text>
                  </View>
                </View>
                <View style={styles.cardFooter}>
                  <PaBadge tone={pt.accepts_walk_ins ? 'go' : 'ink'} label={pt.accepts_walk_ins ? 'Walk-in' : 'By appointment'} />
                  {pt.rating_count ? <Text style={styles.spec}>★ {pt.rating_avg.toFixed(1)} ({pt.rating_count})</Text> : null}
                </View>
                <Pressable onPress={() => navigation.getParent()?.navigate('Rides', { screen: 'ShuttleRequest', params: { to: pt.company_name } })} style={{ marginTop: 10 }}>
                  <Text style={styles.shuttleText}>Add a shuttle →</Text>
                </Pressable>
              </View>
            ))
          )}
        </View>
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper },
  head: { paddingBottom: 14, paddingHorizontal: 16, backgroundColor: pa.surface, borderBottomWidth: 1, borderBottomColor: pa.line },
  title: { fontFamily: paFonts.black, fontSize: 20, color: pa.ink, textAlign: 'center' },
  searchBar: { flexDirection: 'row', alignItems: 'center', gap: 10, backgroundColor: pa.surface, borderRadius: 12, borderWidth: 1, borderColor: pa.line, paddingHorizontal: 14, height: 46, marginBottom: 14 },
  searchText: { flex: 1, fontFamily: paFonts.regular, fontSize: 14, color: pa.muted2 },
  count: { fontSize: 12, color: pa.muted, marginTop: 4, marginBottom: 10 },
  countStrong: { color: pa.ink, fontFamily: paFonts.bold },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginBottom: 6 },
  chip: { backgroundColor: pa.ink, borderRadius: 999, paddingHorizontal: 12, paddingVertical: 6 },
  chipText: { fontFamily: paFonts.bold, fontSize: 12, color: '#fff' },
  card: { backgroundColor: pa.surface, borderWidth: 1, borderColor: pa.line, borderRadius: paRadius.sm, padding: 14, marginBottom: 10 },
  cardTop: { flexDirection: 'row', gap: 12, alignItems: 'center', marginBottom: 10 },
  name: { fontFamily: paFonts.bold, fontSize: 15, color: pa.ink },
  spec: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted, marginTop: 2 },
  cardFooter: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', borderTopWidth: 1, borderTopColor: pa.lineSoft, paddingTop: 10 },
  fee: { fontFamily: paFonts.black, fontSize: 17, color: pa.ink },
  tabs: { gap: 6, paddingBottom: 12 },
  tab: { borderWidth: 1, borderColor: pa.line, backgroundColor: pa.surface, borderRadius: paRadius.sm, paddingHorizontal: 12, paddingVertical: 9 },
  tabOn: { backgroundColor: pa.ink, borderColor: pa.ink },
  tabText: { fontFamily: paFonts.bold, fontSize: 13, color: pa.ink },
  groupHeading: { fontFamily: paFonts.black, fontSize: 18, color: pa.ink, marginBottom: 4 },
  seeNow: { flexDirection: 'row', alignItems: 'center', gap: 10, backgroundColor: pa.ink, padding: 14, marginBottom: 14, borderRadius: 12 },
  seeNowTitle: { fontFamily: paFonts.black, fontSize: 14, color: '#fff' },
  seeNowText: { fontFamily: paFonts.regular, fontSize: 12, color: '#B8B3A8', marginTop: 2 },
  seeNowBtn: { backgroundColor: pa.beacon, paddingHorizontal: 12, paddingVertical: 10, borderRadius: 8 },
  seeNowBtnText: { fontFamily: paFonts.black, fontSize: 13, color: pa.ink },
  shuttleText: { fontFamily: paFonts.bold, fontSize: 13, color: pa.signal },
});
