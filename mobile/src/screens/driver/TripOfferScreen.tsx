import React, { useCallback, useState } from 'react';
import { ActivityIndicator, Alert, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { api, apiErrorMessage } from '../../api/client';
import { Ride } from '../../api/types';
import { PaButton, headline } from '../../components/pa';
import { SwipeToAccept } from '../../components/IncomingRequestModal';
import RideMap from '../../components/RideMap';
import RiderCard from '../../components/RiderCard';
import { DispatchOfferCard, InboxResponse } from '../../services/dispatch';
import { pa, paFonts } from '../../theme';

/**
 * Deep-link / list entry for one trip request. If the ride is currently
 * offered to this driver it opens the same full-screen IncomingRequestModal
 * as live alerts (countdown, swipe right to accept, decline, "taken" state).
 * Otherwise (open queue) it shows the request with swipe-to-accept — and
 * stale / closed requests are shown as no longer available, never offered.
 */
export default function TripOfferScreen({ route, navigation }: any) {
  const { rideId } = route.params;
  const [ride, setRide] = useState<Ride | null>(null);
  const [offer, setOffer] = useState<DispatchOfferCard | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    try {
      const [{ data: box }, rideRes] = await Promise.all([
        api.get('/dispatch/inbox'),
        api.get(`/rides/${rideId}`).catch(() => null),
      ]);
      const b = box as InboxResponse;
      setOffer(b.offers.find((o) => o.service === 'shuttle' && o.request_id === Number(rideId)) ?? null);
      setRide((rideRes?.data?.data as Ride) ?? null);
    } catch {
      setRide(null);
    } finally {
      setLoading(false);
    }
  }, [rideId]);

  useFocusEffect(
    useCallback(() => {
      load();
      const t = setInterval(load, 4000);
      return () => clearInterval(t);
    }, [load]),
  );

  async function claim() {
    if (!ride) return;
    setBusy(true);
    try {
      await api.post(`/driver/rides/${ride.id}/accept`);
      navigation.navigate('Drive', { screen: 'Trip' });
    } catch (e) {
      Alert.alert('Could not accept trip', apiErrorMessage(e));
      await load();
    } finally {
      setBusy(false);
    }
  }

  if (loading) {
    return (
      <View style={[styles.container, { alignItems: 'center', justifyContent: 'center' }]}>
        <ActivityIndicator color={pa.beacon} />
      </View>
    );
  }

  if (offer) {
    // Live offer: the app-wide IncomingRequestModal (mounted at the root) is already
    // showing it with the countdown, sound and swipe-to-accept — one alert, not two.
    return (
      <View style={[styles.container, { justifyContent: 'center' }]}>
        <Text style={styles.muted}>This trip request is open in the alert — swipe right to accept it there.</Text>
      </View>
    );
  }

  const available = !!ride && ride.status === 'requested' && ride.is_active !== false;

  if (!ride || !available) {
    return (
      <View style={styles.container}>
        <Text style={styles.muted}>This request is no longer available{ride?.status_label ? ` (${ride.status_label})` : ''}.</Text>
        <View style={{ paddingHorizontal: 20 }}>
          <PaButton title="Back to offers" onPress={() => navigation.goBack()} variant="dark-ghost" />
        </View>
      </View>
    );
  }

  return (
    <ScrollView style={styles.container} contentContainerStyle={{ padding: 20 }}>
      <Text style={styles.title}>R {Math.round(ride.fare_estimate ?? 0)}</Text>
      <Text style={styles.subtitle}>
        {headline(ride.vehicle_type)}
        {ride.distance_km != null ? ` · trip ${ride.distance_km.toFixed(1)} km` : ''}
        {ride.scheduled_for ? ` · scheduled ${new Date(ride.scheduled_for).toLocaleString([], { weekday: 'short', hour: '2-digit', minute: '2-digit' })}` : ''}
      </Text>
      <View style={styles.route}>
        <Text style={styles.addr}>● {ride.pickup_address}</Text>
        <Text style={[styles.addr, { marginTop: 8 }]}>■ {ride.dropoff_address}</Text>
      </View>
      <View style={{ borderRadius: 12, overflow: 'hidden', marginBottom: 14 }}>
        <RideMap pickup={{ lat: ride.pickup_lat, lng: ride.pickup_lng }} dropoff={{ lat: ride.dropoff_lat, lng: ride.dropoff_lng }} height={170} />
      </View>
      <RiderCard rider={ride.rider} dark compact />
      <SwipeToAccept onComplete={claim} disabled={busy} />
      <View style={{ height: 10 }} />
      <PaButton title="Pass" onPress={() => navigation.goBack()} variant="dark-ghost" />
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#101012' },
  muted: { color: '#C9C4BA', fontFamily: paFonts.regular, padding: 24, textAlign: 'center' },
  title: { fontFamily: paFonts.black, fontSize: 36, color: '#fff' },
  subtitle: { fontFamily: paFonts.regular, fontSize: 14, color: '#C9C4BA', marginBottom: 14 },
  route: { backgroundColor: '#1C1C20', borderRadius: 12, padding: 12, marginBottom: 12 },
  addr: { color: '#fff', fontFamily: paFonts.bold, fontSize: 15 },
});
