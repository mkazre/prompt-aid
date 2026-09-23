import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Alert, Pressable, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import * as Location from 'expo-location';
import { api, apiErrorMessage } from '../../api/client';
import { Ride } from '../../api/types';
import { useAuth } from '../../context/AuthContext';
import { PaBadge, PaButton } from '../../components/pa';
import { formatTime } from '../../components/pa/driver-extras';
import RideMap from '../../components/RideMap';
import { pa, paFonts, paRadius, type } from '../../theme';

const ACTIVE_STATUSES = ['accepted', 'driver_enroute', 'arrived', 'in_progress'];

export default function DriverHomeScreen({ navigation }: any) {
  const { user, refreshMe } = useAuth();
  const [available, setAvailable] = useState(user?.driver_profile?.availability === 'available');
  const [toggling, setToggling] = useState(false);
  const [rides, setRides] = useState<Ride[]>([]);
  const [loading, setLoading] = useState(false);
  const [pos, setPos] = useState<{ lat: number; lng: number } | null>(null);
  const locationInterval = useRef<ReturnType<typeof setInterval> | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const { data } = await api.get('/driver/rides');
      setRides(data.data as Ride[]);
    } finally {
      setLoading(false);
    }
  }, []);

  useFocusEffect(useCallback(() => { load(); }, [load]));

  useEffect(() => {
    setAvailable(user?.driver_profile?.availability === 'available');
  }, [user?.driver_profile?.availability]);

  useEffect(() => {
    async function startTracking() {
      const perm = await Location.requestForegroundPermissionsAsync();
      if (perm.status !== 'granted') return;

      const ping = async () => {
        const p = await Location.getCurrentPositionAsync({});
        setPos({ lat: p.coords.latitude, lng: p.coords.longitude });
        api.post('/driver/location', { lat: p.coords.latitude, lng: p.coords.longitude }).catch(() => {});
      };
      ping();
      locationInterval.current = setInterval(ping, 15000);
    }

    if (available) startTracking();
    return () => {
      if (locationInterval.current) clearInterval(locationInterval.current);
    };
  }, [available]);

  async function toggleAvailability() {
    const next = !available;
    setToggling(true);
    setAvailable(next);
    try {
      await api.post('/driver/availability', { availability: next ? 'available' : 'offline' });
      await refreshMe();
    } catch (e) {
      setAvailable(!next);
      Alert.alert('Could not update status', apiErrorMessage(e));
    } finally {
      setToggling(false);
    }
  }

  const active = rides.find((r) => ACTIVE_STATUSES.includes(r.status)) ?? null;
  const upcoming = rides
    .filter((r) => r.status === 'requested' && r.scheduled_for && new Date(r.scheduled_for).getTime() > Date.now())
    .sort((a, b) => new Date(a.scheduled_for!).getTime() - new Date(b.scheduled_for!).getTime())[0] ?? null;

  const today = new Date().toDateString();
  const todaysCompleted = rides.filter((r) => r.status === 'completed' && r.completed_at && new Date(r.completed_at).toDateString() === today);
  const todaysEarnings = todaysCompleted.reduce((sum, r) => sum + (r.fare_final ?? 0), 0);

  return (
    <ScrollView
      style={styles.container}
      refreshControl={<RefreshControl refreshing={loading} onRefresh={load} />}
    >
      <View style={styles.mapWrap}>
        {active ? (
          <RideMap
            pickup={{ lat: active.pickup_lat, lng: active.pickup_lng }}
            dropoff={{ lat: active.dropoff_lat, lng: active.dropoff_lng }}
            driver={pos}
            height={280}
          />
        ) : (
          <View style={styles.mapPlaceholder} />
        )}
        <View style={styles.mapOverlay}>
          <View>
            <Text style={styles.overlayLabel}>Today</Text>
            <Text style={styles.overlayNum}>R {todaysEarnings.toFixed(0)}</Text>
          </View>
          <PaBadge tone={available ? 'go' : 'ink'} label={available ? 'Online' : 'Offline'} />
        </View>
      </View>

      <View style={{ padding: 16 }}>
        <Text style={styles.greeting}>Hi {user?.name?.split(' ')[0]}</Text>
        <Text style={styles.subtitle}>
          {available ? "You're online — visible for new trip offers" : 'Go online to start receiving trip offers'}
        </Text>

        {active && (
          <RowCard
            title={`Next · ${statusLabel(active.status)}`}
            subtitle={`${active.patient?.user?.name ?? 'Patient'} · ${active.pickup_address} → ${active.dropoff_address}`}
            badge={<PaBadge status={active.status} />}
            onPress={() => navigation.navigate('Trip')}
          />
        )}

        {upcoming && (
          <RowCard
            title={`${formatTime(upcoming.scheduled_for)} · ${upcoming.patient?.user?.name ?? 'Scheduled trip'}`}
            subtitle={`${upcoming.pickup_address} → ${upcoming.dropoff_address}`}
            badge={<PaBadge tone="wait" label="Scheduled" />}
            onPress={() => navigation.navigate('Trips', { screen: 'TripsList' })}
          />
        )}

        {!active && !upcoming && (
          <View style={styles.emptyRow}>
            <Text style={styles.emptyText}>No active or upcoming trips right now.</Text>
          </View>
        )}

        <View style={styles.statsGrid}>
          <View style={styles.statTile}>
            <Text style={type.label}>Trips today</Text>
            <Text style={[type.num, { fontSize: 22, marginTop: 4 }]}>{todaysCompleted.length}</Text>
          </View>
          <View style={styles.statTile}>
            <Text style={type.label}>Rating</Text>
            <Text style={[type.num, { fontSize: 22, marginTop: 4 }]}>{user?.driver_profile?.rating_avg?.toFixed(1) ?? '—'}</Text>
          </View>
        </View>

        <PaButton
          title={available ? 'Go offline' : 'Go online'}
          onPress={toggleAvailability}
          loading={toggling}
          variant={available ? 'ghost' : 'primary'}
        />
      </View>
    </ScrollView>
  );
}

