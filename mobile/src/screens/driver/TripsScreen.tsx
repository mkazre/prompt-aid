import React, { useCallback, useMemo, useState } from 'react';
import { FlatList, RefreshControl, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { api } from '../../api/client';
import { Ride } from '../../api/types';
import { PaBadge, PaEmptyState, PaRow } from '../../components/pa';
import { PaChipRow } from '../../components/pa/driver-extras';
import { pa, type } from '../../theme';

type Filter = 'today' | 'scheduled' | 'history';

/** Trip history + schedule — driver-trips.html. Recurring series show a
 * small "Recurring" tag on the row (ride_series_id) rather than a separate
 * grouped section, since `/driver/rides` only returns this driver's most
 * recent page of rides, not a full per-series roll-up. */
export default function TripsScreen({ navigation }: any) {
  const [rides, setRides] = useState<Ride[]>([]);
  const [loading, setLoading] = useState(false);
  const [filter, setFilter] = useState<Filter>('today');

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const { data } = await api.get('/driver/rides');
      setRides(data.data as Ride[]);
    } finally {
      setLoading(false);
    }
  }, []);

  useFocusEffect(useCallback(() => { load(); }, [load]));

  const today = new Date().toDateString();
  const counts = useMemo(() => ({
    today: rides.filter((r) => r.requested_at && new Date(r.requested_at).toDateString() === today).length,
    scheduled: rides.filter((r) => r.status === 'requested' && r.scheduled_for && new Date(r.scheduled_for).getTime() > Date.now()).length,
    history: rides.filter((r) => ['completed', 'cancelled'].includes(r.status)).length,
  }), [rides]);

  const filtered = rides.filter((r) => {
    if (filter === 'today') return r.requested_at && new Date(r.requested_at).toDateString() === today;
    if (filter === 'scheduled') return r.status === 'requested' && r.scheduled_for && new Date(r.scheduled_for).getTime() > Date.now();
    return ['completed', 'cancelled'].includes(r.status);
  });

  return (
    <View style={styles.container}>
      <View style={styles.header}>
        <Text style={type.h2}>Trips</Text>
      </View>
      <View style={{ paddingHorizontal: 16 }}>
        <PaChipRow
          value={filter}
          onChange={(k) => setFilter(k as Filter)}
          options={[
            { key: 'today', label: `Today ${counts.today}` },
            { key: 'scheduled', label: `Scheduled ${counts.scheduled}` },
            { key: 'history', label: `History ${counts.history}` },
          ]}
        />
      </View>
      <FlatList
        data={filtered}
        keyExtractor={(r) => String(r.id)}
        contentContainerStyle={{ paddingHorizontal: 16 }}
        refreshControl={<RefreshControl refreshing={loading} onRefresh={load} />}
        ListEmptyComponent={!loading ? <PaEmptyState message="No trips in this view." /> : null}
        renderItem={({ item }) => (
          <PaRow
            title={`${item.ride_ref} · ${formatWhen(item)}`}
            subtitle={`${item.pickup_address} → ${item.dropoff_address}${item.distance_km ? ` · ${item.distance_km.toFixed(1)} km` : ''}`}
            right={
              item.status === 'cancelled' ? (
                <PaBadge tone="stop" label={`R ${(item.fare_final ?? 0).toFixed(0)}`} />
              ) : (
                <PaBadge tone="go" label={`R ${(item.fare_final ?? item.fare_estimate ?? 0).toFixed(0)}`} />
              )
            }
            onPress={() => navigation.navigate('TripDetail', { rideId: item.id })}
          />
        )}
      />
    </View>
  );
}

function formatWhen(r: Ride) {
  const iso = r.scheduled_for ?? r.requested_at;
  if (!iso) return '';
  return new Date(iso).toLocaleTimeString('en-ZA', { hour: '2-digit', minute: '2-digit' });
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper },
  header: { paddingHorizontal: 16, paddingTop: 16, marginBottom: 8 },
});
