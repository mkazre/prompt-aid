import React, { useEffect, useState } from 'react';
import { ActivityIndicator, Image, Modal, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { Feather } from '@expo/vector-icons';
import { api } from '../api/client';
import { pa, paFonts } from '../theme';
import { W, wShadow } from './primitives';
import { shortDate } from './helpers';

export interface HealthArticleSummary {
  id: number;
  slug: string;
  title: string;
  category: string | null;
  excerpt: string | null;
  image: string | null;
  read_minutes: number;
  published_at: string | null;
}

/** Card used in the widget carousel and the full list. */
export function ArticleCard({ article, onPress, wide }: { article: HealthArticleSummary; onPress: () => void; wide?: boolean }) {
  return (
    <Pressable onPress={onPress} style={({ pressed }) => [r.card, wide ? { width: '100%' } : { width: 236 }, pressed && { opacity: 0.85 }]}>
      {article.image ? (
        <Image source={{ uri: article.image }} style={r.cardImg} />
      ) : (
        <View style={[r.cardImg, r.cardPh]}>
          <Feather name="book-open" size={26} color="#7A5C00" />
        </View>
      )}
      <View style={{ padding: 12 }}>
        {article.category ? <Text style={r.cat}>{article.category}</Text> : null}
        <Text style={r.cardTitle} numberOfLines={2}>{article.title}</Text>
        <View style={r.metaRow}>
          <Feather name="clock" size={12} color={pa.muted2} />
          <Text style={r.meta}>{article.read_minutes} min read</Text>
        </View>
      </View>
    </Pressable>
  );
}

/** Full article body — fetched by slug. */
export function ArticleBody({ slug }: { slug: string }) {
  const [article, setArticle] = useState<(HealthArticleSummary & { body: string | null }) | null>(null);
  const [error, setError] = useState(false);

  useEffect(() => {
    let alive = true;
    setArticle(null);
    setError(false);
    api.get(`/health-articles/${slug}`)
      .then(({ data }) => alive && setArticle(data.data))
      .catch(() => alive && setError(true));
    return () => { alive = false; };
  }, [slug]);

  if (error) return <Text style={r.muted}>Could not load this article. Pull to refresh and try again.</Text>;
  if (!article) return <ActivityIndicator color={pa.signal} style={{ marginTop: 40 }} />;

  const paragraphs = (article.body ?? '').split(/\n\s*\n/).filter((p) => p.trim());
  return (
    <View>
      {article.image ? <Image source={{ uri: article.image }} style={r.hero} /> : null}
      {article.category ? <Text style={r.cat}>{article.category}</Text> : null}
      <Text style={r.h1}>{article.title}</Text>
      <Text style={r.muted}>{article.read_minutes} min read{article.published_at ? ` · ${shortDate(article.published_at)}` : ''}</Text>
      {article.excerpt ? <Text style={r.lead}>{article.excerpt}</Text> : null}
      {paragraphs.map((p, i) => (
        <Text key={i} style={r.p}>{p.trim()}</Text>
      ))}
      <Text style={[r.muted, { marginTop: 18 }]}>General information, not a diagnosis. If you are worried, book a consult or start emergency triage.</Text>
    </View>
  );
}

/** Scrollable list of every published article. */
export function ArticleList({ onOpen }: { onOpen: (slug: string) => void }) {
  const [items, setItems] = useState<HealthArticleSummary[] | null>(null);
  useEffect(() => {
    api.get('/health-articles').then(({ data }) => setItems(data.data ?? [])).catch(() => setItems([]));
  }, []);
  if (!items) return <ActivityIndicator color={pa.signal} style={{ marginTop: 40 }} />;
  if (!items.length) return <Text style={r.muted}>No articles yet.</Text>;
  return (
    <View style={{ gap: 12 }}>
      {items.map((a) => <ArticleCard key={a.id} article={a} wide onPress={() => onOpen(a.slug)} />)}
    </View>
  );
}

/**
 * Full-screen modal reader: `slug` shows one article, `slug === '*'` the
 * list (tapping one drills in). Lives in a Modal so the widget works on any
 * screen without registering new navigator routes.
 */
export function HealthArticleModal({ slug, onClose }: { slug: string | null; onClose: () => void }) {
  const insets = useSafeAreaInsets();
  const [current, setCurrent] = useState<string | null>(slug);
  useEffect(() => setCurrent(slug), [slug]);

  const showingList = current === '*';
  const back = () => (slug === '*' && !showingList ? setCurrent('*') : onClose());

  return (
    <Modal visible={!!slug} animationType="slide" onRequestClose={back}>
      <View style={[r.modal, { paddingTop: insets.top }]}>
        <View style={r.bar}>
          <Pressable onPress={back} hitSlop={12} style={r.barBtn}>
            <Feather name={slug === '*' && !showingList ? 'arrow-left' : 'x'} size={22} color={pa.ink} />
          </Pressable>
          <Text style={r.barTitle}>Health articles</Text>
          <View style={r.barBtn} />
        </View>
        <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: insets.bottom + 40 }}>
          {current === null ? null : showingList ? <ArticleList onOpen={setCurrent} /> : <ArticleBody slug={current} />}
        </ScrollView>
      </View>
    </Modal>
  );
}

const r = StyleSheet.create({
  card: { backgroundColor: pa.surface, borderRadius: W.radius, overflow: 'hidden', ...wShadow },
  cardImg: { width: '100%', height: 120 },
  cardPh: { backgroundColor: pa.beaconWash, alignItems: 'center', justifyContent: 'center' },
  cat: { fontFamily: paFonts.black, fontSize: 10, letterSpacing: 1, textTransform: 'uppercase', color: W.blue, marginBottom: 4 },
  cardTitle: { fontFamily: paFonts.black, fontSize: 14, lineHeight: 18, color: W.ink },
  metaRow: { flexDirection: 'row', alignItems: 'center', gap: 5, marginTop: 8 },
  meta: { fontFamily: paFonts.regular, fontSize: 11, color: pa.muted2 },
  modal: { flex: 1, backgroundColor: pa.paper },
  bar: { flexDirection: 'row', alignItems: 'center', paddingHorizontal: 8, height: 52, backgroundColor: pa.surface, borderBottomWidth: 1, borderBottomColor: pa.line },
  barBtn: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center' },
  barTitle: { flex: 1, textAlign: 'center', fontFamily: paFonts.bold, fontSize: 16, color: pa.ink },
  hero: { width: '100%', height: 200, borderRadius: W.radius, marginBottom: 16 },
  h1: { fontFamily: paFonts.black, fontSize: 24, lineHeight: 29, color: pa.ink, marginBottom: 6 },
  lead: { fontFamily: paFonts.bold, fontSize: 16, lineHeight: 23, color: pa.inkSoft, marginTop: 14 },
  p: { fontFamily: paFonts.regular, fontSize: 15, lineHeight: 23, color: pa.ink, marginTop: 12 },
  muted: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted },
});
