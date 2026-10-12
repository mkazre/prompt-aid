import React, { useCallback, useState } from 'react';
import { ActivityIndicator, Alert, FlatList, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { useFocusEffect, useNavigation } from '@react-navigation/native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { apiErrorMessage } from '../../api/client';
import {
  AppNotification,
  clearNotifications,
  deleteNotification,
  fetchNotifications,
  markAllNotificationsRead,
  markNotificationRead,
  timeAgo,
} from '../../api/comms';
import { useAuth } from '../../context/AuthContext';
import { Role } from '../../api/types';
import { pa, paFonts } from '../../theme';

const TYPE_ICON: Record<string, React.ComponentProps<typeof Ionicons>['name']> = {
  appointment: 'calendar-outline',
  chat_message: 'chatbubble-ellipses-outline',
  video: 'videocam-outline',
  ride: 'bus-outline',
  order: 'bag-handle-outline',
  prescription: 'document-text-outline',
  lab: 'flask-outline',
  triage: 'pulse-outline',
  account: 'person-circle-outline',
  broadcast: 'megaphone-outline',
};

/**
 * Where a notification's `data.route` takes each role. Root-level screens
 * (Chat) are addressed directly; everything else goes through the role's
 * tab navigator, mounted at root as "Main".
 */
function resolveTarget(role: Role | undefined, n: AppNotification): [string, any] | null {
  const route = n.data?.route;
  const p = n.data?.params ?? {};
  if (!route) return null;

  if (route === 'chat' && (p.conversation_id || n.data.conversation_id)) {
    return ['Chat', { conversationId: p.conversation_id ?? n.data.conversation_id }];
  }

  // Help & support thread and the "How was your visit?" survey are root screens (every role).
  if (route === 'support_ticket' && p.ticket_id) return ['SupportThread', { ticketId: p.ticket_id }];
  if (route === 'feedback' && p.token) return ['Feedback', { token: p.token }];

  const tab = (screen: string, params?: any) => ['Main', { screen, params }] as [string, any];

  switch (role) {
    case 'patient':
      if (route === 'appointment') return tab('Profile', { screen: 'Appointments' });
      if (route === 'orders') return tab('Profile', { screen: 'Orders' });
      if (route === 'lab_results') return tab('Profile', { screen: 'LabResults' });
      if (route === 'prescriptions') return tab('Pharmacy');
      if (route === 'ride' && p.ride_id) return tab('Rides', { screen: 'RideTrack', params: { rideId: p.ride_id } });
      return null;
    case 'doctor':
      if (route === 'doctor_appointment') return tab('DoctorCalendar');
      return tab('DoctorDashboard');
    case 'driver':
      return tab('Trips');
    case 'pharmacy_admin':
      return tab('Orders');
    case 'third_party':
      return tab('Requests');
    default:
      return null;
  }
}

export default function NotificationsScreen() {
  const insets = useSafeAreaInsets();
  const navigation = useNavigation<any>();
  const { user } = useAuth();
  const [items, setItems] = useState<AppNotification[]>([]);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [loadingMore, setLoadingMore] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const res = await fetchNotifications(1);
      setItems(res.data);
      setPage(1);
      setLastPage(res.meta.last_page);
      setError(null);
    } catch (e) {
      setError(apiErrorMessage(e, 'Could not load notifications.'));
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, []);

  useFocusEffect(
    useCallback(() => {
      load();
    }, [load]),
  );

  async function loadMore() {
    if (loadingMore || page >= lastPage) return;
    setLoadingMore(true);
    try {
      const res = await fetchNotifications(page + 1);
      setItems((prev) => [...prev, ...res.data.filter((n) => !prev.some((x) => x.id === n.id))]);
      setPage(res.meta.current_page);
      setLastPage(res.meta.last_page);
    } catch {
      // ignore; user can pull to refresh
    } finally {
      setLoadingMore(false);
    }
  }

  async function open(n: AppNotification) {
    if (!n.read_at) {
      setItems((prev) => prev.map((x) => (x.id === n.id ? { ...x, read_at: new Date().toISOString() } : x)));
      markNotificationRead(n.id).catch(() => undefined);
    }
    const target = resolveTarget(user?.role, n);
    if (target) navigation.navigate(target[0], target[1]);
  }

  async function remove(n: AppNotification) {
    const before = items;
    setItems((prev) => prev.filter((x) => x.id !== n.id));
    try {
      await deleteNotification(n.id);
    } catch (e) {
      setItems(before);
      Alert.alert('Could not delete', apiErrorMessage(e));
    }
  }

  function clearAll() {
    if (items.length === 0) return;
    Alert.alert('Clear all notifications?', 'This removes every notification from your list.', [
      { text: 'Cancel', style: 'cancel' },
      {
        text: 'Clear all',
        style: 'destructive',
        onPress: async () => {
          const before = items;
          setItems([]);
          try {
            await clearNotifications();
            setLastPage(1);
          } catch (e) {
            setItems(before);
            Alert.alert('Could not clear', apiErrorMessage(e));
          }
        },
      },
    ]);
  }

  async function readAll() {
    try {
      await markAllNotificationsRead();
      const now = new Date().toISOString();
      setItems((prev) => prev.map((x) => (x.read_at ? x : { ...x, read_at: now })));
    } catch (e) {
      Alert.alert('Could not update', apiErrorMessage(e));
    }
  }

  const unread = items.filter((n) => !n.read_at).length;

  return (
    <View style={[styles.container, { paddingTop: insets.top }]}>
      <View style={styles.header}>
        <Pressable onPress={() => navigation.goBack()} hitSlop={10} style={styles.back} accessibilityLabel="Back">
          <Ionicons name="chevron-back" size={24} color="#1C1C1E" />
        </Pressable>
        <Text style={styles.title}>Notifications</Text>
        <Pressable onPress={clearAll} hitSlop={10} disabled={items.length === 0}>
          <Text style={[styles.clear, items.length === 0 && { opacity: 0.4 }]}>Clear All</Text>
        </Pressable>
      </View>

      {loading ? (
        <ActivityIndicator style={{ marginTop: 40 }} color={pa.ink} />
      ) : (
        <FlatList
          data={items}
          keyExtractor={(n) => String(n.id)}
          contentContainerStyle={{ padding: 16, paddingBottom: insets.bottom + 24, flexGrow: 1 }}
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => { setRefreshing(true); load(); }} />}
          onEndReached={loadMore}
          onEndReachedThreshold={0.4}
          ItemSeparatorComponent={() => <View style={{ height: 12 }} />}
          ListHeaderComponent={
            <>
              {error ? <Text style={styles.error}>{error}</Text> : null}
              {unread > 0 ? (
                <Pressable onPress={readAll} style={styles.readAll}>
                  <Ionicons name="checkmark-done-outline" size={16} color="#1C1C1E" />
                  <Text style={styles.readAllText}>Mark all as read ({unread})</Text>
                </Pressable>
              ) : null}
            </>
          }
          ListFooterComponent={loadingMore ? <ActivityIndicator style={{ marginTop: 12 }} color={pa.ink} /> : null}
          ListEmptyComponent={
            <View style={styles.empty}>
              <View style={styles.emptyIcon}>
                <Ionicons name="notifications-off-outline" size={30} color="#1C1C1E" />
              </View>
              <Text style={styles.emptyTitle}>You're all caught up</Text>
              <Text style={styles.emptyText}>Updates about appointments, messages, orders and rides will show up here.</Text>
            </View>
          }
          renderItem={({ item }) => (
            <Pressable onPress={() => open(item)} style={({ pressed }) => [styles.card, !item.read_at && styles.cardUnread, { opacity: pressed ? 0.85 : 1 }]}>
              <View style={[styles.iconWrap, !item.read_at && styles.iconWrapUnread]}>
                <Ionicons name={TYPE_ICON[item.type] ?? 'notifications-outline'} size={20} color="#1C1C1E" />
              </View>
              <View style={{ flex: 1, minWidth: 0 }}>
                <Text style={styles.cardTitle} numberOfLines={2}>
                  {item.title}
                </Text>
                {item.body ? (
                  <Text style={styles.cardBody} numberOfLines={3}>
                    {item.body}
                  </Text>
                ) : null}
                <Text style={styles.cardTime}>{timeAgo(item.created_at)}</Text>
              </View>
              <Pressable onPress={() => remove(item)} hitSlop={10} style={styles.trash} accessibilityLabel="Delete notification">
                <Ionicons name="trash-outline" size={19} color="#8A8A8E" />
              </Pressable>
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
  clear: { fontFamily: paFonts.bold, fontSize: 14, color: pa.signal, paddingHorizontal: 6 },
  readAll: { flexDirection: 'row', alignItems: 'center', gap: 6, alignSelf: 'flex-end', marginBottom: 12, paddingVertical: 4 },
  readAllText: { fontFamily: paFonts.bold, fontSize: 13, color: '#1C1C1E' },
  card: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: 12,
    backgroundColor: '#fff',
    borderRadius: 18,
    padding: 14,
    borderWidth: 1.5,
    borderColor: 'transparent',
    shadowColor: '#000',
    shadowOpacity: 0.05,
    shadowRadius: 10,
    shadowOffset: { width: 0, height: 4 },
    elevation: 2,
  },
  cardUnread: { borderColor: pa.info },
  iconWrap: { width: 42, height: 42, borderRadius: 21, backgroundColor: '#F2F2F5', alignItems: 'center', justifyContent: 'center' },
  iconWrapUnread: { backgroundColor: '#E8EEF4' },
  cardTitle: { fontFamily: paFonts.bold, fontSize: 15, color: '#1C1C1E' },
  cardBody: { fontFamily: paFonts.regular, fontSize: 13, lineHeight: 19, color: '#6B6B70', marginTop: 3 },
  cardTime: { fontFamily: paFonts.regular, fontSize: 11, color: '#9A9AA0', marginTop: 8 },
  trash: { padding: 4 },
  error: { fontFamily: paFonts.regular, color: pa.signalInk, marginBottom: 12 },
  empty: { flex: 1, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 30, paddingTop: 60 },
  emptyIcon: { width: 72, height: 72, borderRadius: 36, backgroundColor: '#fff', alignItems: 'center', justifyContent: 'center', marginBottom: 16 },
  emptyTitle: { fontFamily: paFonts.black, fontSize: 17, color: '#1C1C1E', marginBottom: 6 },
  emptyText: { fontFamily: paFonts.regular, fontSize: 14, lineHeight: 20, color: '#6B6B70', textAlign: 'center' },
});
