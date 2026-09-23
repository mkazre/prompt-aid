import React, { useCallback, useState } from 'react';
import { Alert, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { api, apiErrorMessage } from '../../api/client';
import { Ride } from '../../api/types';
import { PaButton, headline } from '../../components/pa';
import { timeAgo } from '../../components/pa/driver-extras';
import { paFonts } from '../../theme';

/** Full-detail view of one available offer — driver-offer.html, adapted to
 * real fields only (no server-authoritative countdown exists in the API,
 * so we show how long ago it was requested instead of a fake expiry timer). */
export default function TripOfferScreen({ route, navigation }: any) {
  const { rideId } = route.params;
  const [ride, setRide] = useState<Ride | null>(null);
  const [loading, setLoading] = useState(true);
  const [accepting, setAccepting] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const { data } = await api.get(`/rides/${rideId}`);
      setRide(data.data as Ride);
    } catch {
      setRide(null);
    } finally {
      setLoading(false);
    }
  }, [rideId]);

  useFocusEffect(useCallback(() => { load(); }, [load]));

  async function accept() {
    if (!ride) return;
    setAccepting(true);
    try {
      await api.post(`/driver/rides/${ride.id}/accept`);
      navigation.navigate('Drive', { screen: 'Trip' });
    } catch (e) {
      Alert.alert('Could not accept trip', apiErrorMessage(e));
      await load();
    } finally {
      setAccepting(false);
    }
  }

  if (loading || !ride) {
    return (
      <View style={styles.container}>
        <Text style={styles.muted}>{loading ? 'Loading offer…' : 'This offer is no longer available.'}</Text>
      </View>
    );
  }

  return (
    <ScrollView style={styles.container} contentContainerStyle={{ padding: 20 }}>
      {ride.priority === 'emergency' && (
        <View style={styles.eyebrowRow}>
          <View style={styles.badge}>
            <Text style={styles.badgeText}>Orange</Text>
          </View>
          <Text style={styles.eyebrow}>Emergency priority</Text>
        </View>
      )}
      <Text style={styles.title}>{ride.ride_ref}</Text>
      <Text style={styles.subtitle}>Requested {timeAgo(ride.requested_at)}</Text>

      <Detail label="Pickup" value={ride.pickup_address} />
      <Detail label="Drop-off" value={ride.dropoff_address} />
      <Detail label="Vehicle" value={headline(ride.vehicle_type)} />
      {ride.distance_km != null && <Detail label="Distance" value={`${ride.distance_km.toFixed(1)} km`} />}
      <Detail label="Fare" value={`R ${ride.fare_estimate?.toFixed(0) ?? '—'}${ride.priority === 'emergency' ? ' incl. priority' : ''}`} last />

      <View style={styles.actions}>
        <PaButton title="Pass" onPress={() => navigation.goBack()} variant="dark-ghost" style={{ flex: 1 }} />
        <PaButton title="Accept" onPress={accept} loading={accepting} variant="beacon" style={{ flex: 2 }} />
      </View>
    </ScrollView>
  );
}

function Detail({ label, value, last }: { label: string; value: string; last?: boolean }) {
  return (
    <View style={[detailStyles.row, last && { borderBottomWidth: 0 }]}>
      <Text style={detailStyles.label}>{label}</Text>
      <Text style={detailStyles.value}>{value}</Text>
    </View>
  );
}

const detailStyles = StyleSheet.create({
  row: { flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 10, borderBottomWidth: 1, borderBottomColor: '#2A2A2E' },
  label: { fontFamily: paFonts.regular, fontSize: 14, color: '#8A857C' },
  value: { fontFamily: paFonts.bold, fontSize: 14, color: '#fff', maxWidth: '65%', textAlign: 'right' },
});

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#101012' },
  muted: { color: '#8A857C', fontFamily: paFonts.regular, padding: 24, textAlign: 'center' },
  eyebrowRow: { flexDirection: 'row', alignItems: 'center', gap: 8, marginBottom: 12 },
  badge: { backgroundColor: '#E4701E', paddingHorizontal: 10, paddingVertical: 4, borderRadius: 2 },
  badgeText: { color: '#fff', fontFamily: paFonts.bold, fontSize: 11 },
  eyebrow: { fontFamily: paFonts.black, fontSize: 11, letterSpacing: 1.4, textTransform: 'uppercase', color: '#E4701E' },
  title: { fontFamily: paFonts.black, fontSize: 26, color: '#fff', marginBottom: 4 },
  subtitle: { fontFamily: paFonts.regular, fontSize: 13, color: '#8A857C', marginBottom: 16 },
  actions: { flexDirection: 'row', gap: 8, marginTop: 20 },
});
