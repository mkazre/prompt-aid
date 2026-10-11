import React, { useCallback, useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { useFocusEffect, useNavigation } from '@react-navigation/native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { apiErrorMessage } from '../../api/client';
import { Conversation, fetchConversations, timeAgo } from '../../api/comms';
import { initials } from '../../components/pa';
import { useAuth } from '../../context/AuthContext';
import { pa, paFonts } from '../../theme';

/** Messages inbox for patients and doctors. Refreshes on focus + every 10s while open. */
export default function ChatListScreen() {
  const insets = useSafeAreaInsets();
  const navigation = useNavigation<any>();
  const { user } = useAuth();
  const [items, setItems] = useState<Conversation[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const res = await fetchConversations();
      setItems(res.data);
      setError(null);
    } catch (e) {
      setError(apiErrorMessage(e, 'Could not load your messages.'));
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, []);

  useFocusEffect(
    useCallback(() => {
      load();
      const t = setInterval(load, 10000);
      return () => clearInterval(t);
    }, [load]),
  );

  const isDoctor = user?.role === 'doctor';

  return (
    <View style={[styles.container, { paddingTop: insets.top }]}>
      <View style={styles.header}>
        <Pressable onPress={() => navigation.goBack()} hitSlop={10} style={styles.back} accessibilityLabel="Back">
          <Ionicons name="chevron-back" size={24} color="#1C1C1E" />
        </Pressable>
        <Text style={styles.title}>Messages</Text>
        <View style={{ width: 40 }} />
      </View>

      {loading ? (
        <ActivityIndicator style={{ marginTop: 40 }} color={pa.ink} />
      ) : (
        <FlatList
          data={items}
          keyExtractor={(c) => String(c.id)}
          contentContainerStyle={{ padding: 16, paddingBottom: insets.bottom + 24, flexGrow: 1 }}
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => { setRefreshing(true); load(); }} />}
          ItemSeparatorComponent={() => <View style={{ height: 10 }} />}
          ListHeaderComponent={error ? <Text style={styles.error}>{error}</Text> : null}
          ListEmptyComponent={
            <View style={styles.empty}>
              <View style={styles.emptyIcon}>
                <Ionicons name="chatbubbles-outline" size={30} color="#1C1C1E" />
              </View>
              <Text style={styles.emptyTitle}>No conversations yet</Text>
              <Text style={styles.emptyText}>
                {isDoctor
                  ? 'Open an appointment from your day or calendar and tap “Message” to chat with that patient.'
                  : 'Open one of your appointments and tap “Message doctor” to start a conversation.'}
              </Text>
            </View>
          }
          renderItem={({ item }) => (
            <Pressable
              onPress={() => navigation.navigate('Chat', { conversationId: item.id, title: item.counterpart.name })}
              style={({ pressed }) => [styles.row, { opacity: pressed ? 0.8 : 1 }]}
            >
              <View style={styles.avatar}>
                <Text style={styles.avatarText}>{initials(item.counterpart.name.replace(/^Dr\.\s*/, ''))}</Text>
              </View>
              <View style={{ flex: 1, minWidth: 0 }}>
                <View style={styles.rowTop}>
                  <Text style={styles.name} numberOfLines={1}>
                    {item.counterpart.name}
                  </Text>
                  <Text style={styles.time}>{timeAgo(item.last_message?.created_at ?? item.last_message_at)}</Text>
                </View>
                <View style={styles.rowTop}>
                  <Text style={[styles.preview, item.unread_count > 0 && styles.previewUnread]} numberOfLines={1}>
                    {item.last_message ? `${item.last_message.is_mine ? 'You: ' : ''}${item.last_message.body}` : item.counterpart.subtitle}
                  </Text>
                  {item.unread_count > 0 ? (
                    <View style={styles.unread}>
                      <Text style={styles.unreadText}>{item.unread_count}</Text>
                    </View>
                  ) : null}
                </View>
              </View>
            </Pressable>
          )}
        />
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#F6F6F8' },
  header: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingHorizontal: 12, paddingVertical: 10 },
  back: { width: 40, height: 40, borderRadius: 20, alignItems: 'center', justifyContent: 'center', backgroundColor: '#fff' },
  title: { fontFamily: paFonts.black, fontSize: 18, color: '#1C1C1E' },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
    backgroundColor: '#fff',
    borderRadius: 18,
    padding: 14,
    shadowColor: '#000',
    shadowOpacity: 0.05,
    shadowRadius: 10,
    shadowOffset: { width: 0, height: 4 },
    elevation: 2,
  },
  avatar: { width: 48, height: 48, borderRadius: 24, backgroundColor: pa.ink, alignItems: 'center', justifyContent: 'center' },
  avatarText: { color: '#fff', fontFamily: paFonts.black, fontSize: 16 },
  rowTop: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 8 },
  name: { flex: 1, fontFamily: paFonts.bold, fontSize: 15, color: '#1C1C1E' },
  time: { fontFamily: paFonts.regular, fontSize: 11, color: '#8A8A8E' },
  preview: { flex: 1, fontFamily: paFonts.regular, fontSize: 13, color: '#6B6B70', marginTop: 3 },
  previewUnread: { color: '#1C1C1E', fontFamily: paFonts.bold },
  unread: { minWidth: 20, height: 20, borderRadius: 10, paddingHorizontal: 6, backgroundColor: pa.signal, alignItems: 'center', justifyContent: 'center' },
  unreadText: { color: '#fff', fontFamily: paFonts.black, fontSize: 11 },
  error: { fontFamily: paFonts.regular, color: pa.signalInk, marginBottom: 12 },
  empty: { flex: 1, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 30, paddingTop: 60 },
  emptyIcon: { width: 72, height: 72, borderRadius: 36, backgroundColor: '#fff', alignItems: 'center', justifyContent: 'center', marginBottom: 16 },
  emptyTitle: { fontFamily: paFonts.black, fontSize: 17, color: '#1C1C1E', marginBottom: 6 },
  emptyText: { fontFamily: paFonts.regular, fontSize: 14, lineHeight: 20, color: '#6B6B70', textAlign: 'center' },
});
