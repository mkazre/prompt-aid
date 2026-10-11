import React, { useCallback, useRef, useState } from 'react';
import { Pressable, StyleSheet, Text, View, ViewStyle, StyleProp } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { useFocusEffect, useNavigation } from '@react-navigation/native';
import { fetchUnreadCounts, UnreadCounts } from '../api/comms';
import { pa, paFonts } from '../theme';

/**
 * Unread notification + chat counts, refreshed whenever the host screen
 * gains focus and every 30s while it stays focused (no websockets on the
 * shared host — polling only). Stops on blur.
 */
export function useUnreadCounts(intervalMs = 30000) {
  const [counts, setCounts] = useState<UnreadCounts>({ notifications: 0, messages: 0 });
  const alive = useRef(true);

  const load = useCallback(async () => {
    try {
      const c = await fetchUnreadCounts();
      if (alive.current) setCounts(c);
    } catch {
      // keep the last known counts on a transient network error
    }
  }, []);

  useFocusEffect(
    useCallback(() => {
      alive.current = true;
      load();
      const t = setInterval(load, intervalMs);
      return () => {
        alive.current = false;
        clearInterval(t);
      };
    }, [load, intervalMs]),
  );

  return { counts, reload: load };
}

function Badge({ count }: { count: number }) {
  if (count <= 0) return null;
  return (
    <View style={styles.badge}>
      <Text style={styles.badgeText}>{count > 99 ? '99+' : count}</Text>
    </View>
  );
}

export function HeaderIconButton({
  icon,
  count = 0,
  onPress,
  label,
  dark,
}: {
  icon: React.ComponentProps<typeof Ionicons>['name'];
  count?: number;
  onPress: () => void;
  label: string;
  dark?: boolean;
}) {
  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="button"
      accessibilityLabel={count > 0 ? `${label}, ${count} unread` : label}
      hitSlop={6}
      style={({ pressed }) => [styles.btn, dark && styles.btnDark, { opacity: pressed ? 0.7 : 1 }]}
    >
      <Ionicons name={icon} size={21} color={dark ? '#fff' : '#1C1C1E'} />
      <Badge count={count} />
    </Pressable>
  );
}

/**
 * Bell (→ Notifications) and, for patients/doctors, a chat bubble
 * (→ Messages). Drop into any role's home header.
 */
export default function CommsHeaderIcons({ showMessages = false, dark, style }: { showMessages?: boolean; dark?: boolean; style?: StyleProp<ViewStyle> }) {
  const navigation = useNavigation<any>();
  const { counts } = useUnreadCounts();

  return (
    <View style={[styles.row, style]}>
      {showMessages ? (
        <HeaderIconButton icon="chatbubble-ellipses-outline" label="Messages" count={counts.messages} dark={dark} onPress={() => navigation.navigate('ChatList')} />
      ) : null}
      <HeaderIconButton icon="notifications-outline" label="Notifications" count={counts.notifications} dark={dark} onPress={() => navigation.navigate('Notifications')} />
    </View>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  btn: {
    width: 42,
    height: 42,
    borderRadius: 21,
    backgroundColor: '#fff',
    borderWidth: StyleSheet.hairlineWidth,
    borderColor: '#E6E6E9',
    alignItems: 'center',
    justifyContent: 'center',
  },
  btnDark: { backgroundColor: 'rgba(255,255,255,0.08)', borderColor: 'rgba(255,255,255,0.18)' },
  badge: {
    position: 'absolute',
    top: -3,
    right: -3,
    minWidth: 18,
    height: 18,
    paddingHorizontal: 4,
    borderRadius: 9,
    backgroundColor: pa.signal,
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 2,
    borderColor: '#fff',
  },
  badgeText: { color: '#fff', fontFamily: paFonts.black, fontSize: 9 },
});
