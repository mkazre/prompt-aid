import React, { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Image, Keyboard, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { api } from '../../api/client';
import { SearchGroups, SearchResult } from '../../api/types';
import { PaAvatar } from '../../components/pa';
import { IconName, R } from '../../components/settings-ui';
import { pa, paFonts } from '../../theme';

const RECENT_KEY = 'pa_recent_searches';
const MAX_RECENT = 8;

const GROUPS: { key: keyof SearchGroups; title: string }[] = [
  { key: 'specialities', title: 'Specialities' },
  { key: 'conditions', title: 'Conditions & Symptoms' },
  { key: 'doctors', title: 'Doctors' },
  { key: 'clinics', title: 'Clinics' },
  { key: 'products', title: 'Pharmacy' },
];

const EMPTY: SearchGroups = { specialities: [], conditions: [], doctors: [], clinics: [], products: [] };

/**
 * Search as you type — GET /search?q= (debounced 250ms), grouped results
 * with the matched text in bold. Recent searches are kept on the device.
 * Routes across tabs so it works from both Home and Find care.
 */
export default function SearchScreen({ navigation, route }: any) {
  const insets = useSafeAreaInsets();
  const [q, setQ] = useState<string>(route?.params?.initial ?? '');
  const [groups, setGroups] = useState<SearchGroups>(EMPTY);
  const [loading, setLoading] = useState(false);
  const [failed, setFailed] = useState(false);
  const [recent, setRecent] = useState<string[]>([]);
  const reqId = useRef(0);

  useEffect(() => {
    AsyncStorage.getItem(RECENT_KEY)
      .then((raw) => setRecent(raw ? (JSON.parse(raw) as string[]).filter((x) => typeof x === 'string') : []))
      .catch(() => setRecent([]));
  }, []);

  useEffect(() => {
    const term = q.trim();
    if (term.length < 2) {
      reqId.current++;
      setGroups(EMPTY);
      setLoading(false);
      setFailed(false);
      return;
    }
    const id = ++reqId.current;
    setLoading(true);
    const t = setTimeout(() => {
      api
        .get('/search', { params: { q: term } })
        .then(({ data }) => {
          if (id === reqId.current) {
            setGroups({ ...EMPTY, ...(data.groups ?? {}) });
            setFailed(false);
          }
        })
        .catch(() => {
          if (id === reqId.current) setFailed(true);
        })
        .finally(() => {
          if (id === reqId.current) setLoading(false);
        });
    }, 250);
    return () => clearTimeout(t);
  }, [q]);

  async function remember(term: string) {
    const clean = term.trim();
    if (clean.length < 2) return;
    const next = [clean, ...recent.filter((r) => r.toLowerCase() !== clean.toLowerCase())].slice(0, MAX_RECENT);
    setRecent(next);
    try {
      await AsyncStorage.setItem(RECENT_KEY, JSON.stringify(next));
    } catch {
      // non-critical
    }
  }

  async function clearRecent() {
    setRecent([]);
    try {
      await AsyncStorage.removeItem(RECENT_KEY);
    } catch {
      // non-critical
    }
  }

  /** Tab navigator; pops this screen first so the originating stack returns to its root. */
  function tabs() {
    if (navigation.canGoBack()) navigation.popToTop();
    return navigation.getParent() ?? navigation;
  }

  function open(item: SearchResult) {
    Keyboard.dismiss();
    remember(q);

    switch (item.type) {
      case 'speciality':
      case 'service_category':
        tabs().navigate('Care', { screen: 'FindCare', params: { specialization: item.specialization ?? item.name } });
        return;
      case 'condition':
      case 'symptom':
        if (item.urgent || !item.specialization) {
          tabs().navigate('Home', { screen: 'TriageStart', initial: false });
        } else {
          tabs().navigate('Care', { screen: 'FindCare', params: { specialization: item.specialization } });
        }
        return;
      case 'doctor':
        tabs().navigate('Care', { screen: 'DoctorDetail', params: { doctorId: item.id }, initial: false });
        return;
      case 'clinic':
        tabs().navigate('Care', { screen: 'FindCare', params: { clinicId: item.id, clinicName: item.name } });
        return;
      case 'product':
        if (item.product) tabs().navigate('Pharmacy', { screen: 'ProductDetail', params: { product: item.product }, initial: false });
        return;
    }
  }

  function submit() {
    const term = q.trim();
    if (term.length < 2) return;
    remember(term);
    tabs().navigate('Care', { screen: 'FindCare', params: { search: term } });
  }

  const term = q.trim();
  const total = GROUPS.reduce((n, g) => n + groups[g.key].length, 0);

  return (
    <View style={[styles.container, { paddingTop: insets.top + 8 }]}>
      <View style={styles.bar}>
        <View style={styles.inputWrap}>
          <Ionicons name="search" size={18} color={pa.muted} />
          <TextInput
            autoFocus
            value={q}
            onChangeText={setQ}
            onSubmitEditing={submit}
            placeholder="Search doctor, speciality, symptom…"
            placeholderTextColor={pa.muted2}
            returnKeyType="search"
            autoCorrect={false}
            style={styles.input}
            accessibilityLabel="Search"
          />
          {q.length ? (
            <Pressable onPress={() => setQ('')} hitSlop={10} accessibilityLabel="Clear search">
              <Ionicons name="close-circle" size={18} color={pa.muted2} />
            </Pressable>
          ) : null}
        </View>
        <Pressable onPress={() => navigation.goBack()} hitSlop={8}>
          <Text style={styles.cancel}>Cancel</Text>
        </Pressable>
      </View>

      <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={{ paddingHorizontal: 16, paddingBottom: insets.bottom + 32 }}>
        {term.length < 2 ? (
          recent.length ? (
            <View>
              <View style={styles.groupHead}>
                <Text style={styles.groupTitle}>Recent searches</Text>
                <Pressable onPress={clearRecent} hitSlop={8}>
                  <Text style={styles.link}>Clear</Text>
                </Pressable>
              </View>
              {recent.map((r) => (
                <Pressable key={r} onPress={() => setQ(r)} style={styles.item}>
                  <Ionicons name="time-outline" size={18} color={pa.muted} />
                  <Text style={styles.itemText}>{r}</Text>
                  <Ionicons name="arrow-up-outline" size={16} color={pa.muted2} style={{ transform: [{ rotate: '-45deg' }] }} />
                </Pressable>
              ))}
            </View>
          ) : (
            <Text style={styles.hint}>Type at least 2 letters to search doctors, specialities, conditions, clinics and pharmacy products.</Text>
          )
        ) : failed ? (
          <Text style={styles.hint}>Search is unavailable right now. Check your connection and try again.</Text>
        ) : !loading && total === 0 ? (
          <Text style={styles.hint}>No results for “{term}”.</Text>
        ) : (
          <>
            {loading && total === 0 ? <ActivityIndicator color={pa.signal} style={{ marginTop: 24 }} /> : null}
            {GROUPS.map((g) =>
              groups[g.key].length ? (
                <View key={g.key}>
                  <Text style={[styles.groupTitle, styles.groupHead]}>{g.title}</Text>
                  {groups[g.key].map((item, i) => (
                    <ResultRow key={`${g.key}-${item.id ?? item.name}-${i}`} item={item} term={term} onPress={() => open(item)} />
                  ))}
                </View>
              ) : null
            )}
          </>
        )}
      </ScrollView>
    </View>
  );
}

function ResultRow({ item, term, onPress }: { item: SearchResult; term: string; onPress: () => void }) {
  const icon: IconName =
    item.type === 'speciality' || item.type === 'service_category'
      ? 'medkit-outline'
      : item.type === 'clinic'
        ? 'business-outline'
        : item.type === 'product'
          ? 'bag-handle-outline'
          : item.urgent
            ? 'warning-outline'
            : 'pulse-outline';

  return (
    <Pressable onPress={onPress} style={({ pressed }) => [styles.item, { opacity: pressed ? 0.6 : 1 }]}>
      {item.type === 'doctor' ? (
        item.avatar_url ? <Image source={{ uri: item.avatar_url }} style={styles.avatar} /> : <PaAvatar name={item.name} size={34} />
      ) : (
        <View style={[styles.iconBubble, item.urgent && { backgroundColor: pa.signalWash }]}>
          <Ionicons name={icon} size={17} color={item.urgent ? pa.signal : pa.info} />
        </View>
      )}
      <View style={{ flex: 1 }}>
        <Highlight text={item.name} term={term} />
        {item.subtitle ? <Text style={styles.sub} numberOfLines={1}>{item.subtitle}</Text> : null}
      </View>
      {item.type === 'doctor' && item.rating_count ? (
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 3 }}>
          <Ionicons name="star" size={12} color={pa.beacon} />
          <Text style={styles.sub}>{item.rating_avg?.toFixed(1)}</Text>
        </View>
      ) : null}
    </Pressable>
  );
}

