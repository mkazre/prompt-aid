import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, StyleProp, View, ViewStyle } from 'react-native';
import { useFocusEffect, useNavigation } from '@react-navigation/native';
import * as Location from 'expo-location';
import { api } from '../api/client';
import { pa } from '../theme';
import { WidgetConfig, WidgetContext } from './types';
import { resolveWidget } from './registry';

type Screen = 'home' | 'find_care' | 'shop' | 'record' | 'calendar' | 'profile' | 'shuttle' | 'appointments';

/** Screens whose widgets use the viewer's position (distance to doctors/clinics). */
const LOCATION_SCREENS: Screen[] = ['home', 'find_care'];

class WidgetBoundary extends React.Component<{ widgetKey: string; children: React.ReactNode }, { failed: boolean }> {
  state = { failed: false };

  static getDerivedStateFromError() {
    return { failed: true };
  }

  componentDidCatch(error: unknown) {
    if (__DEV__) console.warn(`[widget ${this.props.widgetKey}] render failed`, error);
  }

  render() {
    // One broken widget quietly disappears; the rest of the screen keeps working.
    return this.state.failed ? null : this.props.children;
  }
}

/**
 * Renders the admin-configured widgets (Site → App Widgets) for the signed-
 * in user's role on one screen, in order, from a single
 * `GET /app-widgets?screen=…&include=data` round trip.
 *
 * - Re-fetches on focus and whenever `refreshKey` changes (bump it from the
 *   screen's pull-to-refresh).
 * - Consecutive widgets with `settings.size === 'half'` sit side by side.
 * - Unknown keys are skipped; each widget has its own error boundary.
 * - `context` passes screen hooks (e.g. calendar date, shop category) down.
 * - `onLoaded` reports which keys rendered, so a screen can hide the
 *   hardcoded block a widget replaces.
 */
export default function WidgetHost({
  screen,
  refreshKey,
  context,
  onLoaded,
  style,
  gap = 14,
}: {
  screen: Screen;
  refreshKey?: number;
  context?: Omit<WidgetContext, 'refresh'>;
  onLoaded?: (keys: string[]) => void;
  style?: StyleProp<ViewStyle>;
  gap?: number;
}) {
  const navigation = useNavigation<any>();
  const [widgets, setWidgets] = useState<WidgetConfig[] | null>(null);
  const [generation, setGeneration] = useState(0);
  const onLoadedRef = useRef(onLoaded);
  onLoadedRef.current = onLoaded;
  const inFlight = useRef(false);

  const load = useCallback(async () => {
    if (inFlight.current) return;
    inFlight.current = true;
    try {
      const params: Record<string, string | number> = { screen, include: 'data' };
      if (LOCATION_SCREENS.includes(screen)) {
        try {
          // Never prompts — only uses a position the user already granted.
          const perm = await Location.getForegroundPermissionsAsync();
          if (perm.granted) {
            const pos = await Location.getLastKnownPositionAsync();
            if (pos) {
              params.lat = pos.coords.latitude;
              params.lng = pos.coords.longitude;
            }
          }
        } catch {
          // location is optional
        }
      }
      const { data } = await api.get('/app-widgets', { params });
      const list = (data?.data ?? []) as WidgetConfig[];
      setWidgets(list);
      setGeneration((g) => g + 1);
      onLoadedRef.current?.(list.filter((w) => resolveWidget(w.key)).map((w) => w.key));
    } catch {
      // Keep whatever we showed last; an offline blip shouldn't blank the screen.
      setWidgets((prev) => prev ?? []);
      onLoadedRef.current?.([]);
    } finally {
      inFlight.current = false;
    }
  }, [screen]);

  useFocusEffect(useCallback(() => { load(); }, [load]));

  const firstRefresh = useRef(true);
  useEffect(() => {
    if (firstRefresh.current) {
      firstRefresh.current = false;
      return;
    }
    load();
  }, [refreshKey, load]);

  const ctx: WidgetContext = useMemo(() => ({ ...(context ?? {}), refresh: load }), [context, load]);

  // Group consecutive half-width widgets into pairs.
  const rows = useMemo(() => {
    const out: WidgetConfig[][] = [];
    for (const w of widgets ?? []) {
      if (!resolveWidget(w.key)) continue;
      const last = out[out.length - 1];
      const half = w.settings?.size === 'half';
      if (half && last && last.length === 1 && last[0].settings?.size === 'half') last.push(w);
      else out.push([w]);
    }
    return out;
  }, [widgets]);

  if (widgets === null) {
    return <ActivityIndicator color={pa.signal} style={{ marginVertical: 24 }} />;
  }
  if (!rows.length) return null;

  return (
    <View style={[{ gap }, style]}>
      {rows.map((row) => (
        <View key={row.map((w) => w.key).join('+')} style={row.length > 1 ? { flexDirection: 'row', gap: 12 } : undefined}>
          {row.map((w) => {
            const Component = resolveWidget(w.key)!;
            return (
              <View key={`${w.key}:${generation}`} style={row.length > 1 ? { flex: 1 } : undefined}>
                <WidgetBoundary widgetKey={w.key}>
                  <Component config={w} data={w.data} ctx={ctx} navigation={navigation} />
                </WidgetBoundary>
              </View>
            );
          })}
        </View>
      ))}
    </View>
  );
}
