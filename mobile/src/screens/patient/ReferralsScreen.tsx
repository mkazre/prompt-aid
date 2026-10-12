import React, { useCallback, useState } from 'react';
import { Alert, RefreshControl, ScrollView, Share, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { Feather } from '@expo/vector-icons';
import { api, apiErrorMessage } from '../../api/client';
import { PaButton, PaCard, PaEmptyState, PaRow, PaSectionLabel } from '../../components/pa';
import { pa, paFonts, paRadius, type } from '../../theme';

interface ReferralSummary {
  enabled: boolean;
  code: string;
  link: string;
  share_message: string;
  reward_text: string;
  joined_count: number;
  recent: { name: string; joined_at: string | null }[];
}

/**
 * "Invite friends" — the patient's personal referral code, a native share
 * sheet (RN Share) and how many friends joined. Backed by GET /referrals;
 * the programme text is admin-editable (Staff → System → Commerce & alerts).
 */
export default function ReferralsScreen() {
  const [summary, setSummary] = useState<ReferralSummary | null>(null);
  const [loading, setLoading] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const { data } = await api.get('/referrals');
      setSummary(data.data);
    } catch (e) {
      Alert.alert('Could not load your invite code', apiErrorMessage(e));
    } finally {
      setLoading(false);
    }
  }, []);

  useFocusEffect(useCallback(() => { load(); }, [load]));

  async function share() {
    if (!summary) return;
    try {
      await Share.share({ message: summary.share_message, url: summary.link, title: 'Join me on PromptAid' });
    } catch (e) {
      Alert.alert('Could not share', apiErrorMessage(e));
    }
  }

  return (
    <ScrollView
      style={styles.container}
      contentContainerStyle={{ padding: 16, paddingBottom: 40 }}
      refreshControl={<RefreshControl refreshing={loading} onRefresh={load} tintColor={pa.signal} />}
    >
      <Text style={[type.h2, { marginBottom: 6 }]}>Invite friends</Text>
      {summary?.reward_text ? <Text style={styles.lead}>{summary.reward_text}</Text> : null}

      {summary && !summary.enabled ? (
        <View style={styles.note}>
          <Text style={styles.noteText}>The referral programme is paused at the moment. Your code still works when it reopens.</Text>
        </View>
      ) : null}

      <PaCard style={styles.codeCard}>
        <Text style={styles.codeLabel}>Your code</Text>
        <Text style={styles.code} selectable>{summary?.code ?? '—'}</Text>
        <Text style={styles.link} numberOfLines={1} selectable>{summary?.link ?? ''}</Text>
        <PaButton title="Share invite" onPress={share} disabled={!summary} style={{ marginTop: 14 }} />
      </PaCard>

      <View style={styles.statRow}>
        <Feather name="users" size={18} color={pa.go} />
        <Text style={styles.statText}>
          <Text style={styles.statNum}>{summary?.joined_count ?? 0}</Text>
          {summary?.joined_count === 1 ? ' friend has joined' : ' friends have joined'}
        </Text>
      </View>

      <PaSectionLabel style={{ marginTop: 18, marginBottom: 8 }}>Recently joined</PaSectionLabel>
      {summary && summary.recent.length === 0 ? (
        <PaEmptyState message="No one has joined with your code yet." />
      ) : (
        summary?.recent.map((f, i) => (
          <PaRow key={`${f.name}-${i}`} title={f.name} subtitle={f.joined_at ? new Date(f.joined_at).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' }) : undefined} />
        ))
      )}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper },
  lead: { fontFamily: paFonts.regular, fontSize: 14, lineHeight: 20, color: pa.inkSoft, marginBottom: 14 },
  note: { backgroundColor: pa.beaconWash, borderWidth: 1, borderColor: pa.beaconLine, borderRadius: paRadius.sm, padding: 12, marginBottom: 12 },
  noteText: { fontFamily: paFonts.regular, fontSize: 13, color: pa.ink },
  codeCard: { alignItems: 'stretch' },
  codeLabel: { ...type.label, marginBottom: 4 },
  code: { fontFamily: paFonts.black, fontSize: 32, letterSpacing: 2, color: pa.ink },
  link: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted, marginTop: 4 },
  statRow: { flexDirection: 'row', alignItems: 'center', gap: 8, marginTop: 16 },
  statText: { fontFamily: paFonts.regular, fontSize: 14, color: pa.ink },
  statNum: { fontFamily: paFonts.black, fontSize: 18 },
});
