import React, { useCallback, useEffect, useState } from 'react';
import { Alert, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import * as Location from 'expo-location';
import { api, apiErrorMessage } from '../../api/client';
import { Ride, RideQuote, VehicleType } from '../../api/types';
import { PaButton, PaInput } from '../../components/pa';
import RideMap from '../../components/RideMap';
import { pa, paFonts } from '../../theme';

const VEHICLE_LABELS: Record<VehicleType, { label: string; sub: string }> = {
  sedan: { label: 'Standard', sub: 'Sedan, 1–3 seats' },
  suv: { label: 'Comfort', sub: 'SUV, extra legroom' },
  van: { label: 'Van', sub: 'Larger group transfer' },
  wheelchair_accessible: { label: 'Wheelchair', sub: 'Ramp and restraints' },
  stretcher: { label: 'Stretcher', sub: 'Non-emergency transfer' },
};

export default function ShuttleRequestScreen({ navigation, route }: any) {
  const [activeRide, setActiveRide] = useState<Ride | null>(null);
  const [pickup, setPickup] = useState('');
  const [dropoff, setDropoff] = useState(route?.params?.to ?? '');
  const [pickupCoords, setPickupCoords] = useState<{ lat: number; lng: number } | null>(null);
  const [dropoffCoords, setDropoffCoords] = useState<{ lat: number; lng: number } | null>(null);
  const [quotes, setQuotes] = useState<RideQuote[]>([]);
  const [vehicleType, setVehicleType] = useState<VehicleType>('sedan');
  const [waitAndReturn, setWaitAndReturn] = useState(true);
  const [loadingQuotes, setLoadingQuotes] = useState(false);
  const [requesting, setRequesting] = useState(false);

  const checkActive = useCallback(async () => {
    const { data } = await api.get('/rides');
    const rides: Ride[] = data.data;
    setActiveRide(rides.find((r) => !['completed', 'cancelled'].includes(r.status)) ?? null);
  }, []);

  useFocusEffect(useCallback(() => { checkActive(); }, [checkActive]));

  useEffect(() => {
    if (activeRide) navigation.navigate('RideTrack', { rideId: activeRide.id });
  }, [activeRide?.id]);

  async function useMyLocation() {
    const perm = await Location.requestForegroundPermissionsAsync();
    if (perm.status !== 'granted') {
      Alert.alert('Location permission needed', 'Enable location access to auto-fill your pickup point.');
      return;
    }
    const pos = await Location.getCurrentPositionAsync({});
    const coords = { lat: pos.coords.latitude, lng: pos.coords.longitude };
    setPickupCoords(coords);
    if (!pickup) setPickup('My current location');
  }

  async function getQuote() {
    if (!pickup || !dropoff) {
      Alert.alert('Missing info', 'Enter both a pickup and a drop-off address.');
      return;
    }
    setLoadingQuotes(true);
    try {
      if (pickupCoords) {
        // Real GPS coordinates already captured via "Use my current
        // location" — only the drop-off needs geocoding, and re-geocoding
        // the placeholder pickup text would throw away a real position for
        // a deterministic guess.
        const geocoded = await api.post('/rides/quote-by-address', { pickup_address: dropoff, dropoff_address: dropoff });
        const dropoffPoint = geocoded.data.pickup as { lat: number; lng: number };
        setDropoffCoords(dropoffPoint);
        const { data } = await api.post('/rides/quote', {
          pickup_lat: pickupCoords.lat,
          pickup_lng: pickupCoords.lng,
          dropoff_lat: dropoffPoint.lat,
          dropoff_lng: dropoffPoint.lng,
        });
        setQuotes(data.quotes);
      } else {
        // No on-device geocoder — the backend resolves both addresses to
        // coordinates the same way the website's shuttle page does, and
        // hands back the resolved points so booking doesn't need a second
        // geocode round-trip.
        const { data } = await api.post('/rides/quote-by-address', {
          pickup_address: pickup,
          dropoff_address: dropoff,
        });
        setPickupCoords(data.pickup);
        setDropoffCoords(data.dropoff);
        setQuotes(data.quotes);
      }
    } catch (e) {
      Alert.alert('Could not get a quote', apiErrorMessage(e));
    } finally {
      setLoadingQuotes(false);
    }
  }

  async function requestRide() {
    if (!pickupCoords || !dropoffCoords || !pickup || !dropoff) return;
    setRequesting(true);
    try {
      await api.post('/rides', {
        pickup_address: pickup,
        pickup_lat: pickupCoords.lat,
        pickup_lng: pickupCoords.lng,
        dropoff_address: dropoff,
        dropoff_lat: dropoffCoords.lat,
        dropoff_lng: dropoffCoords.lng,
        vehicle_type: vehicleType,
        wait_and_return: waitAndReturn,
      });
      await checkActive();
    } catch (e) {
      Alert.alert('Could not request ride', apiErrorMessage(e));
    } finally {
      setRequesting(false);
    }
  }

  const selectedQuote = quotes.find((q) => q.vehicle_type === vehicleType);

  return (
    <ScrollView style={styles.container}>
      <View style={styles.mapWrap}>
        {pickupCoords && dropoffCoords ? (
          <RideMap pickup={pickupCoords} dropoff={dropoffCoords} height={220} />
        ) : (
          <View style={[styles.mapPlaceholder, { height: 220 }]} />
        )}
      </View>

      <View style={styles.sheet}>
        <Text style={styles.label}>Pick up</Text>
        <PaInput value={pickup} onChangeText={setPickup} placeholder="Home address" />
        <PaButton title="📍 Use my current location" variant="ghost" onPress={useMyLocation} style={{ marginBottom: 14 }} />

        <Text style={styles.label}>Drop off</Text>
        <PaInput value={dropoff} onChangeText={setDropoff} placeholder="Clinic, pharmacy or lab" />

        <Pressable onPress={() => setWaitAndReturn((v) => !v)} style={[styles.checkRow, waitAndReturn && styles.checkRowOn]}>
          <Text style={styles.checkText}>Return leg</Text>
        </Pressable>

        <PaButton title={loadingQuotes ? 'Getting quote…' : 'Get fare estimate'} onPress={getQuote} disabled={loadingQuotes} style={{ marginVertical: 12 }} />

        {quotes.length > 0 && (
          <>
            {(Object.keys(VEHICLE_LABELS) as VehicleType[]).map((vt) => {
              const q = quotes.find((x) => x.vehicle_type === vt);
              if (!q) return null;
              const meta = VEHICLE_LABELS[vt];
              const active = vehicleType === vt;
              return (
                <Pressable key={vt} onPress={() => setVehicleType(vt)} style={[styles.vehicleRow, active && styles.vehicleRowActive]}>
                  <View>
                    <Text style={styles.vehicleLabel}>{meta.label}</Text>
                    <Text style={styles.vehicleSub}>{meta.sub}</Text>
                  </View>
                  <View style={{ alignItems: 'flex-end' }}>
                    <Text style={styles.vehicleFare}>R {Math.round(q.fare)}</Text>
                    <Text style={styles.vehicleEta}>{q.eta_minutes} min away</Text>
                  </View>
                </Pressable>
              );
            })}

            <PaButton
              title={selectedQuote ? `Confirm · R ${Math.round(selectedQuote.fare)}` : 'Confirm'}
              onPress={requestRide}
              loading={requesting}
              disabled={!selectedQuote}
              style={{ marginTop: 8 }}
            />
          </>
        )}

        <PaButton title="Recurring trips" variant="ghost" onPress={() => navigation.navigate('RideSeries')} style={{ marginTop: 12 }} />
      </View>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper },
  mapWrap: {},
  mapPlaceholder: { backgroundColor: pa.ink },
  sheet: { padding: 16, marginTop: -26, backgroundColor: pa.paper },
  label: { fontFamily: paFonts.black, fontSize: 11, letterSpacing: 1.2, textTransform: 'uppercase', color: pa.muted, marginBottom: 6 },
  checkRow: { borderWidth: 1, borderColor: pa.line, backgroundColor: pa.surface, padding: 13, marginBottom: 6 },
  checkRowOn: { borderColor: pa.ink, backgroundColor: pa.beaconWash },
  checkText: { fontFamily: paFonts.bold, fontSize: 13, color: pa.ink },
  vehicleRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', borderWidth: 1, borderColor: pa.line, backgroundColor: pa.surface, padding: 13, marginBottom: 6 },
  vehicleRowActive: { borderColor: pa.ink, backgroundColor: pa.beaconWash },
  vehicleLabel: { fontFamily: paFonts.bold, fontSize: 14, color: pa.ink },
  vehicleSub: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted, marginTop: 2 },
  vehicleFare: { fontFamily: paFonts.black, fontSize: 17, color: pa.ink },
  vehicleEta: { fontFamily: paFonts.regular, fontSize: 11, color: pa.muted, marginTop: 2 },
});
