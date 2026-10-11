import React, { useCallback, useEffect, useState } from 'react';
import { Alert, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { api, apiErrorMessage } from '../../api/client';
import { Ride, RideQuote, VehicleType } from '../../api/types';
import { PaButton } from '../../components/pa';
import AddressAutocomplete, { GeoPoint } from '../../components/AddressAutocomplete';
import RideMap from '../../components/RideMap';
import { pa, paFonts } from '../../theme';
import SuccessModal from '../../components/SuccessModal';

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
  const [dropoff, setDropoff] = useState<string>(route?.params?.to ?? '');
  const [pickupCoords, setPickupCoords] = useState<GeoPoint | null>(null);
  const [dropoffCoords, setDropoffCoords] = useState<GeoPoint | null>(paramPoint(route?.params?.toLat, route?.params?.toLng));
  const [quotes, setQuotes] = useState<RideQuote[]>([]);
  const [vehicleType, setVehicleType] = useState<VehicleType>('sedan');
  const [waitAndReturn, setWaitAndReturn] = useState(true);
  const [loadingQuotes, setLoadingQuotes] = useState(false);
  const [requesting, setRequesting] = useState(false);
  const [requested, setRequested] = useState<{ id: number; fare: number | null } | null>(null);

  const checkActive = useCallback(async () => {
    const { data } = await api.get('/rides');
    const rides: Ride[] = data.data;
    setActiveRide(rides.find((r) => !['completed', 'cancelled'].includes(r.status)) ?? null);
  }, []);

  useFocusEffect(useCallback(() => { checkActive(); }, [checkActive]));

  useEffect(() => {
    if (activeRide && !requested) navigation.navigate('RideTrack', { rideId: activeRide.id });
  }, [activeRide?.id]);

  // Arriving from "Add a shuttle" on a doctor/clinic: pre-fill that drop-off.
  useEffect(() => {
    const to = route?.params?.to;
    if (!to) return;
    setDropoff(to);
    setDropoffCoords(paramPoint(route?.params?.toLat, route?.params?.toLng));
    setQuotes([]);
  }, [route?.params?.to, route?.params?.toLat, route?.params?.toLng]);

  function changePickup(address: string, coords: GeoPoint | null) {
    setPickup(address);
    setPickupCoords(coords);
    setQuotes([]); // any previous fare no longer matches the trip
  }

  function changeDropoff(address: string, coords: GeoPoint | null) {
    setDropoff(address);
    setDropoffCoords(coords);
    setQuotes([]);
  }

  async function getQuote() {
    if (!pickup.trim() || !dropoff.trim()) {
      Alert.alert('Missing info', 'Enter both a pickup and a drop-off address.');
      return;
    }
    setLoadingQuotes(true);
    try {
      if (pickupCoords && dropoffCoords) {
        // Both points already resolved (picked suggestion / GPS) — price directly.
        const { data } = await api.post('/rides/quote', {
          pickup_lat: pickupCoords.lat,
          pickup_lng: pickupCoords.lng,
          dropoff_lat: dropoffCoords.lat,
          dropoff_lng: dropoffCoords.lng,
        });
        setQuotes(data.quotes);
      } else {
        // Free text for at least one leg — the backend geocodes whatever has
        // no coordinates yet (OpenStreetMap) and returns the resolved points.
        const { data } = await api.post('/rides/quote-by-address', {
          pickup_address: pickup,
          dropoff_address: dropoff,
          ...(pickupCoords ? { pickup_lat: pickupCoords.lat, pickup_lng: pickupCoords.lng } : {}),
          ...(dropoffCoords ? { dropoff_lat: dropoffCoords.lat, dropoff_lng: dropoffCoords.lng } : {}),
        });
        setPickupCoords(data.pickup);
        setDropoffCoords(data.dropoff);
        setQuotes(data.quotes);
      }
    } catch (e) {
      Alert.alert('Could not get a quote', validationMessage(e));
    } finally {
      setLoadingQuotes(false);
    }
  }

  async function requestRide() {
    if (!pickupCoords || !dropoffCoords || !pickup || !dropoff) return;
    setRequesting(true);
    try {
      const { data } = await api.post('/rides', {
        pickup_address: pickup,
        pickup_lat: pickupCoords.lat,
        pickup_lng: pickupCoords.lng,
        dropoff_address: dropoff,
        dropoff_lat: dropoffCoords.lat,
        dropoff_lng: dropoffCoords.lng,
        vehicle_type: vehicleType,
        wait_and_return: waitAndReturn,
      });
      const ride = (data?.data ?? data) as Partial<Ride> | undefined;
      if (ride?.id) {
        // Confirm first; tracking opens from the modal's button.
        setRequested({ id: ride.id, fare: selectedQuote?.fare ?? null });
      } else {
        await checkActive();
      }
    } catch (e) {
      Alert.alert('Could not request ride', validationMessage(e));
    } finally {
      setRequesting(false);
    }
  }

  const selectedQuote = quotes.find((q) => q.vehicle_type === vehicleType);

  return (
    <ScrollView style={styles.container} keyboardShouldPersistTaps="handled">
      <SuccessModal
        visible={!!requested}
        title="Ride requested"
        message="We're finding the nearest available driver. You'll get a notification when one is assigned."
        details={[
          { label: 'Pickup', value: pickup },
          { label: 'Drop-off', value: dropoff },
          ...(requested?.fare != null ? [{ label: 'Estimated fare', value: `R ${Number(requested.fare).toFixed(2)}` }] : []),
        ]}
        primaryLabel="Track my ride"
        onPrimary={() => {
          const id = requested?.id;
          setRequested(null);
          if (id) navigation.navigate('RideTrack', { rideId: id });
        }}
      />
      <View style={styles.mapWrap}>
        {pickupCoords && dropoffCoords ? (
          <RideMap pickup={pickupCoords} dropoff={dropoffCoords} height={220} />
        ) : (
          <View style={[styles.mapPlaceholder, { height: 220 }]} />
        )}
      </View>

      <View style={styles.sheet}>
        <Text style={styles.label}>Pick up</Text>
        <AddressAutocomplete value={pickup} onChange={changePickup} placeholder="Start typing your address" allowCurrentLocation />

        <Text style={styles.label}>Drop off</Text>
        <AddressAutocomplete value={dropoff} onChange={changeDropoff} placeholder="Clinic, pharmacy or lab address" />

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

function paramPoint(lat: unknown, lng: unknown): GeoPoint | null {
  const la = Number(lat);
  const ln = Number(lng);
  if (lat == null || lng == null || !Number.isFinite(la) || !Number.isFinite(ln)) return null;
  return { lat: la, lng: ln };
}

/** Laravel 422s carry per-field messages; show them all (e.g. both addresses not found). */
function validationMessage(e: unknown) {
  const errors = (e as any)?.response?.data?.errors;
  if (errors && typeof errors === 'object') {
    const msgs = Object.values(errors).flat().filter((m): m is string => typeof m === 'string');
    if (msgs.length) return msgs.join('\n');
  }
  return apiErrorMessage(e);
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
