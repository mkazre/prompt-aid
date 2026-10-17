import React, { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, StyleSheet, Text, View } from 'react-native';
import * as Location from 'expo-location';
import { api } from '../api/client';
import { PaInput } from './pa';
import { pa, paFonts } from '../theme';

export type GeoPoint = { lat: number; lng: number };
export type AddressSuggestion = { label: string; full?: string; lat: number; lng: number };

// Session cache: the server lookup takes ~2s (Nominatim, 1 req/s), so
// re-typing or deleting back to an earlier query should be instant.
const cache = new Map<string, AddressSuggestion[]>();

function cleanResults(data: unknown): AddressSuggestion[] {
  if (!Array.isArray(data)) return [];
  return data
    .map((s: any) => ({ label: String(s?.label ?? ''), full: s?.full ?? undefined, lat: Number(s?.lat), lng: Number(s?.lng) }))
    .filter((s) => s.label && Number.isFinite(s.lat) && Number.isFinite(s.lng));
}

/**
 * Server-side OpenStreetMap search (GET /geocode/autocomplete →
 * [{label, full, lat, lng}]). Throws on network/server errors so the field
 * can tell "no results" apart from "couldn't search".
 */
export async function searchAddressesStrict(q: string): Promise<AddressSuggestion[]> {
  const key = q.trim().toLowerCase();
  if (key.length < 3) return [];
  const hit = cache.get(key);
  if (hit) return hit;
  const { data } = await api.get('/geocode/autocomplete', { params: { q: q.trim() }, timeout: 12000 });
  const results = cleanResults(data);
  cache.set(key, results);
  return results;
}

/** Same as searchAddressesStrict but returns [] on any failure. */
export async function searchAddresses(q: string): Promise<AddressSuggestion[]> {
  try {
    return await searchAddressesStrict(q);
  } catch {
    return [];
  }
}

/** Coordinates -> readable address (GET /geocode/reverse). Null when unknown/unreachable. */
export async function reverseGeocode(point: GeoPoint): Promise<string | null> {
  try {
    const { data } = await api.get('/geocode/reverse', { params: { lat: point.lat, lng: point.lng } });
    return data?.label ?? null;
  } catch {
    return null;
  }
}

type Props = {
  label?: string;
  placeholder?: string;
  value: string;
  /** Called with the new text; coords is null while the user is typing, set once a suggestion/GPS is chosen. */
  onChange: (address: string, coords: GeoPoint | null) => void;
  /** Show a "Use my current location" action that fills a real, reverse-geocoded address. */
  allowCurrentLocation?: boolean;
  /** Lets the parent scroll the field to the top so suggestions sit above the keyboard. */
  onFocus?: () => void;
};

/**
 * Address field with debounced typeahead suggestions from /api/geocode.
 * Parent ScrollViews should set keyboardShouldPersistTaps="handled" so a
 * suggestion tap registers while the keyboard is open.
 */
