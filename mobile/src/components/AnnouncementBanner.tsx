import React, { useEffect, useState } from 'react';
import { Linking, Pressable, StyleProp, StyleSheet, Text, View, ViewStyle } from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { Feather } from '@expo/vector-icons';
import { Announcement, fetchAnnouncements } from '../api/support';
import { paFonts } from '../theme';

const KEY = (a: Announcement) => `pa_annc_dismissed_${a.id}-${a.version ?? 0}`;

/**
 * The current admin announcement (/staff → Support → Announcements) as a
 * coloured, dismissible banner. GET /api/announcements is filtered by the
 * signed-in user's role; dismissal is remembered per announcement + edit.
 * Renders nothing when there is no live announcement.
 */
export default function AnnouncementBanner({ refreshKey, style }: { refreshKey?: number; style?: StyleProp<ViewStyle> }) {
  const [item, setItem] = useState<Announcement | null>(null);

  useEffect(() => {
    let alive = true;
    (async () => {
      try {
        const list = await fetchAnnouncements();
        for (const a of list) {
          const dismissed = a.is_dismissible ? await AsyncStorage.getItem(KEY(a)).catch(() => null) : null;
          if (!dismissed) {
            if (alive) setItem(a);
            return;
          }
        }
        if (alive) setItem(null);
      } catch {
        // Banner is optional — never block the home screen.
      }
    })();
    return () => {
      alive = false;
    };
  }, [refreshKey]);

  if (!item) return null;

  const fg = item.text_color || '#fff';

  function dismiss() {
    if (!item) return;
    AsyncStorage.setItem(KEY(item), '1').catch(() => undefined);
    setItem(null);
  }

  return (
    <View style={[styles.wrap, { backgroundColor: item.color }, style]} accessibilityRole="summary">
      <Feather name="volume-2" size={18} color={fg} style={{ marginTop: 1 }} />
      <View style={{ flex: 1 }}>
        {item.title ? <Text style={[styles.title, { color: fg }]}>{item.title}</Text> : null}
        <Text style={[styles.msg, { color: fg }]}>{item.message}</Text>
        {item.link_url ? (
          <Pressable onPress={() => Linking.openURL(item.link_url!)} hitSlop={8}>
            <Text style={[styles.link, { color: fg }]}>{item.link_label ?? 'Learn more'} →</Text>
          </Pressable>
        ) : null}
      </View>
      {item.is_dismissible ? (
        <Pressable onPress={dismiss} hitSlop={12} accessibilityLabel="Dismiss announcement">
          <Feather name="x" size={18} color={fg} />
        </Pressable>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: { flexDirection: 'row', gap: 10, alignItems: 'flex-start', borderRadius: 12, padding: 14, marginBottom: 14 },
  title: { fontFamily: paFonts.black, fontSize: 14, marginBottom: 2 },
  msg: { fontFamily: paFonts.regular, fontSize: 13, lineHeight: 18 },
  link: { fontFamily: paFonts.bold, fontSize: 13, marginTop: 6, textDecorationLine: 'underline' },
});
