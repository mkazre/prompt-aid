import React, { useCallback, useState } from 'react';
import { ActivityIndicator, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { api } from '../../api/client';
import { Ride } from '../../api/types';
import { PaBadge, PaCard, headline } from '../../components/pa';
import { formatDate, formatTime } from '../../components/pa/driver-extras';
import RideMap from '../../components/RideMap';
import { pa, paFonts, type } from '../../theme';

/** Read-only detail for a past, scheduled or in-flight trip — reached from
 * the Trips list and the home screen's "next" row. */
export default function TripDetailScreen({ route }: any) {
  const { rideId } = route.params;
  const [ride, setRide] = useState<Ride | null>(null);
  const [loading, setLoading] = useState(true);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const { data } = await api.get(`/rides/${rideId}`);
      setRide(data.data as Ride);
    } finally {
      setLoading(false);
    }
  }, [rideId]);

  useFocusEffect(useCallback(() => { load(); }, [load]));

  if (loading || !ride) {
    return (
      <View style={styles.center}>
        <ActivityIndicator color={pa.signal} />
      </View>
    );
  }

  return (
    <ScrollView style={styles.container}>
      <RideMap pickup={{ lat: ride.pickup_lat, lng: ride.pickup_lng }} dropoff={{ lat: ride.dropoff_lat, lng: ride.dropoff_lng }} height={220} />
      <View style={{ padding: 16 }}>
        <View style={styles.spread}>
          <Text style={type.h3}>{ride.ride_ref}</Text>
          <PaBadge status={ride.status} />
        </View>
        <Text style={styles.when}>
          {ride.scheduled_for ? `Scheduled ${formatDate(ride.scheduled_for)} · ${formatTime(ride.scheduled_for)}` : `Requested ${formatDate(ride.requested_at)} · ${formatTime(ride.requested_at)}`}
        </Text>

        <PaCard style={{ marginTop: 16 }}>
          <Row label="Pickup" value={ride.pickup_address} />
          <Row label="Drop-off" value={ride.dropoff_address} />
          <Row label="Vehicle" value={headline(ride.vehicle_type)} />
          {ride.distance_km != null && <Row label="Distance" value={`${ride.distance_km.toFixed(1)} km`} />}
          <Row label="Fare" value={`R ${(ride.fare_final ?? ride.fare_estimate ?? 0).toFixed(0)}`} last />
        </PaCard>

        {ride.patient?.user?.name && (
          <PaCard style={{ marginTop: 12 }}>
            <Row label="Patient" value={ride.patient.user.name} last={!ride.patient.user.phone} />
            {ride.patient.user.phone && <Row label="Phone" value={ride.patient.user.phone} last />}
          </PaCard>
        )}
      </View>
    </ScrollView>
  );
}

function Row({ label, value, last }: { label: string; value: string; last?: boolean }) {
  return (
    <View style={[rowStyles.row, last && { borderBottomWidth: 0 }]}>
      <Text style={rowStyles.label}>{label}</Text>
      <Text style={rowStyles.value}>{value}</Text>
    </View>
  );
}

const rowStyles = StyleSheet.create({
  row: { flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 9, borderBottomWidth: 1, borderBottomColor: pa.lineSoft, gap: 12 },
  label: { fontFamily: paFonts.regular, fontSize: 13, color: pa.muted },
  value: { fontFamily: paFonts.bold, fontSize: 13, color: pa.ink, textAlign: 'right', maxWidth: '65%' },
});

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: pa.paper },
  spread: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginTop: 12 },
  when: { ...type.small, marginTop: 4 },
});
