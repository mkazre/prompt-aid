import React, { useCallback, useState } from 'react';
import { Linking, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { api } from '../../api/client';
import { Ride } from '../../api/types';
import { PaAvatar, PaBadge, PaButton } from '../../components/pa';
import RideMap from '../../components/RideMap';
import { pa, paFonts } from '../../theme';

export default function RideTrackScreen({ route }: any) {
  const { rideId } = route.params;
  const [ride, setRide] = useState<Ride | null>(null);

  const poll = useCallback(async () => {
    try {
      const { data } = await api.get(`/rides/${rideId}`);
      setRide(data.data);
    } catch {
      // ignore transient errors, retry next tick
    }
  }, [rideId]);

  useFocusEffect(
    useCallback(() => {
      poll();
      const interval = setInterval(poll, 5000);
      return () => clearInterval(interval);
    }, [poll])
  );

  async function requestReturn() {
    await api.post(`/rides/${rideId}/return`);
    poll();
  }

  if (!ride) return null;

  const driverAny = ride.driver as any;

  return (
    <ScrollView style={styles.container}>
      <RideMap
        pickup={{ lat: ride.pickup_lat, lng: ride.pickup_lng }}
        dropoff={{ lat: ride.dropoff_lat, lng: ride.dropoff_lng }}
        driver={driverAny?.current_lat ? { lat: driverAny.current_lat, lng: driverAny.current_lng } : null}
        height={280}
      />
      <View style={styles.sheet}>
        <View style={styles.topRow}>
          <View style={styles.driverRow}>
            <PaAvatar name={ride.driver?.name} size={46} />
            <View>
              <Text style={styles.driverName}>{ride.driver?.name ?? 'Matching you with a driver…'}</Text>
              {ride.driver ? <Text style={styles.driverMeta}>{ride.driver.vehicle_make} {ride.driver.vehicle_model} · {ride.driver.vehicle_plate_no}</Text> : null}
            </View>
          </View>
          <Text style={styles.rating}>{ride.driver?.rating_avg ? ride.driver.rating_avg.toFixed(1) : ''}</Text>
        </View>

        <View style={styles.actionsRow}>
          <PaButton
            title="Call"
            variant="ghost"
            onPress={() => ride.driver && Linking.openURL(`tel:${ride.driver.phone}`)}
            disabled={!ride.driver}
            style={{ flex: 1 }}
          />
          <PaButton title="Status" variant="ghost" onPress={poll} style={{ flex: 1 }} />
        </View>

        <View style={styles.statusBlock}>
          <Text style={styles.statusLabel}>Status</Text>
          <PaBadge status={ride.status} />
          {ride.eta_minutes ? <Text style={styles.eta}>{ride.eta_minutes} min away</Text> : null}
        </View>

        <View style={styles.fareBlock}>
          <Text style={styles.fareLine}>{ride.pickup_address} → {ride.dropoff_address}</Text>
          <Text style={styles.fareTotal}>R {(ride.fare_final ?? ride.fare_estimate ?? 0).toFixed(2)}</Text>
        </View>

        {ride.status === 'completed' && !ride.is_return && !ride.return_of_ride_id ? (
          <PaButton title="Request return ride" onPress={requestReturn} style={{ marginTop: 12 }} />
        ) : null}
      </View>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper },
  sheet: { padding: 16 },
  topRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 },
  driverRow: { flexDirection: 'row', gap: 12, alignItems: 'center' },
  driverName: { fontFamily: paFonts.bold, fontSize: 15, color: pa.ink },
  driverMeta: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted, marginTop: 2 },
  rating: { fontFamily: paFonts.black, fontSize: 15, color: pa.ink },
  actionsRow: { flexDirection: 'row', gap: 8, marginBottom: 14 },
  statusBlock: { flexDirection: 'row', alignItems: 'center', gap: 10, marginBottom: 14 },
  statusLabel: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted },
  eta: { fontFamily: paFonts.bold, fontSize: 12, color: pa.go },
  fareBlock: { borderTopWidth: 1, borderTopColor: pa.line, paddingTop: 12 },
  fareLine: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted, marginBottom: 6 },
  fareTotal: { fontFamily: paFonts.black, fontSize: 22, color: pa.ink },
});
