import React, { useCallback, useRef, useState } from 'react';
import { Alert, Linking, Pressable, ScrollView, Share, StyleSheet, Text, TextInput, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import * as Location from 'expo-location';
import { api, apiErrorMessage } from '../../api/client';
import { PaAvatar, PaButton } from '../../components/pa';
import RideMap from '../../components/RideMap';
import { pa, paFonts } from '../../theme';

type Point = [number, number];

/** Shape of RideStatusPresenter::forPatient() — GET /rides/{id}/live. */
interface LiveRide {
  id: number;
  ride_ref: string;
  status: string;
  status_label: string;
  is_active: boolean;
  vehicle_type: string;
  pickup: { lat: number; lng: number; address: string | null };
  dropoff: { lat: number; lng: number; address: string | null };
  driver: {
    name: string;
    initials?: string;
    photo_url?: string | null;
    phone?: string | null;
    vehicle?: string | null;
    plate?: string | null;
    rating?: number | null;
    lat?: number | null;
    lng?: number | null;
    heading?: number | null;
    location_age_s?: number | null;
  } | null;
  eta: { minutes: number; distance_km: number; target: 'pickup' | 'dropoff' } | null;
  route: { key: string | null; points: Point[] | null };
  trip_route: { key: string; points: Point[] | null };
  fare?: { quoted: number | null; final: number | null };
  trip_pin?: string | null;
  share_url?: string | null;
  rating?: { rating: number; comment: string | null } | false | null;
  cancellation?: { reason: string | null; fee: number } | null;
  poll_ms: number;
}

const STATUS_COLOURS: Record<string, string> = {
  requested: '#E4701E',
  accepted: '#1F5FBF',
  driver_enroute: '#1F5FBF',
  arrived: '#6A4C93',
  in_progress: '#1F7A4C',
  completed: '#1F7A4C',
  cancelled: '#C8102E',
};

/**
 * Live shuttle tracking: moving car (facing its direction of travel), road
 * route, ETA, trip PIN, call/share/SOS, cancel with the exact fee shown
 * first, and rating once the trip is complete.
 */
export default function RideTrackScreen({ route: navRoute, navigation }: any) {
  const { rideId } = navRoute.params;
  const [ride, setRide] = useState<LiveRide | null>(null);
  const [activeRoute, setActiveRoute] = useState<Point[] | null>(null);
  const [tripRoute, setTripRoute] = useState<Point[] | null>(null);
  const [stars, setStars] = useState(0);
  const [comment, setComment] = useState('');
  const [busy, setBusy] = useState(false);
  const keys = useRef<{ rk: string | null; tk: string | null }>({ rk: null, tk: null });
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

  const poll = useCallback(async () => {
    try {
      const { data } = await api.get(`/rides/${rideId}/live`, { params: { rk: keys.current.rk ?? undefined, tk: keys.current.tk ?? undefined } });
      const live = data as LiveRide;
      // Route geometry is only sent when it changed (keyed), so keep the last one.
      if (live.route?.points) setActiveRoute(live.route.points);
      if (!live.route?.key) setActiveRoute(null);
      if (live.trip_route?.points) setTripRoute(live.trip_route.points);
      keys.current = { rk: live.route?.key ?? null, tk: live.trip_route?.key ?? null };
      setRide(live);
      if (live.poll_ms > 0) {
        timer.current = setTimeout(poll, Math.max(3000, live.poll_ms));
      }
    } catch {
      timer.current = setTimeout(poll, 8000);
    }
  }, [rideId]);

  useFocusEffect(useCallback(() => {
    poll();
    return () => {
      if (timer.current) clearTimeout(timer.current);
    };
  }, [poll]));

  async function cancel() {
    try {
      const { data: preview } = await api.get(`/rides/${rideId}/cancel-preview`);
      if (!preview.allowed) {
        Alert.alert('Cannot cancel', preview.reason ?? 'This trip can no longer be cancelled.');
        return;
      }
      const fee = Number(preview.fee ?? 0);
      const reasons: string[] = Array.isArray(preview.reasons) && preview.reasons.length ? preview.reasons : ['Plans changed'];
      const doCancel = async (reason: string) => {
        setBusy(true);
        try {
          await api.post(`/rides/${rideId}/cancel`, { reason, expected_fee: fee });
          if (timer.current) clearTimeout(timer.current);
          poll();
        } catch (e) {
          Alert.alert('Could not cancel', apiErrorMessage(e));
        } finally {
          setBusy(false);
        }
      };
      Alert.alert(
        fee > 0 ? `Cancel for R ${fee.toFixed(2)}?` : 'Cancel this trip for free?',
        `${preview.reason ?? ''}\n\nWhy are you cancelling?`,
        [...reasons.slice(0, 3).map((r) => ({ text: r, onPress: () => doCancel(r) })), { text: 'Keep my trip', style: 'cancel' as const }],
      );
    } catch (e) {
      Alert.alert('Could not cancel', apiErrorMessage(e));
    }
  }

  async function sos() {
    Alert.alert('Send an SOS?', 'Our support team will be alerted immediately and can see your trip.', [
      { text: 'Not now', style: 'cancel' },
      {
        text: 'Send SOS',
        style: 'destructive',
        onPress: async () => {
          let coords: { lat?: number; lng?: number } = {};
          try {
            const perm = await Location.getForegroundPermissionsAsync();
            if (perm.granted) {
              const pos = await Location.getCurrentPositionAsync({});
              coords = { lat: pos.coords.latitude, lng: pos.coords.longitude };
            }
          } catch {
            /* send without location */
          }
          try {
            const { data } = await api.post(`/rides/${rideId}/sos`, coords);
            const primary = (data.contacts ?? []).find((c: any) => c.is_primary) ?? data.contacts?.[0];
            Alert.alert('Help is on the way', data.message, primary ? [{ text: `Call ${primary.phone}`, onPress: () => Linking.openURL(primary.tel_url) }, { text: 'OK' }] : undefined);
          } catch (e) {
            Alert.alert('Could not send SOS', apiErrorMessage(e));
          }
        },
      },
    ]);
  }

  async function rate() {
    if (!stars) return;
    setBusy(true);
    try {
      await api.post(`/rides/${rideId}/review`, { rating: stars, comment: comment || undefined });
      poll();
    } catch (e) {
      Alert.alert('Could not save rating', apiErrorMessage(e));
    } finally {
      setBusy(false);
    }
  }

  if (!ride) return <View style={styles.container} />;

  const d = ride.driver;
  const colour = STATUS_COLOURS[ride.status] ?? pa.ink;
  const canCancel = ['requested', 'accepted', 'driver_enroute', 'arrived'].includes(ride.status);
  const fare = ride.fare?.final ?? ride.fare?.quoted ?? null;

  return (
    <ScrollView style={styles.container} contentContainerStyle={{ paddingBottom: 40 }}>
      <RideMap
        pickup={{ lat: ride.pickup.lat, lng: ride.pickup.lng }}
        dropoff={{ lat: ride.dropoff.lat, lng: ride.dropoff.lng }}
        driver={d?.lat != null && d?.lng != null ? { lat: d.lat, lng: d.lng, heading: d.heading ?? null } : null}
        route={activeRoute}
        tripRoute={tripRoute}
        height={300}
      />

      <View style={[styles.statusBanner, { backgroundColor: colour }]}>
        <Text style={styles.statusText}>{ride.status_label}</Text>
        {ride.eta ? (
          <Text style={styles.statusSub}>
            {ride.eta.minutes} min to {ride.eta.target === 'pickup' ? 'your pickup' : 'drop-off'} · {ride.eta.distance_km.toFixed(1)} km
          </Text>
        ) : ride.status === 'requested' ? (
          <Text style={styles.statusSub}>Finding the nearest available driver…</Text>
        ) : null}
        {d && d.lat == null && ride.is_active ? <Text style={styles.statusSub}>Waiting for the driver's location…</Text> : null}
      </View>

      <View style={styles.sheet}>
        {ride.trip_pin ? (
          <View style={styles.pinBox}>
            <Text style={styles.pinLabel}>Your trip PIN — tell the driver</Text>
            <Text style={styles.pin}>{ride.trip_pin}</Text>
          </View>
        ) : null}

        <View style={styles.driverRow}>
          <PaAvatar name={d?.name ?? '?'} size={50} />
          <View style={{ flex: 1 }}>
            <Text style={styles.driverName}>{d?.name ?? 'Matching you with a driver…'}</Text>
            {d ? <Text style={styles.driverMeta}>{[d.vehicle, d.plate].filter(Boolean).join(' · ')}</Text> : null}
          </View>
          {d?.rating ? <Text style={styles.rating}>★ {Number(d.rating).toFixed(1)}</Text> : null}
        </View>

        <View style={styles.actions}>
          {d?.phone ? <Pressable style={styles.action} onPress={() => Linking.openURL(`tel:${d.phone}`)}><Text style={styles.actionText}>📞 Call</Text></Pressable> : null}
          {ride.share_url ? <Pressable style={styles.action} onPress={() => Share.share({ message: `Follow my Prompt Aid trip: ${ride.share_url}` })}><Text style={styles.actionText}>🔗 Share trip</Text></Pressable> : null}
          {ride.is_active ? <Pressable style={[styles.action, styles.sos]} onPress={sos}><Text style={[styles.actionText, { color: '#fff' }]}>SOS</Text></Pressable> : null}
        </View>

        <View style={styles.tripBox}>
          <Text style={styles.tripLine}>From: {ride.pickup.address ?? '—'}</Text>
          <Text style={styles.tripLine}>To: {ride.dropoff.address ?? '—'}</Text>
          <View style={styles.tripFoot}>
            <Text style={styles.tripRef}>{ride.ride_ref} · {ride.vehicle_type}</Text>
            {fare != null ? <Text style={styles.fare}>R {fare.toFixed(2)}</Text> : null}
          </View>
        </View>

        {ride.cancellation ? (
          <View style={styles.notice}>
            <Text style={styles.noticeText}>Cancelled{ride.cancellation.reason ? ` — ${ride.cancellation.reason}` : ''}{ride.cancellation.fee > 0 ? ` · fee R ${ride.cancellation.fee.toFixed(2)}` : ''}</Text>
          </View>
        ) : null}

        {canCancel ? <PaButton title="Cancel trip" variant="ghost" onPress={cancel} loading={busy} style={{ marginTop: 12 }} /> : null}

        {ride.status === 'completed' && ride.rating === false ? (
          <View style={styles.rateBox}>
            <Text style={styles.rateTitle}>How was your trip?</Text>
            <View style={{ flexDirection: 'row', gap: 6, marginBottom: 10 }}>
              {[1, 2, 3, 4, 5].map((n) => (
                <Pressable key={n} onPress={() => setStars(n)}><Text style={{ fontSize: 30, color: n <= stars ? pa.beacon : pa.line }}>★</Text></Pressable>
              ))}
            </View>
            <TextInput value={comment} onChangeText={setComment} placeholder="Anything to add? (optional)" style={styles.input} multiline />
            <PaButton title="Submit rating" onPress={rate} loading={busy} disabled={!stars} />
          </View>
        ) : null}

        {ride.status === 'completed' ? (
          <PaButton title="Book another shuttle" variant="ghost" onPress={() => navigation.navigate('ShuttleRequest')} style={{ marginTop: 12 }} />
        ) : null}
      </View>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper },
  statusBanner: { marginHorizontal: 16, marginTop: -24, borderRadius: 14, padding: 14 },
  statusText: { fontFamily: paFonts.black, fontSize: 17, color: '#fff' },
  statusSub: { fontFamily: paFonts.regular, fontSize: 13, color: 'rgba(255,255,255,0.9)', marginTop: 3 },
  sheet: { padding: 16 },
  pinBox: { backgroundColor: pa.beaconWash, borderWidth: 1, borderColor: pa.beaconLine, borderRadius: 12, padding: 12, alignItems: 'center', marginBottom: 14 },
  pinLabel: { fontFamily: paFonts.bold, fontSize: 12, color: pa.inkSoft },
  pin: { fontFamily: paFonts.black, fontSize: 30, letterSpacing: 8, color: pa.ink, marginTop: 4 },
  driverRow: { flexDirection: 'row', gap: 12, alignItems: 'center', marginBottom: 12 },
  driverName: { fontFamily: paFonts.bold, fontSize: 16, color: pa.ink },
  driverMeta: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted, marginTop: 2 },
  rating: { fontFamily: paFonts.black, fontSize: 15, color: pa.ink },
  actions: { flexDirection: 'row', gap: 8, marginBottom: 14, flexWrap: 'wrap' },
  action: { borderWidth: 1, borderColor: pa.line, backgroundColor: pa.surface, borderRadius: 999, paddingHorizontal: 14, paddingVertical: 9 },
  actionText: { fontFamily: paFonts.bold, fontSize: 13, color: pa.ink },
  sos: { backgroundColor: pa.sats.red, borderColor: pa.sats.red },
  tripBox: { backgroundColor: pa.surface, borderWidth: 1, borderColor: pa.line, borderRadius: 12, padding: 12 },
  tripLine: { fontFamily: paFonts.regular, fontSize: 13, color: pa.inkSoft, marginBottom: 4 },
  tripFoot: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginTop: 6, borderTopWidth: 1, borderTopColor: pa.lineSoft, paddingTop: 8 },
  tripRef: { fontFamily: paFonts.bold, fontSize: 12, color: pa.muted },
  fare: { fontFamily: paFonts.black, fontSize: 20, color: pa.ink },
  notice: { backgroundColor: '#FBEAEA', borderRadius: 10, padding: 10, marginTop: 12 },
  noticeText: { fontFamily: paFonts.bold, fontSize: 13, color: pa.sats.red },
  rateBox: { backgroundColor: pa.surface, borderWidth: 1, borderColor: pa.line, borderRadius: 12, padding: 14, marginTop: 14 },
  rateTitle: { fontFamily: paFonts.black, fontSize: 16, color: pa.ink, marginBottom: 8 },
  input: { borderWidth: 1, borderColor: pa.line, borderRadius: 10, padding: 10, minHeight: 60, marginBottom: 10, fontFamily: paFonts.regular, color: pa.ink },
});
