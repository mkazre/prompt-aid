import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Alert, FlatList, RefreshControl, StyleSheet, Switch, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import * as Location from 'expo-location';
import { api, apiErrorMessage } from '../../api/client';
import { Ride } from '../../api/types';
import { useAuth } from '../../context/AuthContext';
import { Card, EmptyState, PrimaryButton } from '../../components/UI';
import { colors, font, spacing } from '../../theme';

export default function DriverHomeScreen({ navigation }: any) {
  const { user, refreshMe } = useAuth();
  const [available, setAvailable] = useState(user?.driver_profile?.availability === 'available');
  const [rides, setRides] = useState<Ride[]>([]);
  const [activeRide, setActiveRide] = useState<Ride | null>(null);
  const [loading, setLoading] = useState(false);
  const locationInterval = useRef<ReturnType<typeof setInterval> | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const [availableRes, mineRes] = await Promise.all([
        api.get('/driver/rides/available'),
        api.get('/driver/rides'),
      ]);
      setRides(availableRes.data.data);
      const mine: Ride[] = mineRes.data.data;
      setActiveRide(mine.find((r) => !['completed', 'cancelled'].includes(r.status)) ?? null);
    } finally {
      setLoading(false);
    }
  }, []);

  useFocusEffect(useCallback(() => { load(); }, [load]));

  useEffect(() => {
    async function startTracking() {
      const perm = await Location.requestForegroundPermissionsAsync();
      if (perm.status !== 'granted') return;

      locationInterval.current = setInterval(async () => {
        const pos = await Location.getCurrentPositionAsync({});
        api.post('/driver/location', { lat: pos.coords.latitude, lng: pos.coords.longitude }).catch(() => {});
      }, 15000);
    }

    if (available) startTracking();
    return () => {
      if (locationInterval.current) clearInterval(locationInterval.current);
    };
  }, [available]);

  async function toggleAvailability(value: boolean) {
    setAvailable(value);
    try {
      await api.post('/driver/availability', { availability: value ? 'available' : 'offline' });
      await refreshMe();
    } catch (e) {
      setAvailable(!value);
      Alert.alert('Could not update status', apiErrorMessage(e));
    }
  }

  async function acceptRide(ride: Ride) {
    try {
      await api.post(`/driver/rides/${ride.id}/accept`);
      await load();
      navigation.navigate('ActiveRide');
    } catch (e) {
      Alert.alert('Could not accept ride', apiErrorMessage(e));
    }
  }

  return (
    <View style={styles.container}>
      <View style={styles.header}>
        <View>
          <Text style={styles.title}>Hi {user?.name?.split(' ')[0]} 🚗</Text>
          <Text style={styles.subtitle}>{available ? "You're online — visible to patients nearby" : 'Go online to receive ride requests'}</Text>
        </View>
        <Switch value={available} onValueChange={toggleAvailability} trackColor={{ true: colors.success }} />
      </View>

      {activeRide && (
        <Card style={{ backgroundColor: colors.accentLight, marginBottom: spacing.md }}>
          <Text style={styles.activeTitle}>Active ride: {activeRide.ride_ref}</Text>
          <Text style={styles.meta}>Pickup: {activeRide.pickup_address}</Text>
          <View style={{ marginTop: spacing.sm }}>
            <PrimaryButton title="Manage active ride" onPress={() => navigation.navigate('ActiveRide')} />
          </View>
        </Card>
      )}

      <Text style={styles.sectionTitle}>Ride requests near you</Text>
      <FlatList
        data={rides}
        keyExtractor={(r) => String(r.id)}
        refreshControl={<RefreshControl refreshing={loading} onRefresh={load} />}
        ListEmptyComponent={!loading ? <EmptyState message={available ? 'No ride requests right now.' : 'Go online to see ride requests.'} /> : null}
        renderItem={({ item }) => (
          <Card style={{ marginBottom: spacing.sm }}>
            <Text style={styles.pickup}>📍 {item.pickup_address}</Text>
            <Text style={styles.meta}>To: {item.dropoff_address}</Text>
            <Text style={styles.fare}>Est. fare: R{item.fare_estimate?.toFixed(0)} · {item.distance_km?.toFixed(1)} km</Text>
            <View style={{ marginTop: spacing.sm }}>
              <PrimaryButton title="Accept ride" onPress={() => acceptRide(item)} disabled={!!activeRide} />
            </View>
          </Card>
        )}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: colors.gray50, padding: spacing.lg },
  header: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: spacing.lg },
  title: { fontFamily: font.bold, fontSize: 20, color: colors.secondary },
  subtitle: { fontFamily: font.regular, fontSize: 12, color: colors.gray600, marginTop: 2, maxWidth: 220 },
  activeTitle: { fontFamily: font.semibold, color: colors.secondary },
  sectionTitle: { fontFamily: font.bold, fontSize: 16, color: colors.secondary, marginBottom: spacing.sm },
  pickup: { fontFamily: font.semibold, color: colors.secondary },
  meta: { fontFamily: font.regular, fontSize: 13, color: colors.gray600, marginTop: 2 },
  fare: { fontFamily: font.medium, fontSize: 13, color: colors.accent, marginTop: 4 },
});