export default function AddressAutocomplete({ label, placeholder, value, onChange, allowCurrentLocation, onFocus }: Props) {
  const [suggestions, setSuggestions] = useState<AddressSuggestion[]>([]);
  const [open, setOpen] = useState(false);
  const [searching, setSearching] = useState(false);
  const [failed, setFailed] = useState(false);
  const [locating, setLocating] = useState(false);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const seq = useRef(0);

  useEffect(() => () => {
    if (timer.current) clearTimeout(timer.current);
  }, []);

  function handleText(text: string) {
    onChange(text, null); // typed text invalidates any previously chosen coordinates
    if (timer.current) clearTimeout(timer.current);
    const q = text.trim();
    if (q.length < 3) {
      seq.current++;
      setSuggestions([]);
      setOpen(false);
      setSearching(false);
      return;
    }
    setOpen(true);
    setSearching(true);
    setFailed(false);
    timer.current = setTimeout(() => runSearch(q), 300);
  }

  async function runSearch(q: string) {
    const mine = ++seq.current;
    setSearching(true);
    setFailed(false);
    try {
      const results = await searchAddressesStrict(q);
      if (mine !== seq.current) return; // a newer keystroke superseded this lookup
      setSuggestions(results);
    } catch {
      if (mine !== seq.current) return;
      setSuggestions([]);
      setFailed(true);
    } finally {
      if (mine === seq.current) setSearching(false);
    }
  }

  function choose(s: AddressSuggestion) {
    seq.current++;
    if (timer.current) clearTimeout(timer.current);
    setSuggestions([]);
    setOpen(false);
    setSearching(false);
    onChange(s.label, { lat: s.lat, lng: s.lng });
  }

  async function useCurrentLocation() {
    setLocating(true);
    try {
      const perm = await Location.requestForegroundPermissionsAsync();
      if (perm.status !== 'granted') {
        Alert.alert('Location permission needed', 'Enable location access to fill in your address automatically.');
        return;
      }
      // A fresh fix can take a while indoors — fall back to the last known one.
      const pos =
        (await Promise.race([
          Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Balanced }),
          new Promise<null>((r) => setTimeout(() => r(null), 10000)),
        ])) ?? (await Location.getLastKnownPositionAsync());
      if (!pos) throw new Error('no fix');
      const point = { lat: pos.coords.latitude, lng: pos.coords.longitude };
      const address = await reverseGeocode(point);
      seq.current++;
      setSuggestions([]);
      setOpen(false);
      onChange(address ?? 'My current location', point);
    } catch {
      Alert.alert('Location unavailable', 'We could not get your location — please type your address.');
    } finally {
      setLocating(false);
    }
  }

  return (
    <View style={styles.wrap}>
      <PaInput
        label={label}
        value={value}
        onChangeText={handleText}
        placeholder={placeholder ?? 'Start typing an address'}
        autoCorrect={false}
        autoComplete="off"
        onBlur={() => setTimeout(() => setOpen(false), 200)}
        onFocus={() => { onFocus?.(); if (suggestions.length) setOpen(true); }}
        returnKeyType="search"
        style={open ? styles.inputOpen : undefined}
      />

      {open ? (
        <View style={styles.list}>
          {searching ? (
            <View style={styles.status}>
              <ActivityIndicator size="small" color={pa.muted} />
              <Text style={styles.statusText}>Searching…</Text>
            </View>
          ) : failed ? (
            <Pressable style={styles.status} onPress={() => runSearch(value)}>
              <Text style={styles.statusText}>Couldn't reach address search. Tap to try again.</Text>
            </Pressable>
          ) : suggestions.length === 0 ? (
            <View style={styles.status}>
              <Text style={styles.statusText}>No matching addresses — try adding a suburb or city.</Text>
            </View>
          ) : (
            suggestions.map((s, i) => (
              <Pressable
                key={`${s.lat},${s.lng},${i}`}
                onPress={() => choose(s)}
                style={({ pressed }) => [styles.item, i === suggestions.length - 1 && styles.itemLast, pressed && styles.itemPressed]}
              >
                <Text style={styles.itemMain} numberOfLines={2}>{s.label}</Text>
                {s.full && s.full !== s.label ? <Text style={styles.itemSub} numberOfLines={1}>{s.full}</Text> : null}
              </Pressable>
            ))
          )}
        </View>
      ) : null}

      {allowCurrentLocation ? (
        <Pressable onPress={useCurrentLocation} disabled={locating} style={styles.locate} hitSlop={8}>
          <Text style={[styles.locateText, locating && { color: pa.muted }]}>
            {locating ? 'Finding you…' : '📍 Use my current location'}
          </Text>
        </Pressable>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: { marginBottom: 6 },
  inputOpen: { borderColor: pa.ink },
  list: { marginTop: -12, marginBottom: 12, borderWidth: 1, borderColor: pa.ink, backgroundColor: pa.surface },
  item: { paddingHorizontal: 13, paddingVertical: 10, borderBottomWidth: 1, borderBottomColor: pa.line },
  itemLast: { borderBottomWidth: 0 },
  itemPressed: { backgroundColor: pa.beaconWash },
  itemMain: { fontFamily: paFonts.bold, fontSize: 14, lineHeight: 19, color: pa.ink },
  itemSub: { fontFamily: paFonts.regular, fontSize: 12, lineHeight: 16, color: pa.muted, marginTop: 2 },
  status: { flexDirection: 'row', alignItems: 'center', gap: 8, paddingHorizontal: 13, paddingVertical: 10 },
  statusText: { fontFamily: paFonts.regular, fontSize: 13, color: pa.muted, flexShrink: 1 },
  locate: { marginTop: -6, marginBottom: 12, alignSelf: 'flex-start' },
  locateText: { fontFamily: paFonts.bold, fontSize: 13, color: pa.signal },
});
