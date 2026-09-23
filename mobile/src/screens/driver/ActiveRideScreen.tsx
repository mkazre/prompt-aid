import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Alert, Linking, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import * as Location from 'expo-location';
import { api, apiErrorMessage } from '../../api/client';
import { Ride } from '../../api/types';
import { PaButton, PaEmptyState } from '../../components/pa';
import RideMap from '../../components/RideMap';
import { pa, paFonts, paRadius, type } from '../../theme';

const NEXT_STATUS: Record<string, { next: string; label: string; primary?: boolean } | undefined> = {
  accepted: { next: 'driver_enroute', label: "I'm on my way" },
  driver_enroute: { next: 'arrived', label: 'Arrived at pickup' },
  arrived: { next: 'in_progress', label: 'Start trip' },
  in_progress: { next: 'completed', label: 'Complete drop-off', primary: true },
};

const ACTIVE_STATUSES = ['accepted', 'driver_enroute', 'arrived', 'in_progress'];

export default function ActiveRideScreen({ navigation }: any) {
  const [ride, setRide] = useState<Ride | null>(null);
  const [updating, setUpdating] = useState(false);
  const [cancelling, setCancelling] = useState(false);
  const [pos, setPos] = useState<{ lat: number; lng: number } | null>(null);
  const locationInterval = useRef<ReturnType<typeof setInterval> | null>(null);

  const load = useCallback(async () => {
    const { data } = await api.get('/driver/rides');
    const active = (data.data as Ride[]).find((r) => ACTIVE_STATUSES.includes(r.status));
    setRide(active ?? null);
  }, []);

  useFocusEffect(useCallback(() => { load(); }, [load]));

  // Live location ping while a trip is in progress, matching the pattern
  // already used on the home screen (POST /driver/location every ~15s).
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

    if (ride) startTracking();
    return () => {
      if (locationInterval.current) clearInterval(locationInterval.current);
    };
  }, [ride?.id]);

  async function advance() {
    if (!ride) return;
    const step = NEXT_STATUS[ride.status];
    if (!step) return;

    setUpdating(true);
    try {
      await api.post(`/driver/rides/${ride.id}/status`, {
        status: step.next,
        lat: pos?.lat,
        lng: pos?.lng,
      });
      if (step.next === 'completed') {
        Alert.alert('Trip completed', 'Great job! You are ready for your next trip.');
        navigation.navigate('Drive');
      } else {
        await load();
      }
    } catch (e) {
      Alert.alert('Could not update trip', apiErrorMessage(e));
    } finally {
      setUpdating(false);
    }
  }

  function call() {
    const phone = ride?.patient?.user?.phone;
    if (!phone) return Alert.alert('No phone number on file for this patient.');
    Linking.openURL(`tel:${phone}`);
  }

  function navigate() {
    if (!ride) return;
    const target = ['accepted', 'driver_enroute'].includes(ride.status)
      ? { lat: ride.pickup_lat, lng: ride.pickup_lng }
      : { lat: ride.dropoff_lat, lng: ride.dropoff_lng };
    Linking.openURL(`https://maps.google.com/?daddr=${target.lat},${target.lng}`);
  }

  function cancel() {
    if (!ride) return;
    Alert.alert('Cancel this trip?', 'The patient will be notified and the trip will be released.', [
      { text: 'Keep trip', style: 'cancel' },
      {
        text: 'Cancel trip',
        style: 'destructive',
        onPress: async () => {
          setCancelling(true);
          try {
            await api.post(`/rides/${ride.id}/cancel`);
            await load();
          } catch (e) {
            Alert.alert('Could not cancel trip', apiErrorMessage(e));
          } finally {
            setCancelling(false);
          }
        },
      },
    ]);
  }

  if (!ride) {
    return (
      <View style={styles.container}>
        <PaEmptyState message="No active trip right now. Accept an offer to get started." />
      </View>
    );
  }

  const step = NEXT_STATUS[ride.status];

  return (
    <ScrollView style={styles.container}>
      <View style={styles.mapWrap}>
        <RideMap
          pickup={{ lat: ride.pickup_lat, lng: ride.pickup_lng }}
          dropoff={{ lat: ride.dropoff_lat, lng: ride.dropoff_lng }}
          driver={pos}
          height={300}
        />
        {ride.eta_minutes != null && (
          <View style={styles.etaOverlay}>
            <Text style={[type.label, { marginBottom: 0 }]}>{['accepted', 'driver_enroute'].includes(ride.status) ? 'Arriving' : 'ETA'}</Text>
            <Text style={[type.num, { fontSize: 22, color: pa.ink }]}>{ride.eta_minutes} min</Text>
          </View>
        )}
      </View>

      <View style={{ padding: 16 }}>
        <View style={styles.spread}>
          <View style={{ flex: 1 }}>
            <Text style={styles.patientName}>{ride.patient?.user?.name ?? 'Patient'}</Text>
            <Text style={styles.meta}>
              → {ride.dropoff_address}
              {ride.wait_and_return ? ' · wait & return' : ''}
            </Text>
          </View>
          <Text style={[type.num, { fontSize: 20 }]}>R {(ride.fare_final ?? ride.fare_estimate ?? 0).toFixed(0)}</Text>
        </View>

        <View style={styles.actionsRow}>
          <PaButton title="Call" onPress={call} variant="ghost" style={styles.actionBtn} />
          <PaButton title="Navigate" onPress={navigate} variant="ghost" style={styles.actionBtn} />
          <PaButton title="Cancel" onPress={cancel} variant="ghost" loading={cancelling} style={styles.actionBtn} />
        </View>

        {step && (
          <PaButton
            title={step.label}
            onPress={advance}
            loading={updating}
            variant={step.primary ? 'primary' : 'ghost'}
          />
        )}
      </View>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper },
  mapWrap: { position: 'relative' },
  etaOverlay: { position: 'absolute', left: 14, top: 14, backgroundColor: pa.paper, paddingHorizontal: 12, paddingVertical: 10, borderRadius: paRadius.sm },
  spread: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 12, gap: 12 },
  patientName: { fontFamily: paFonts.bold, fontSize: 16, color: pa.ink },
  meta: { ...type.small, marginTop: 2 },
  actionsRow: { flexDirection: 'row', gap: 6, marginBottom: 12 },
  actionBtn: { flex: 1, paddingVertical: 10 },
});