function RowCard({ title, subtitle, badge, onPress }: { title: string; subtitle: string; badge: React.ReactNode; onPress: () => void }) {
  return (
    <Pressable style={styles.row} onPress={onPress}>
      <View style={{ flex: 1 }}>
        <Text style={styles.rowTitle}>{title}</Text>
        <Text style={styles.rowSubtitle} numberOfLines={1}>{subtitle}</Text>
      </View>
      {badge}
    </Pressable>
  );
}

function statusLabel(status: string) {
  const map: Record<string, string> = {
    accepted: 'Heading to pickup',
    driver_enroute: 'En route to pickup',
    arrived: 'Arrived at pickup',
    in_progress: 'Trip in progress',
  };
  return map[status] ?? status;
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper },
  mapWrap: { position: 'relative' },
  mapPlaceholder: { height: 280, backgroundColor: '#0A0A0C' },
  mapOverlay: {
    position: 'absolute',
    left: 14,
    top: 14,
    right: 14,
    backgroundColor: pa.paper,
    paddingHorizontal: 14,
    paddingVertical: 12,
    borderRadius: paRadius.sm,
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
  },
  overlayLabel: { ...type.label, marginBottom: 0 },
  overlayNum: { ...type.num, fontSize: 22, color: pa.ink },
  greeting: { ...type.h2, color: pa.ink },
  subtitle: { ...type.small, marginTop: 2, marginBottom: 16 },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: 12,
    backgroundColor: pa.surface,
    borderWidth: 1,
    borderColor: pa.line,
    padding: 14,
    marginBottom: 8,
    minHeight: 56,
    borderRadius: paRadius.sm,
  },
  rowTitle: { fontFamily: paFonts.bold, fontSize: 14, color: pa.ink },
  rowSubtitle: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted, marginTop: 2 },
  emptyRow: { paddingVertical: 20, alignItems: 'center' },
  emptyText: { ...type.small },
  statsGrid: { flexDirection: 'row', gap: 1, backgroundColor: pa.line, borderWidth: 1, borderColor: pa.line, marginBottom: 14 },
  statTile: { flex: 1, backgroundColor: pa.surface, padding: 16, minHeight: 78 },
});
