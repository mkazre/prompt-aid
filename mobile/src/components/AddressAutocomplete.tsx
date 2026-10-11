import React, { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, StyleSheet, Text, View } from 'react-native';
import * as Location from 'expo-location';
import { api } from '../api/client';
import { PaInput } from './pa';
import { pa, paFonts } from '../theme';

export type GeoPoint = { lat: number; lng: number };
export type AddressSuggestion = { label: string; full?: string; lat: number; lng: number };

/** Server-side OpenStreetMap search (GET /geocode/autocomplete). Returns [] on any failure. */
export async function searchAddresses(q: string): Promise<AddressSuggestion[]> {
  if (q.trim().length < 3) return [];
  try {
    const { data } = await api.get('/geocode/autocomplete', { params: { q: q.trim() } });
    return Array.isArray(data) ? data : [];
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
};

/**
 * Address field with debounced typeahead suggestions from /api/geocode.
 * Parent ScrollViews should set keyboardShouldPersistTaps="handled" so a
 * suggestion tap registers while the keyboard is open.
 */
export default function AddressAutocomplete({ label, placeholder, value, onChange, allowCurrentLocation }: Props) {
  const [suggestions, setSuggestions] = useState<AddressSuggestion[]>([]);
  const [open, setOpen] = useState(false);
  const [searching, setSearching] = useState(false);
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
    timer.current = setTimeout(async () => {
      const mine = ++seq.current;
      const results = await searchAddresses(q);
      if (mine !== seq.current) return; // a newer keystroke superseded this lookup
      setSuggestions(results);
      setSearching(false);
    }, 300);
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
      const pos = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.High });
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
        onFocus={() => { if (suggestions.length) setOpen(true); }}
        style={open ? styles.inputOpen : undefined}
      />

      {open ? (
        <View style={styles.list}>
          {searching ? (
            <View style={styles.status}>
              <ActivityIndicator size="small" color={pa.muted} />
              <Text style={styles.statusText}>Searching…</Text>
            </View>
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
                <Text style={styles.itemMain} numberOfLines={1}>{s.label}</Text>
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
  itemMain: { fontFamily: paFonts.bold, fontSize: 14, color: pa.ink },
  itemSub: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted, marginTop: 2 },
  status: { flexDirection: 'row', alignItems: 'center', gap: 8, paddingHorizontal: 13, paddingVertical: 10 },
  statusText: { fontFamily: paFonts.regular, fontSize: 13, color: pa.muted, flexShrink: 1 },
  locate: { marginTop: -6, marginBottom: 12, alignSelf: 'flex-start' },
  locateText: { fontFamily: paFonts.bold, fontSize: 13, color: pa.signal },
});
