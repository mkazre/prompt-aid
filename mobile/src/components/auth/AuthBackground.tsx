import React, { useEffect, useState } from 'react';
import { Image, StyleSheet, View } from 'react-native';
import { LinearGradient } from 'expo-linear-gradient';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { LoginLook, useSiteContent } from '../../services/siteContent';
import { colors } from '../../theme';

/**
 * Sign-in / sign-up background set by the admin (Staff → Site content →
 * Sign up & sign in): photo with opacity + blur, then a solid or gradient
 * overlay. The last look is kept on the phone so the screen opens with it
 * straight away; the fresh one from the server replaces it a moment later.
 */
const STORE_KEY = 'pa.loginLook.v1';
let memo: LoginLook | null = null;

function pct(v: unknown, fallback: number) {
  const n = Number(v);
  return Number.isFinite(n) ? Math.min(100, Math.max(0, n)) / 100 : fallback / 100;
}

/** '#RRGGBB' (or '#RGB') + opacity → rgba(). Anything unparseable → black. */
export function rgba(hex: string | undefined, alpha: number) {
  let h = String(hex ?? '#000000').trim().replace('#', '');
  if (h.length === 3) h = h.split('').map((c) => c + c).join('');
  const n = parseInt(h.slice(0, 6), 16);
  if (!Number.isFinite(n) || h.length < 6) return `rgba(0,0,0,${alpha})`;
  return `rgba(${(n >> 16) & 255},${(n >> 8) & 255},${n & 255},${alpha})`;
}

export function useLoginLook(): LoginLook | null {
  const content = useSiteContent();
  const [look, setLook] = useState<LoginLook | null>(memo);

  useEffect(() => {
    if (memo) return;
    AsyncStorage.getItem(STORE_KEY)
      .then((raw) => {
        if (raw && !memo) {
          memo = JSON.parse(raw);
          setLook(memo);
        }
      })
      .catch(() => undefined);
  }, []);

  useEffect(() => {
    const fresh = content.login_screen;
    if (!fresh) return;
    memo = fresh;
    setLook(fresh);
    if (fresh.image_url) Image.prefetch(fresh.image_url).catch(() => undefined);
    AsyncStorage.setItem(STORE_KEY, JSON.stringify(fresh)).catch(() => undefined);
  }, [content.login_screen]);

  return look;
}

/** Text colours + layout hints for whatever sits on the background. */
export function lookTheme(look: LoginLook | null, screen: 'login' | 'signup') {
  // Only once a photo is uploaded — until then the screens keep their usual plain look.
  const active = !!look && !!look.image_url && (screen === 'login' || look.apply_to_signup !== false);
  const light = active && look!.text_theme !== 'dark';
  return {
    active,
    card: !active || look!.form_card !== false,
    text: light ? '#FFFFFF' : colors.secondary,
    muted: light ? 'rgba(255,255,255,0.82)' : colors.gray600,
    link: light ? '#FFFFFF' : colors.primary,
  };
}

export default function AuthBackground({ screen, look, children }: { screen: 'login' | 'signup'; look: LoginLook | null; children: React.ReactNode }) {
  const t = lookTheme(look, screen);
  if (!t.active || !look) return <View style={{ flex: 1, backgroundColor: colors.gray50 }}>{children}</View>;

  const base = look.text_theme === 'dark' ? '#F7F5F0' : '#0B0B0D';
  const overlay = look.overlay ?? 'none';
  const dir = look.gradient_direction ?? 'vertical';
  const [start, end] =
    dir === 'horizontal'
      ? [{ x: 0, y: 0.5 }, { x: 1, y: 0.5 }]
      : dir === 'diagonal'
        ? [{ x: 0, y: 0 }, { x: 1, y: 1 }]
        : dir === 'vertical_reverse'
          ? [{ x: 0.5, y: 1 }, { x: 0.5, y: 0 }]
          : [{ x: 0.5, y: 0 }, { x: 0.5, y: 1 }];

  return (
    <View style={{ flex: 1, backgroundColor: base }}>
      {look.image_url ? (
        <Image
          source={{ uri: look.image_url }}
          style={[StyleSheet.absoluteFill, { opacity: pct(look.image_opacity, 100) }]}
          resizeMode="cover"
          blurRadius={Math.min(20, Math.max(0, Number(look.blur) || 0))}
        />
      ) : null}
      {overlay === 'solid' ? (
        <View pointerEvents="none" style={[StyleSheet.absoluteFill, { backgroundColor: rgba(look.overlay_color, pct(look.overlay_opacity, 45)) }]} />
      ) : null}
      {overlay === 'gradient' ? (
        <LinearGradient
          pointerEvents="none"
          style={StyleSheet.absoluteFill}
          colors={[rgba(look.gradient_from, pct(look.gradient_from_opacity, 10)), rgba(look.gradient_to, pct(look.gradient_to_opacity, 85))]}
          start={start}
          end={end}
        />
      ) : null}
      {children}
    </View>
  );
}
