import React, { useCallback, useState } from 'react';
import { Alert, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { api, apiErrorMessage } from '../../api/client';
import { RideSeries, VehicleType } from '../../api/types';
import { PaButton, PaEmptyState, PaInput } from '../../components/pa';
import AddressAutocomplete, { GeoPoint } from '../../components/AddressAutocomplete';
import { pa, paFonts } from '../../theme';

const DAYS = [
  { label: 'Sun', value: 0 }, { label: 'Mon', value: 1 }, { label: 'Tue', value: 2 },
  { label: 'Wed', value: 3 }, { label: 'Thu', value: 4 }, { label: 'Fri', value: 5 }, { label: 'Sat', value: 6 },
];

export default function RideSeriesScreen() {
  const [series, setSeries] = useState<RideSeries[]>([]);
  const [loading, setLoading] = useState(false);

  const [creating, setCreating] = useState(false);
  const [days, setDays] = useState<number[]>([5]);
  const [time, setTime] = useState('14:30');
  const [until, setUntil] = useState('');
  const [pickupAddress, setPickupAddress] = useState('');
  const [dropoffAddress, setDropoffAddress] = useState('');
  const [pickupCoords, setPickupCoords] = useState<GeoPoint | null>(null);
  const [dropoffCoords, setDropoffCoords] = useState<GeoPoint | null>(null);
  const [saving, setSaving] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const { data } = await api.get('/ride-series');
      setSeries(data.data);
    } finally {
      setLoading(false);
    }
  }, []);

  useFocusEffect(useCallback(() => { load(); }, [load]));

  function toggleDay(d: number) {
    setDays((prev) => (prev.includes(d) ? prev.filter((x) => x !== d) : [...prev, d]));
  }

  async function toggle(s: RideSeries) {
    try {
      await api.post(`/ride-series/${s.id}/${s.active ? 'pause' : 'resume'}`);
      load();
    } catch (e) {
      Alert.alert('Could not update series', apiErrorMessage(e));
    }
  }

  async function createSeries() {
    if (!days.length || !time || !until || !pickupAddress || !dropoffAddress) {
      Alert.alert('Missing info', 'Fill in the day(s), time, end date, pickup and drop-off.');
      return;
    }
    setSaving(true);
    try {
      // Use the coordinates from the picked suggestions; only geocode
      // (server-side, OpenStreetMap) a leg that was typed but never picked.
      let pickupPoint = pickupCoords;
      let dropoffPoint = dropoffCoords;
      if (!pickupPoint || !dropoffPoint) {
        const { data } = await api.post('/rides/quote-by-address', {
          pickup_address: pickupAddress,
          dropoff_address: dropoffAddress,
          ...(pickupPoint ? { pickup_lat: pickupPoint.lat, pickup_lng: pickupPoint.lng } : {}),
          ...(dropoffPoint ? { dropoff_lat: dropoffPoint.lat, dropoff_lng: dropoffPoint.lng } : {}),
        });
        pickupPoint = data.pickup as GeoPoint;
        dropoffPoint = data.dropoff as GeoPoint;
      }
      if (!pickupPoint || !dropoffPoint) throw new Error('unresolved');

      await api.post('/ride-series', {
        days,
        time,
        until,
        pickup_address: pickupAddress,
        pickup_lat: pickupPoint.lat,
        pickup_lng: pickupPoint.lng,
        dropoff_address: dropoffAddress,
        dropoff_lat: dropoffPoint.lat,
        dropoff_lng: dropoffPoint.lng,
        vehicle_type: 'sedan' as VehicleType,
      });
      setCreating(false);
      setPickupAddress('');
      setDropoffAddress('');
      setPickupCoords(null);
      setDropoffCoords(null);
      load();
      Alert.alert('Series saved', 'Your recurring trip has been scheduled.');
    } catch (e) {
      const errors = (e as any)?.response?.data?.errors;
      const msgs = errors ? Object.values(errors).flat().filter((m): m is string => typeof m === 'string') : [];
      Alert.alert('Could not save series', msgs.length ? msgs.join('\n') : apiErrorMessage(e));
    } finally {
      setSaving(false);
    }
  }

  return (
    <ScrollView style={styles.container} contentContainerStyle={{ padding: 16, paddingBottom: 40 }} keyboardShouldPersistTaps="handled">
      <Text style={styles.title}>Recurring trips</Text>

      {series.map((s) => (
        <View key={s.id} style={styles.card}>
          <Text style={styles.cardEyebrow}>SERIES-{s.id}</Text>
          <Text style={styles.cardTitle}>{s.pickup.address} → {s.dropoff.address}</Text>
          <Text style={styles.cardMeta}>
            {s.pattern.days.map((d) => DAYS[d]?.label).join(', ')} {s.pattern.time} · until {s.pattern.until}
          </Text>
          <PaButton title={s.active ? 'Pause' : 'Resume'} variant="ghost" onPress={() => toggle(s)} style={{ marginTop: 10 }} />
        </View>
      ))}

      {series.length === 0 && !loading && !creating ? <PaEmptyState message="No recurring trips yet." /> : null}

      {creating ? (
        <View style={styles.card}>
          <Text style={styles.label}>Repeat on</Text>
          <View style={styles.chipRow}>
            {DAYS.map((d) => (
              <Pressable key={d.value} onPress={() => toggleDay(d.value)} style={[styles.chip, days.includes(d.value) && styles.chipActive]}>
                <Text style={[styles.chipText, days.includes(d.value) && styles.chipTextActive]}>{d.label}</Text>
              </Pressable>
            ))}
          </View>
          <PaInput label="Pick-up time" value={time} onChangeText={setTime} placeholder="14:30" />
          <PaInput label="Until" value={until} onChangeText={setUntil} placeholder="2026-11-14" />
          <AddressAutocomplete
            label="Pick-up address"
            value={pickupAddress}
            onChange={(address, coords) => { setPickupAddress(address); setPickupCoords(coords); }}
            placeholder="Start typing your address"
            allowCurrentLocation
          />
          <AddressAutocomplete
            label="Drop-off address"
            value={dropoffAddress}
            onChange={(address, coords) => { setDropoffAddress(address); setDropoffCoords(coords); }}
            placeholder="Clinic, pharmacy or lab address"
          />
          <PaButton title="Save series" onPress={createSeries} loading={saving} />
        </View>
      ) : (
        <PaButton title="+ New recurring trip" variant="ghost" onPress={() => setCreating(true)} />
      )}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper },
  title: { fontFamily: paFonts.black, fontSize: 22, color: pa.ink, marginBottom: 14 },
  card: { backgroundColor: pa.surface, borderWidth: 1, borderColor: pa.line, padding: 14, marginBottom: 12 },
  cardEyebrow: { fontFamily: paFonts.black, fontSize: 11, letterSpacing: 1.2, color: pa.signal, marginBottom: 6 },
  cardTitle: { fontFamily: paFonts.bold, fontSize: 15, color: pa.ink },
  cardMeta: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted, marginTop: 3 },
  label: { fontFamily: paFonts.black, fontSize: 11, letterSpacing: 1.2, textTransform: 'uppercase', color: pa.muted, marginBottom: 8 },
  chipRow: { flexDirection: 'row', gap: 6, marginBottom: 14 },
  chip: { flex: 1, borderWidth: 1, borderColor: pa.line, paddingVertical: 8, alignItems: 'center', backgroundColor: pa.surface },
  chipActive: { backgroundColor: pa.ink, borderColor: pa.ink },
  chipText: { fontFamily: paFonts.bold, fontSize: 12, color: pa.ink },
  chipTextActive: { color: '#fff' },
});
