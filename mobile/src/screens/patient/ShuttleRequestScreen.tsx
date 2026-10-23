import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Alert, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { Ionicons } from '@expo/vector-icons';
import { api, apiErrorMessage } from '../../api/client';
import { RideQuote, VehicleType } from '../../api/types';
import { fetchActiveRide, RideLike } from '../../services/activeRide';
import { PaButton } from '../../components/pa';
import AddressAutocomplete, { GeoPoint } from '../../components/AddressAutocomplete';
import RideMap from '../../components/RideMap';
import { pa, paFonts } from '../../theme';
import SuccessModal from '../../components/SuccessModal';
import WidgetHost from '../../widgets/WidgetHost';
import { PaymentSheet } from '../../components/payments';

/** Upfront ride payment block from the API (RideResource.payment). */
type RidePayment = { required: boolean; state: string; amount: number; pay_by: string | null };
const ridePayment = (r: unknown): RidePayment | null => ((r as { payment?: RidePayment } | null)?.payment ?? null);

const VEHICLE_LABELS: Record<VehicleType, { label: string; sub: string }> = {
  sedan: { label: 'Standard', sub: 'Sedan, 1–3 seats' },
  suv: { label: 'Comfort', sub: 'SUV, extra legroom' },
  van: { label: 'Van', sub: 'Larger group transfer' },
  wheelchair_accessible: { label: 'Wheelchair', sub: 'Ramp and restraints' },
  stretcher: { label: 'Stretcher', sub: 'Non-emergency transfer' },
};

