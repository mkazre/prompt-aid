import React, { useCallback, useMemo, useState } from 'react';
import { Alert, FlatList, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { api, apiErrorMessage } from '../../api/client';
import { LabRequest } from '../../api/types';
import { useAuth } from '../../context/AuthContext';
import { pa, paFonts, type } from '../../theme';
import { PaBadge, PaButton, PaCard, PaEmptyState, PaEyebrow } from '../../components/pa';
import { PaChipRow, categoryEmoji, categoryLabel, categoryNoun, elapsedLabel } from '../../components/pa/partner-extras';

export default function ThirdPartyHomeScreen({ navigation }: any) {
  const { user } = useAuth();
  const profile = user?.third_party_profile;
  const [available, setAvailable] = useState<LabRequest[]>([]);
  const [mine, setMine] = useState<LabRequest[]>([]);
  const [loading, setLoading] = useState(false);
  const [filter, setFilter] = useState('all');

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const [avRes, mineRes] = await Promise.all([
        api.get('/third-party/lab-requests/available'),
        api.get('/third-party/lab-requests'),
      ]);
      setAvailable(avRes.data.data);
      setMine((mineRes.data.data as LabRequest[]).filter((r) => !['completed', 'cancelled'].includes(r.status)));
    } finally {
      setLoading(false);
    }
  }, []);

  useFocusEffect(useCallback(() => { load(); }, [load]));

  async function accept(request: LabRequest) {
    try {
      await api.post(`/third-party/lab-requests/${request.id}/accept`);
      await load();
      navigation.navigate('RequestDetail', { requestId: request.id });
    } catch (e) {
      Alert.alert('Could not accept', apiErrorMessage(e));
    }
  }

  const urgentCount = available.filter((r) => r.priority === 'urgent').length;
  const chips = useMemo(
    () => [
      { label: `All ${available.length}`, value: 'all' },
      { label: `Urgent ${urgentCount}`, value: 'urgent' },
    ],
    [available.length, urgentCount],
  );
  const filtered = filter === 'urgent' ? available.filter((r) => r.priority === 'urgent') : available;

  return (
    <View style={styles.container}>
      <PaEyebrow>{categoryLabel(profile?.category)} PARTNER QUEUE</PaEyebrow>
      <Text style={styles.title}>Hi {user?.name?.split(' ')[0]} {categoryEmoji(profile?.category)}</Text>
      <Text style={styles.subtitle}>
        New {categoryNoun(profile?.category, true)} from Prompt Aid doctors{profile?.company_name ? ` for ${profile.company_name}` : ''}.
      </Text>

      <FlatList
        data={filtered}
        keyExtractor={(r) => String(r.id)}
        refreshControl={<RefreshControl refreshing={loading} onRefresh={load} />}
        ListHeaderComponent={
          <>
            {mine.length > 0 && (
              <>
                <Text style={styles.sectionTitle}>In progress</Text>
                {mine.map((req) => (
                  <Pressable key={req.id} onPress={() => navigation.navigate('RequestDetail', { requestId: req.id })}>
                    <PaCard style={styles.card}>
                      <View style={styles.rowBetween}>
                        <Text style={styles.patientName}>{req.patient?.user?.name ?? 'Patient'}</Text>
                        <PaBadge status={req.status} />
                      </View>
                      <Text style={styles.meta}>{req.items?.map((i) => i.test_name).join(', ')}</Text>
                    </PaCard>
                  </Pressable>
                ))}
              </>
            )}
            <Text style={styles.sectionTitle}>Available {categoryNoun(profile?.category, true)}</Text>
            <PaChipRow chips={chips} active={filter} onChange={setFilter} />
          </>
        }
        ListEmptyComponent={!loading ? <PaEmptyState message={`No open ${categoryNoun(profile?.category, true)} right now.`} /> : null}
        renderItem={({ item }) => (
          <Pressable onPress={() => navigation.navigate('RequestDetail', { requestId: item.id })}>
            <PaCard style={styles.card}>
              <View style={styles.rowBetween}>
                <Text style={styles.patientName}>{item.patient?.user?.name ?? 'Patient'}</Text>
                {item.priority === 'urgent' ? <PaBadge label="Urgent" tone="stop" /> : <PaBadge label={elapsedLabel(item.requested_at)} tone="wait" />}
              </View>
              <Text style={styles.meta}>Dr. {item.doctor?.name} · {item.items?.map((i) => i.test_name).join(', ')}</Text>
              {item.collection_address ? <Text style={styles.meta}>📍 {item.collection_address}</Text> : null}
              <View style={{ marginTop: 12 }}>
                <PaButton title={`Accept ${categoryNoun(profile?.category)}`} onPress={() => accept(item)} />
              </View>
            </PaCard>
          </Pressable>
        )}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper, padding: 20 },
  title: { ...type.h2, color: pa.ink, marginBottom: 4 },
  subtitle: { fontFamily: paFonts.regular, fontSize: 13, color: pa.muted, marginBottom: 16 },
  sectionTitle: { ...type.label, marginTop: 8, marginBottom: 8 },
  rowBetween: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  patientName: { fontFamily: paFonts.bold, fontSize: 14, color: pa.ink },
  meta: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted, marginTop: 4 },
  card: { marginBottom: 10 },
});
