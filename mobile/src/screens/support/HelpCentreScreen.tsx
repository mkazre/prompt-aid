import React, { useEffect, useMemo, useState } from 'react';
import { FlatList, Linking, Pressable, RefreshControl, StyleSheet, Text, TextInput, View } from 'react-native';
import { Feather } from '@expo/vector-icons';
import { useNavigation } from '@react-navigation/native';
import { apiErrorMessage } from '../../api/client';
import { Faq, fetchFaqs } from '../../api/support';
import { PaEmptyState } from '../../components/pa';
import { pa, paFonts } from '../../theme';

/** Help centre: admin-managed FAQs (filtered to the user's role) with instant search. */
export default function HelpCentreScreen() {
  const navigation = useNavigation<any>();
  const [faqs, setFaqs] = useState<Faq[]>([]);
  const [intro, setIntro] = useState('');
  const [webUrl, setWebUrl] = useState('');
  const [q, setQ] = useState('');
  const [open, setOpen] = useState<number | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  async function load() {
    setLoading(true);
    try {
      const r = await fetchFaqs();
      setFaqs(r.faqs);
      setIntro(r.intro);
      setWebUrl(r.webUrl);
      setError(null);
    } catch (e) {
      setError(apiErrorMessage(e, 'Could not load the help centre.'));
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => { load(); }, []);

  const filtered = useMemo(() => {
    const term = q.trim().toLowerCase();
    if (!term) return faqs;
    return faqs.filter((f) => (f.question + ' ' + f.answer + ' ' + (f.category?.name ?? '')).toLowerCase().includes(term));
  }, [faqs, q]);

  return (
    <FlatList
      style={{ flex: 1, backgroundColor: pa.paper }}
      data={filtered}
      keyExtractor={(f) => String(f.id)}
      keyboardShouldPersistTaps="handled"
      contentContainerStyle={{ padding: 16, paddingBottom: 48 }}
      refreshControl={<RefreshControl refreshing={loading} onRefresh={load} />}
      ListHeaderComponent={
        <View>
          {intro ? <Text style={styles.intro}>{intro}</Text> : null}
          <View style={styles.search}>
            <Feather name="search" size={16} color={pa.muted} />
            <TextInput style={styles.searchInput} value={q} onChangeText={setQ} placeholder="Search e.g. cancel, shuttle, medical aid" placeholderTextColor={pa.muted2} returnKeyType="search" />
            {q ? <Pressable onPress={() => setQ('')} hitSlop={8}><Feather name="x" size={16} color={pa.muted} /></Pressable> : null}
          </View>
          {error ? <Text style={styles.error}>{error}</Text> : null}
        </View>
      }
      ListEmptyComponent={!loading ? <PaEmptyState message={q ? 'No answers match that search.' : 'No help articles yet.'} /> : null}
      renderItem={({ item: f, index }) => {
        const isOpen = open === f.id;
        const showCat = f.category?.name && (index === 0 || filtered[index - 1]?.category?.id !== f.category?.id);
        return (
          <View>
            {showCat ? <Text style={styles.cat}>{f.category!.name}</Text> : null}
            <Pressable style={styles.item} onPress={() => setOpen(isOpen ? null : f.id)}>
              <View style={styles.qRow}>
                <Text style={styles.q}>{f.question}</Text>
                <Feather name={isOpen ? 'minus' : 'plus'} size={18} color={pa.muted} />
              </View>
              {isOpen ? <Text style={styles.a}>{f.answer}</Text> : null}
            </Pressable>
          </View>
        );
      }}
      ListFooterComponent={
        <View style={styles.footer}>
          <Text style={styles.footerTitle}>Still stuck?</Text>
          <Pressable style={styles.footerBtn} onPress={() => navigation.navigate('SupportNew')}>
            <Text style={styles.footerBtnText}>Contact support</Text>
          </Pressable>
          {webUrl ? (
            <Pressable onPress={() => Linking.openURL(webUrl)} hitSlop={8}>
              <Text style={styles.web}>Open the help centre on the website</Text>
            </Pressable>
          ) : null}
        </View>
      }
    />
  );
}

const styles = StyleSheet.create({
  intro: { fontFamily: paFonts.regular, fontSize: 13, color: pa.inkSoft, lineHeight: 19, marginBottom: 12 },
  search: { flexDirection: 'row', alignItems: 'center', gap: 8, borderWidth: 1, borderColor: pa.line, borderRadius: 10, backgroundColor: pa.surface, paddingHorizontal: 12, marginBottom: 12 },
  searchInput: { flex: 1, paddingVertical: 11, fontFamily: paFonts.regular, fontSize: 14, color: pa.ink },
  error: { fontFamily: paFonts.bold, fontSize: 13, color: pa.signal, marginBottom: 10 },
  cat: { fontFamily: paFonts.black, fontSize: 11, letterSpacing: 1.4, textTransform: 'uppercase', color: pa.muted, marginTop: 14, marginBottom: 6 },
  item: { backgroundColor: pa.surface, borderWidth: 1, borderColor: pa.line, borderRadius: 10, padding: 14, marginBottom: 6 },
  qRow: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  q: { flex: 1, fontFamily: paFonts.bold, fontSize: 14, color: pa.ink },
  a: { fontFamily: paFonts.regular, fontSize: 14, color: pa.inkSoft, lineHeight: 20, marginTop: 10 },
  footer: { alignItems: 'center', marginTop: 24, gap: 10 },
  footerTitle: { fontFamily: paFonts.black, fontSize: 16, color: pa.ink },
  footerBtn: { backgroundColor: pa.signal, borderRadius: 10, paddingHorizontal: 20, paddingVertical: 12 },
  footerBtnText: { fontFamily: paFonts.black, fontSize: 14, color: '#fff' },
  web: { fontFamily: paFonts.bold, fontSize: 12, color: pa.info, textDecorationLine: 'underline' },
});
