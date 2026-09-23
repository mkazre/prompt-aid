import React, { useCallback, useState } from 'react';
import { FlatList, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { api } from '../../api/client';
import { Ride } from '../../api/types';
import { PaEmptyState, headline } from '../../components/pa';
import { timeAgo } from '../../components/pa/driver-extras';
import { pa, paFonts, paRadius, type } from '../../theme';

/**
 * Unassigned trip requests this driver can accept — the real-data
 * equivalent of driver-offer.html's push-offer flow. The backend has no
 * per-offer push/expiry mechanism (only a plain `/driver/rides/available`
 * list + `/driver/rides/{ride}/accept`), so instead of faking a
 * server-authoritative countdown this screen lists everything currently
 * available and lets the driver open one to accept or pass.
 */
export default function OffersScreen({ navigation }: any) {
  const [rides, setRides] = useState<Ride[]>([]);
  const [loading, setLoading] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const { data } = await api.get('/driver/rides/available');
      setRides(data.data as Ride[]);
    } finally {
      setLoading(false);
    }
  }, []);

  useFocusEffect(useCallback(() => { load(); }, [load]));

  return (
    <View style={styles.container}>
      <View style={styles.header}>
        <Text style={type.h2}>Offers</Text>
      </View>
      <FlatList
        data={rides}
        keyExtractor={(r) => String(r.id)}
        contentContainerStyle={{ padding: 16 }}
        refreshControl={<RefreshControl refreshing={loading} onRefresh={load} />}
        ListEmptyComponent={!loading ? <PaEmptyState message="No trip requests waiting right now." /> : null}
        renderItem={({ item }) => (
          <Pressable style={styles.card} onPress={() => navigation.navigate('OfferDetail', { rideId: item.id })}>
            {item.priority === 'emergency' && (
              <View style={styles.eyebrowRow}>
                <View style={styles.eyebrowDot} />
                <Text style={styles.eyebrow}>Emergency priority</Text>
              </View>
            )}
            <View style={styles.spread}>
              <Text style={styles.ref}>{item.ride_ref}</Text>
              <Text style={styles.fare}>R {item.fare_estimate?.toFixed(0) ?? '—'}</Text>
            </View>
            <Text style={styles.addr}>📍 {item.pickup_address}</Text>
            <Text style={styles.addr}>🏥 {item.dropoff_address}</Text>
            <Text style={styles.meta}>{headline(item.vehicle_type)} · requested {timeAgo(item.requested_at)}</Text>
          </Pressable>
        )}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper },
  header: { paddingHorizontal: 16, paddingTop: 16 },
  card: { backgroundColor: pa.surface, borderWidth: 1, borderColor: pa.line, borderRadius: paRadius.sm, padding: 14, marginBottom: 10 },
  eyebrowRow: { flexDirection: 'row', alignItems: 'center', gap: 8, marginBottom: 8 },
  eyebrowDot: { width: 6, height: 6, backgroundColor: pa.sats.orange },
  eyebrow: { fontFamily: paFonts.black, fontSize: 11, letterSpacing: 1.4, textTransform: 'uppercase', color: pa.sats.orange },
  spread: { flexDirection: 'row', justifyContent: 'space-between', marginBottom: 6 },
  ref: { fontFamily: paFonts.bold, fontSize: 15, color: pa.ink },
  fare: { ...type.num, fontSize: 15, color: pa.ink },
  addr: { fontFamily: paFonts.regular, fontSize: 13, color: pa.inkSoft, marginTop: 2 },
  meta: { ...type.small, marginTop: 6 },
});
