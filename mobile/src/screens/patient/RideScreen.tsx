import React, { useCallback, useState } from 'react';
import { Alert, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import * as Location from 'expo-location';
import { api, apiErrorMessage } from '../../api/client';
import { Clinic, Ride, RideQuote, VehicleType } from '../../api/types';
import { Badge, Card, Input, PrimaryButton, SectionTitle } from '../../components/UI';
import RideMap from '../../components/RideMap';
import { colors, font, spacing } from '../../theme';

const VEHICLE_LABELS: Record<VehicleType, string> = {
  sedan: '🚗 Sedan',
  suv: '🚙 SUV',
  van: '🚐 Van',
  wheelchair_accessible: '♿ Wheelchair Accessible',
};

export default function RideScreen() {
  const [activeRide, setActiveRide] = useState<Ride | null>(null);
  const [history, setHistory] = useState<Ride[]>([]);
  const [clinics, setClinics] = useState<Clinic[]>([]);
  const [clinicId, setClinicId] = useState<number | null>(null);
  const [pickupAddress, setPickupAddress] = useState('');
  const [pickupCoords, setPickupCoords] = useState<{ lat: number; lng: number } | null>(null);
  const [quotes, setQuotes] = useState<RideQuote[]>([]);
  const [vehicleType, setVehicleType] = useState<VehicleType>('sedan');
  const [loadingQuotes, setLoadingQuotes] = useState(false);
  const [requesting, setRequesting] = useState(false);

  const load = useCallback(async () => {
    const [ridesRes, clinicsRes] = await Promise.all([api.get('/rides'), api.get('/clinics')]);
    const rides: Ride[] = ridesRes.data.data;
    setHistory(rides);
    setActiveRide(rides.find((r) => !['completed', 'cancelled'].includes(r.status)) ?? null);
    setClinics(clinicsRes.data.data);
    if (!clinicId && clinicsRes.data.data.length) setClinicId(clinicsRes.data.data[0].id);
  }, [clinicId]);

  useFocusEffect(useCallback(() => { load(); }, [load]));

  // Poll the active ride for live driver location + status every 6s.
  useFocusEffect(
    useCallback(() => {
      if (!activeRide) return;
      const interval = setInterval(async () => {
        try {
          const { data } = await api.get(`/rides/${activeRide.id}`);
          setActiveRide(data.data);
        } catch {
          // ignore transient errors
        }
      }, 6000);
      return () => clearInterval(interval);
    }, [activeRide?.id])
  );

  async function useMyLocation() {
    const perm = await Location.requestForegroundPermissionsAsync();
    if (perm.status !== 'granted') {
      Alert.alert('Location permission needed', 'Enable location access to auto-fill your pickup point.');
      return;
    }
    const pos = await Location.getCurrentPositionAsync({});
    setPickupCoords({ lat: pos.coords.latitude, lng: pos.coords.longitude });
    if (!pickupAddress) setPickupAddress('My current location');
    fetchQuotes(pos.coords.latitude, pos.coords.longitude);
  }

  async function fetchQuotes(lat: number, lng: number) {
    const clinic = clinics.find((c) => c.id === clinicId);
    if (!clinic) return;
    setLoadingQuotes(true);
    try {
      const { data } = await api.post('/rides/quote', {
        pickup_lat: lat, pickup_lng: lng, dropoff_lat: clinic.lat, dropoff_lng: clinic.lng,
      });
      setQuotes(data.quotes);
    } finally {
      setLoadingQuotes(false);
    }
  }

  async function requestRide() {
    const clinic = clinics.find((c) => c.id === clinicId);
    if (!clinic || !pickupAddress) return;

    setRequesting(true);
    try {
      let coords = pickupCoords;
      if (!coords) {
        const perm = await Location.requestForegroundPermissionsAsync();
        if (perm.status === 'granted') {
          const pos = await Location.getCurrentPositionAsync({});
          coords = { lat: pos.coords.latitude, lng: pos.coords.longitude };
        } else {
          coords = { lat: -26.1076, lng: 28.0567 };
        }
      }

      await api.post('/rides', {
        pickup_address: pickupAddress,
        pickup_lat: coords.lat,
        pickup_lng: coords.lng,
        dropoff_address: clinic.address,
        dropoff_lat: clinic.lat,
        dropoff_lng: clinic.lng,
        vehicle_type: vehicleType,
      });

      setPickupAddress('');
      setPickupCoords(null);
      setQuotes([]);
      await load();
      Alert.alert('Ride requested', 'We are matching you with a nearby driver.');
    } catch (e) {
      Alert.alert('Could not request ride', apiErrorMessage(e));
    } finally {
      setRequesting(false);
    }
  }

  return (
    <ScrollView style={styles.container} contentContainerStyle={{ padding: spacing.lg }}>
      <Text style={styles.title}>Patient Shuttle</Text>
      <Text style={styles.subtitle}>Free door-to-door rides to your appointments.</Text>

      {activeRide ? (
        <View style={{ marginTop: spacing.md }}>
          <RideMap
            pickup={{ lat: activeRide.pickup_lat, lng: activeRide.pickup_lng }}
            dropoff={{ lat: activeRide.dropoff_lat, lng: activeRide.dropoff_lng }}
            driver={activeRide.driver ? { lat: (activeRide.driver as any).current_lat, lng: (activeRide.driver as any).current_lng } : null}
          />
          <Card style={{ marginTop: spacing.sm, backgroundColor: colors.accentLight }}>
            <View style={styles.rowBetween}>
              <Text style={styles.rideRef}>{activeRide.ride_ref}</Text>
              <Badge status={activeRide.status} />
            </View>
            <Text style={styles.meta}>To: {activeRide.dropoff_address}</Text>
            {activeRide.eta_minutes ? <Text style={styles.meta}>ETA: {activeRide.eta_minutes} min</Text> : null}
            {activeRide.driver ? (
              <Text style={styles.meta}>Driver: {activeRide.driver.name} · {activeRide.driver.vehicle_plate_no}</Text>
            ) : (
              <Text style={styles.meta}>Matching you with a driver...</Text>
            )}
          </Card>
        </View>
      ) : (
        <Card style={{ marginTop: spacing.md }}>
          <Input label="Pickup address" value={pickupAddress} onChangeText={setPickupAddress} placeholder="Your address" />
          <PrimaryButton title="📍 Use my current location" variant="outline" onPress={useMyLocation} />

          <Text style={[styles.label, { marginTop: spacing.md }]}>Destination clinic</Text>
          <View style={styles.chipRow}>
            {clinics.map((c) => (
              <Text
                key={c.id}
                onPress={() => { setClinicId(c.id); if (pickupCoords) fetchQuotes(pickupCoords.lat, pickupCoords.lng); }}
                style={[styles.chip, clinicId === c.id && styles.chipActive]}
              >
                {c.name}
              </Text>
            ))}
          </View>

          <Text style={styles.label}>Vehicle type {loadingQuotes ? '(loading fares...)' : ''}</Text>
          {(['sedan', 'suv', 'van', 'wheelchair_accessible'] as VehicleType[]).map((vt) => {
            const quote = quotes.find((q) => q.vehicle_type === vt);
            return (
              <Card
                key={vt}
                style={[styles.vehicleCard, vehicleType === vt && styles.vehicleCardActive]}
              >
                <View style={styles.rowBetween} onTouchEnd={() => setVehicleType(vt)}>
                  <Text style={styles.vehicleLabel}>{VEHICLE_LABELS[vt]}</Text>
                  <Text style={styles.vehicleFare}>{quote ? `R${quote.fare.toFixed(0)} · ${quote.eta_minutes} min` : '—'}</Text>
                </View>
              </Card>
            );
          })}

          <PrimaryButton title="Request a ride" onPress={requestRide} loading={requesting} disabled={!pickupAddress || !clinicId} />
        </Card>
      )}

      <SectionTitle>Ride history</SectionTitle>
      {history.filter((r) => ['completed', 'cancelled'].includes(r.status)).map((r) => (
        <Card key={r.id} style={{ marginBottom: spacing.sm }}>
          <View style={styles.rowBetween}>
            <Text style={styles.meta}>{r.dropoff_address}</Text>
            <Badge status={r.status} />
          </View>
        </Card>
      ))}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: colors.gray50 },
  title: { fontFamily: font.bold, fontSize: 22, color: colors.secondary },
  subtitle: { fontFamily: font.regular, color: colors.gray600, marginTop: 2 },
  rowBetween: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  rideRef: { fontFamily: font.semibold, color: colors.secondary },
  meta: { fontFamily: font.regular, fontSize: 13, color: colors.gray700, marginTop: 4 },
  label: { fontFamily: font.medium, fontSize: 12, color: colors.gray600, marginBottom: 6 },
  chipRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginBottom: spacing.md },
  chip: { borderWidth: 1, borderColor: colors.gray300, borderRadius: 10, paddingHorizontal: 12, paddingVertical: 8, fontFamily: font.medium, fontSize: 12, color: colors.gray700, overflow: 'hidden' },
  chipActive: { backgroundColor: colors.primary, color: colors.white, borderColor: colors.primary },
  vehicleCard: { marginBottom: spacing.sm, borderWidth: 1, borderColor: colors.gray200 },
  vehicleCardActive: { borderColor: colors.primary, backgroundColor: colors.primaryLight },
  vehicleLabel: { fontFamily: font.medium, fontSize: 14, color: colors.gray800 },
  vehicleFare: { fontFamily: font.semibold, fontSize: 13, color: colors.secondary },
});
