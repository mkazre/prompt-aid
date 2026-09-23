import React, { useCallback, useState } from 'react';
import { FlatList, Linking, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { api, API_BASE_URL } from '../../api/client';
import { LabRequest } from '../../api/types';
import { PaBadge, PaEmptyState, PaRow } from '../../components/pa';
import { pa, paFonts, type } from '../../theme';

const STORAGE_BASE = API_BASE_URL.replace(/\/api\/?$/, '/storage/');

/**
 * Lab & diagnostic results — the "Results" screen reached from RecordScreen.
 * Matches `results.html`'s featured-latest-result + list pattern. The
 * mockup shows individual lab value rows (Haemoglobin / Ferritin / WCC with
 * low/normal badges) that the backend doesn't structure — `LabResult` only
 * carries a free-text `summary`, so that's rendered as-is under the featured
 * card instead of fabricating per-analyte rows.
 */
export default function LabResultsScreen() {
  const [requests, setRequests] = useState<LabRequest[]>([]);
  const [loading, setLoading] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const { data } = await api.get('/lab-requests');
      setRequests(data.data);
    } finally {
      setLoading(false);
    }
  }, []);

  useFocusEffect(useCallback(() => { load(); }, [load]));

  const withResults = requests.filter((r) => (r.results?.length ?? 0) > 0);
  const featured = withResults[0];
  const rest = withResults.slice(1);

  return (
    <View style={styles.container}>
      <Text style={[type.h2, { marginBottom: 12 }]}>Results</Text>
      <FlatList
        data={rest}
        keyExtractor={(r) => String(r.id)}
        contentContainerStyle={{ paddingBottom: 40 }}
        ListHeaderComponent={
          featured ? (
            <View style={styles.featured}>
              <View style={styles.featuredHeader}>
                <Text style={styles.featuredTitle}>{featured.items?.map((i) => i.test_name).join(', ') || featured.request_ref}</Text>
                <PaBadge tone="go" label="New" />
              </View>
              <Text style={styles.featuredMeta}>
                {featured.third_party?.company_name ?? 'Lab partner'} · released {formatDate(featured.requested_at)}
              </Text>
              {featured.results?.map((res) => (
                <Text
                  key={res.id}
                  onPress={() => Linking.openURL(`${STORAGE_BASE}${res.file_path}`)}
                  style={styles.resultLink}
                >
                  {res.label}{res.summary ? ` — ${res.summary}` : ''} · View report
                </Text>
              ))}
            </View>
          ) : null
        }
        ListEmptyComponent={!loading && !featured ? <PaEmptyState message="No lab results yet." /> : null}
        renderItem={({ item }) => (
          <PaRow
            title={item.items?.map((i) => i.test_name).join(', ') || item.request_ref}
            subtitle={formatDate(item.requested_at)}
            right={<PaBadge status={item.status} />}
          />
        )}
      />
    </View>
  );
}

function formatDate(iso?: string | null) {
  if (!iso) return '—';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return d.toLocaleDateString('en-ZA', { day: '2-digit', month: 'short' });
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper, padding: 16 },
  featured: { backgroundColor: pa.surface, borderWidth: 1, borderColor: pa.line, borderRadius: 2, padding: 16, marginBottom: 8 },
  featuredHeader: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 },
  featuredTitle: { fontFamily: paFonts.bold, fontSize: 15, color: pa.ink, flex: 1, marginRight: 8 },
  featuredMeta: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted, marginBottom: 12 },
  resultLink: { fontFamily: paFonts.bold, fontSize: 13, color: pa.signal, paddingVertical: 6, borderTopWidth: 1, borderTopColor: pa.lineSoft, marginTop: 4 },
});