/** Renders `text` with every case-insensitive occurrence of `term` in bold. */
function Highlight({ text, term }: { text: string; term: string }) {
  const parts: { t: string; m: boolean }[] = [];
  const lower = text.toLowerCase();
  const needle = term.toLowerCase();
  let i = 0;
  while (needle && i < text.length) {
    const at = lower.indexOf(needle, i);
    if (at === -1) break;
    if (at > i) parts.push({ t: text.slice(i, at), m: false });
    parts.push({ t: text.slice(at, at + needle.length), m: true });
    i = at + needle.length;
  }
  if (i < text.length) parts.push({ t: text.slice(i), m: false });

  return (
    <Text style={styles.itemText} numberOfLines={1}>
      {parts.map((p, k) => (
        <Text key={k} style={p.m ? styles.bold : undefined}>{p.t}</Text>
      ))}
    </Text>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.surface },
  bar: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingHorizontal: 16, paddingBottom: 10, borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: pa.line },
  inputWrap: { flex: 1, flexDirection: 'row', alignItems: 'center', gap: 8, backgroundColor: pa.paper, borderRadius: R.input, paddingHorizontal: 12, height: 44 },
  input: { flex: 1, fontFamily: paFonts.regular, fontSize: 15, color: pa.ink, paddingVertical: 0 },
  cancel: { fontFamily: paFonts.bold, fontSize: 15, color: pa.signal },
  groupHead: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginTop: 18, marginBottom: 4 },
  groupTitle: { fontFamily: paFonts.black, fontSize: 13, color: pa.muted, letterSpacing: 0.4 },
  link: { fontFamily: paFonts.bold, fontSize: 13, color: pa.signal },
  item: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 11, borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: pa.lineSoft },
  itemText: { fontFamily: paFonts.regular, fontSize: 15, color: pa.ink },
  bold: { fontFamily: paFonts.black, color: pa.ink },
  sub: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted, marginTop: 2 },
  iconBubble: { width: 34, height: 34, borderRadius: 17, backgroundColor: '#EAF1F8', alignItems: 'center', justifyContent: 'center' },
  avatar: { width: 34, height: 34, borderRadius: 17 },
  hint: { fontFamily: paFonts.regular, fontSize: 13, color: pa.muted, textAlign: 'center', marginTop: 32, lineHeight: 19 },
});
