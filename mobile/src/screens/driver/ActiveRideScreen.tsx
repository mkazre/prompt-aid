import React, { useCallback, useState } from 'react';
import { Alert, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { api, apiErrorMessage } from '../../api/client';
import { Ride } from '../../api/types';
import { Badge, Card, EmptyState, PrimaryButton } from '../../components/UI';
import RideMap from '../../components/RideMap';
import { colors, font, spacing } from '../../theme';

const NEXT_STATUS: Record<string, { next: string; label: string } | undefined> = {
  accepted: { next: 'driver_enroute', label: "I'm on my way" },
  driver_enroute: { next: 'arrived', label: 'I have arrived' },
  arrived: { next: 'in_progress', label: 'Start ride' },
  in_progress: { next: 'completed', label: 'Complete ride' },
};

export default function ActiveRideScreen({ navigation }: any) {
  const [ride, setRide] = useState<Ride | null>(null);
  const [updating, setUpdating] = useState(false);

  const load = useCallback(async () => {
    const { data } = await api.get('/driver/rides');
    const active = (data.data as Ride[]).find((r) => !['completed', 'cancelled'].includes(r.status));
    setRide(active ?? null);
  }, []);

  useFocusEffect(useCallback(() => { load(); }, [load]));

  async function advance() {
    if (!ride) return;
    const step = NEXT_STATUS[ride.status];
    if (!step) return;

    setUpdating(true);
    try {
      await api.post(`/driver/rides/${ride.id}/status`, { status: step.next });
      if (step.next === 'completed') {
        Alert.alert('Ride completed', 'Great job! You are ready for your next ride.');
        navigation.navigate('DriverHome');
      } else {
        await load();
      }
    } catch (e) {
      Alert.alert('Could not update ride', apiErrorMessage(e));
    } finally {
      setUpdating(false);
    }
  }

  if (!ride) {
    return (
      <View style={styles.container}>
        <EmptyState message="No active ride right now." />
      </View>
    );
  }

  const step = NEXT_STATUS[ride.status];

  return (
    <ScrollView style={styles.container} contentContainerStyle={{ padding: spacing.lg }}>
      <Text style={styles.title}>Active Ride</Text>
      <View style={{ marginTop: spacing.md }}>
        <RideMap
          pickup={{ lat: ride.pickup_lat, lng: ride.pickup_lng }}
          dropoff={{ lat: ride.dropoff_lat, lng: ride.dropoff_lng }}
        />
      </View>
      <Card style={{ marginTop: spacing.md }}>
        <View style={styles.rowBetween}>
          <Text style={styles.ref}>{ride.ride_ref}</Text>
          <Badge status={ride.status} />
        </View>
        <View style={{ marginTop: spacing.md }}>
          <Text style={styles.label}>Pickup</Text>
          <Text style={styles.value}>📍 {ride.pickup_address}</Text>
        </View>
        <View style={{ marginTop: spacing.md }}>
          <Text style={styles.label}>Drop-off</Text>
          <Text style={styles.value}>🏥 {ride.dropoff_address}</Text>
        </View>
        <View style={{ marginTop: spacing.md }}>
          <Text style={styles.label}>Fare</Text>
          <Text style={styles.value}>R{(ride.fare_final ?? ride.fare_estimate)?.toFixed(0)} · {ride.distance_km?.toFixed(1)} km</Text>
        </View>
      </Card>

      {step && (
        <View style={{ marginTop: spacing.lg }}>
          <PrimaryButton title={step.label} onPress={advance} loading={updating} />
        </View>
      )}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: colors.gray50 },
  title: { fontFamily: font.bold, fontSize: 20, color: colors.secondary },
  rowBetween: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  ref: { fontFamily: font.semibold, color: colors.secondary },
  label: { fontFamily: font.medium, fontSize: 11, color: colors.gray500, textTransform: 'uppercase' },
  value: { fontFamily: font.regular, fontSize: 15, color: colors.gray900, marginTop: 2 },
});
