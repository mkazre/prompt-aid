import React, { useCallback, useState } from 'react';
import { FlatList, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native';
import { Feather } from '@expo/vector-icons';
import { useFocusEffect, useNavigation } from '@react-navigation/native';
import { apiErrorMessage } from '../../api/client';
import { fetchSupportMeta, fetchTickets, SupportMeta, SupportTicket } from '../../api/support';
import { PaEmptyState } from '../../components/pa';
import { pa, paFonts } from '../../theme';

export const TICKET_STATUS: Record<string, { fg: string; bg: string }> = {
  open: { fg: '#7A5C00', bg: pa.beaconWash },
  awaiting_reply: { fg: pa.signalInk, bg: pa.signalWash },
  resolved: { fg: pa.go, bg: pa.goWash },
  closed: { fg: pa.muted, bg: pa.lineSoft },
};

/**
 * "Help & support" — reachable from every role's profile/settings screen.
 * Links to the Help centre (FAQs), lists the user's tickets and opens new ones.
 */
export default function SupportScreen() {
  const navigation = useNavigation<any>();
  const [tickets, setTickets] = useState<SupportTicket[]>([]);
  const [meta, setMeta] = useState<SupportMeta | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const [list, m] = await Promise.all([fetchTickets(), fetchSupportMeta()]);
      setTickets(list);
      setMeta(m);
      setError(null);
    } catch (e) {
      setError(apiErrorMessage(e, 'Could not load your tickets.'));
    } finally {
      setLoading(false);
    }
  }, []);

  useFocusEffect(useCallback(() => { load(); }, [load]));

  return (
    <FlatList
      style={styles.container}
      data={tickets}
      keyExtractor={(t) => String(t.id)}
      contentContainerStyle={{ padding: 16, paddingBottom: 48 }}
      refreshControl={<RefreshControl refreshing={loading} onRefresh={load} />}
      ListHeaderComponent={
        <View>
          <Pressable style={styles.helpCard} onPress={() => navigation.navigate('HelpCentre')}>
            <Feather name="book-open" size={22} color={pa.info} />
            <View style={{ flex: 1 }}>
              <Text style={styles.helpTitle}>Help centre</Text>
              <Text style={styles.helpSub}>Answers to common questions about bookings, the shuttle, payments and more.</Text>
            </View>
            <Feather name="chevron-right" size={20} color={pa.muted} />
          </Pressable>

          {meta?.intro ? <Text style={styles.intro}>{meta.intro}</Text> : null}

          {meta?.enabled !== false ? (
            <Pressable style={styles.newBtn} onPress={() => navigation.navigate('SupportNew')}>
              <Feather name="plus" size={18} color="#fff" />
              <Text style={styles.newBtnText}>New support ticket</Text>
            </Pressable>
          ) : null}

          {error ? <Text style={styles.error}>{error}</Text> : null}
          <Text style={styles.section}>My tickets</Text>
        </View>
      }
      ListEmptyComponent={!loading && !error ? <PaEmptyState message="No support tickets yet." /> : null}
      ListFooterComponent={meta?.response_note ? <Text style={styles.note}>{meta.response_note}</Text> : null}
      renderItem={({ item: t }) => {
        const st = TICKET_STATUS[t.status] ?? TICKET_STATUS.open;
        return (
          <Pressable style={styles.row} onPress={() => navigation.navigate('SupportThread', { ticketId: t.id })}>
            <View style={{ flex: 1 }}>
              <Text style={styles.subject} numberOfLines={1}>{t.subject}</Text>
              <Text style={styles.meta} numberOfLines={1}>
                {t.reference} · {t.category_label} · {formatWhen(t.last_reply_at ?? t.created_at)}
              </Text>
            </View>
            <View style={[styles.pill, { backgroundColor: st.bg }]}>
              <Text style={[styles.pillText, { color: st.fg }]}>{t.status_label}</Text>
            </View>
          </Pressable>
        );
      }}
    />
  );
}

export function formatWhen(iso?: string | null) {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  return d.toLocaleDateString('en-ZA', { day: 'numeric', month: 'short' }) + ' ' + d.toLocaleTimeString('en-ZA', { hour: '2-digit', minute: '2-digit' });
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper },
  helpCard: { flexDirection: 'row', alignItems: 'center', gap: 12, backgroundColor: pa.surface, borderWidth: 1, borderColor: pa.line, borderRadius: 12, padding: 14, marginBottom: 14 },
  helpTitle: { fontFamily: paFonts.black, fontSize: 15, color: pa.ink },
  helpSub: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted, marginTop: 2 },
  intro: { fontFamily: paFonts.regular, fontSize: 13, color: pa.inkSoft, marginBottom: 12, lineHeight: 19 },
  newBtn: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8, backgroundColor: pa.signal, borderRadius: 10, paddingVertical: 13, marginBottom: 18 },
  newBtnText: { fontFamily: paFonts.black, fontSize: 14, color: '#fff' },
  section: { fontFamily: paFonts.black, fontSize: 11, letterSpacing: 1.4, textTransform: 'uppercase', color: pa.muted, marginBottom: 8 },
  error: { fontFamily: paFonts.bold, fontSize: 13, color: pa.signal, marginBottom: 10 },
  row: { flexDirection: 'row', alignItems: 'center', gap: 10, backgroundColor: pa.surface, borderWidth: 1, borderColor: pa.line, borderRadius: 12, padding: 14, marginBottom: 8 },
  subject: { fontFamily: paFonts.bold, fontSize: 15, color: pa.ink },
  meta: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted, marginTop: 3 },
  pill: { borderRadius: 999, paddingHorizontal: 10, paddingVertical: 4 },
  pillText: { fontFamily: paFonts.black, fontSize: 11 },
  note: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted, marginTop: 14 },
});