export default function ShuttleRequestScreen({ navigation, route }: any) {
  const insets = useSafeAreaInsets();
  const scrollRef = useRef<ScrollView>(null);
  const sheetY = useRef(0);
  const fieldY = useRef<{ pickup: number; dropoff: number }>({ pickup: 0, dropoff: 0 });
  const redirectedFor = useRef<number | null>(null);
  const [activeRide, setActiveRide] = useState<RideLike | null>(null);
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
  // Uber-style: the trip only goes to drivers once this payment is secured.
  const [payRide, setPayRide] = useState<{ id: number; amount: number; fare: number | null } | null>(null);
  const [cancellingUnpaid, setCancellingUnpaid] = useState(false);

  function resetForm() {
    setPickup('');
    setPickupCoords(null);
    setDropoff('');
    setDropoffCoords(null);
    setQuotes([]);
    setVehicleType('sedan');
    setWaitAndReturn(true);
  }

  // Only a TRULY active ride (server is_active / non-terminal and not stale)
  // counts: a long-finished or cancelled ride must never hijack this screen.
  const hadActive = useRef(false);
  const checkActive = useCallback(async () => {
    let ride: RideLike | null = null;
    try {
      ride = await fetchActiveRide();
    } catch {
      return; // offline: keep showing the form
    }
    // The ride we were tracking has ended / been cancelled: start from a clean form.
    if (hadActive.current && !ride) resetForm();
    hadActive.current = !!ride;
    setActiveRide(ride);
  }, []);

  useFocusEffect(useCallback(() => { checkActive(); }, [checkActive]));

  // Auto-open tracking once per active ride; going back shows the form plus a "Track" card.
  useEffect(() => {
    if (activeRide && !requested && !payRide && !ridePayment(activeRide)?.required && redirectedFor.current !== activeRide.id) {
      redirectedFor.current = activeRide.id;
      navigation.navigate('RideTrack', { rideId: activeRide.id });
    }
  }, [activeRide?.id]);

  // Keep the focused address field near the top so its suggestions sit above the keyboard.
  function scrollToField(which: 'pickup' | 'dropoff') {
    const y = sheetY.current + fieldY.current[which] - 12;
    setTimeout(() => scrollRef.current?.scrollTo({ y: Math.max(0, y), animated: true }), 150);
  }

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
      const ride = (data?.data ?? data) as Partial<RideLike> | undefined;
      const pay = ridePayment(ride);
      if (ride?.id && pay?.required) {
        // Pay first (in-app sheet); drivers are alerted once the payment is secured.
        setPayRide({ id: ride.id, amount: pay.amount, fare: selectedQuote?.fare ?? null });
      } else if (ride?.id) {
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

  async function cancelUnpaid() {
    if (!activeRide) return;
    setCancellingUnpaid(true);
    try {
      await api.post(`/rides/${activeRide.id}/cancel`, { reason: 'Payment not completed' });
    } catch (e) {
      Alert.alert('Could not cancel', apiErrorMessage(e));
    } finally {
      setCancellingUnpaid(false);
      checkActive();
    }
  }

  return (
    <ScrollView
      ref={scrollRef}
      style={styles.container}
      contentContainerStyle={{ paddingTop: insets.top + 8, paddingBottom: 280 }}
      keyboardShouldPersistTaps="handled"
    >
      <PaymentSheet
        visible={!!payRide}
        payable={payRide ? { type: 'ride', id: payRide.id } : undefined}
        amount={payRide?.amount}
        title="Shuttle trip"
        onPaid={() => {
          const p = payRide;
          setPayRide(null);
          if (p) setRequested({ id: p.id, fare: p.fare });
          checkActive();
        }}
        onClose={() => {
          setPayRide(null);
          checkActive();
        }}
      />
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
          if (id) {
            redirectedFor.current = id;
            hadActive.current = true; // so a cancel/finish later resets the form
            navigation.navigate('RideTrack', { rideId: id });
          }
        }}
      />
      <Text style={styles.title}>Book a shuttle</Text>
      <View style={styles.mapWrap}>
        {/* Always a real map: device location / Johannesburg until addresses are picked, then pins. */}
        <RideMap pickup={pickupCoords} dropoff={dropoffCoords} showUserLocation={!pickupCoords && !dropoffCoords} height={220} />
      </View>

      {/* Admin-managed banners for this screen (Site → App Widgets, screen "Shuttle"). */}
      <WidgetHost screen="shuttle" style={{ marginHorizontal: 16, marginTop: 12 }} />

      <View style={styles.sheet} onLayout={(e) => { sheetY.current = e.nativeEvent.layout.y; }}>
        {activeRide && ridePayment(activeRide)?.required ? (
          <View style={styles.activeCard}>
            <Ionicons name="card-outline" size={26} color={pa.signal} />
            <View style={{ flex: 1 }}>
              <Text style={styles.activeTitle}>Waiting for payment</Text>
              <Text style={styles.activeSub} numberOfLines={2}>Pay R {Number(ridePayment(activeRide)?.amount ?? 0).toFixed(2)} to send your trip to drivers. You only pay the final fare.</Text>
              <View style={{ flexDirection: 'row', gap: 8, marginTop: 8 }}>
                <PaButton title="Pay now" onPress={() => setPayRide({ id: activeRide.id, amount: Number(ridePayment(activeRide)?.amount ?? 0), fare: null })} style={{ flex: 1 }} />
                <PaButton title="Cancel" variant="ghost" loading={cancellingUnpaid} onPress={cancelUnpaid} style={{ flex: 1 }} />
              </View>
            </View>
          </View>
        ) : activeRide ? (
          <Pressable style={styles.activeCard} onPress={() => navigation.navigate('RideTrack', { rideId: activeRide.id })}>
            <Ionicons name="navigate-circle-outline" size={26} color={pa.info} />
            <View style={{ flex: 1 }}>
              <Text style={styles.activeTitle}>You have a shuttle booked</Text>
              <Text style={styles.activeSub} numberOfLines={1}>{activeRide.dropoff_address ?? 'Tap to track your ride'}</Text>
            </View>
            <Text style={styles.activeLink}>Track</Text>
          </Pressable>
        ) : null}

        <View onLayout={(e) => { fieldY.current.pickup = e.nativeEvent.layout.y; }}>
          <Text style={styles.label}>Pick up</Text>
          <AddressAutocomplete value={pickup} onChange={changePickup} placeholder="Start typing your address" allowCurrentLocation onFocus={() => scrollToField('pickup')} />
        </View>

        <View onLayout={(e) => { fieldY.current.dropoff = e.nativeEvent.layout.y; }}>
          <Text style={styles.label}>Drop off</Text>
          <AddressAutocomplete value={dropoff} onChange={changeDropoff} placeholder="Clinic, pharmacy or lab address" onFocus={() => scrollToField('dropoff')} />
        </View>

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
  title: { fontFamily: paFonts.black, fontSize: 22, lineHeight: 28, color: pa.ink, marginHorizontal: 16, marginBottom: 10 },
  mapWrap: { marginHorizontal: 16 },
  sheet: { padding: 16, backgroundColor: pa.paper },
  activeCard: { flexDirection: 'row', alignItems: 'center', gap: 10, borderWidth: 1, borderColor: pa.info, backgroundColor: '#EAF1F8', padding: 12, marginBottom: 14 },
  activeTitle: { fontFamily: paFonts.bold, fontSize: 14, lineHeight: 18, color: pa.ink },
  activeSub: { fontFamily: paFonts.regular, fontSize: 12, lineHeight: 16, color: pa.muted, marginTop: 2 },
  activeLink: { fontFamily: paFonts.black, fontSize: 13, color: pa.info },
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
