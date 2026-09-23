import React, { useCallback, useMemo, useState } from 'react';
import { RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { api } from '../../api/client';
import { Ride } from '../../api/types';
import { PaDarkStat, PaLedgerRow, PaWeekBars } from '../../components/pa/driver-extras';
import { pa, type } from '../../theme';

const DAY_LABELS = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];

/**
 * Earnings summary — driver-earnings.html. There is no dedicated
 * `/driver/earnings` endpoint on the backend (confirmed against
 * routes/api.php and RideController), so this screen aggregates real
 * numbers client-side from `GET /driver/rides` (completed rides only).
 * Two honest limitations vs. the mockup, both flagged rather than faked:
 *   - `myRides()` paginates 15 per page with no date filter, so "this
 *     week" only reflects whatever rides are on the most recent page.
 *   - The API has no breakdown of priority premiums, waiting-time pay or
 *     platform fee — only `fare_final` per ride — so those ledger lines
 *     from the mockup are omitted rather than invented.
 */
export default function EarningsScreen() {
  const [rides, setRides] = useState<Ride[]>([]);
  const [loading, setLoading] = useState(false);

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

  const { total, tripCount, bars, average } = useMemo(() => {
    const completed = rides.filter((r) => r.status === 'completed' && r.completed_at);
    const now = new Date();
    const startOfWeek = new Date(now);
    startOfWeek.setDate(now.getDate() - now.getDay());
    startOfWeek.setHours(0, 0, 0, 0);

    const thisWeek = completed.filter((r) => new Date(r.completed_at!).getTime() >= startOfWeek.getTime());
    const sum = thisWeek.reduce((s, r) => s + (r.fare_final ?? 0), 0);

    const byDay = [0, 0, 0, 0, 0, 0, 0];
    thisWeek.forEach((r) => {
      const day = new Date(r.completed_at!).getDay();
      byDay[day] += r.fare_final ?? 0;
    });

    return {
      total: sum,
      tripCount: thisWeek.length,
      average: thisWeek.length ? sum / thisWeek.length : 0,
      bars: byDay.map((value, i) => ({ label: DAY_LABELS[i], value, today: i === now.getDay() })),
    };
  }, [rides]);

  return (
    <ScrollView
      style={styles.container}
      contentContainerStyle={{ padding: 16 }}
      refreshControl={<RefreshControl refreshing={loading} onRefresh={load} />}
    >
      <Text style={[type.h2, { marginBottom: 12 }]}>Earnings</Text>
      <PaDarkStat label="This week" value={`R ${total.toFixed(0)}`} sub={`${tripCount} trip${tripCount === 1 ? '' : 's'} completed`} />
      <View style={{ marginBottom: 16 }}>
        <PaWeekBars data={bars} />
      </View>
      <PaLedgerRow label="Fares (completed, this week)" value={`R ${total.toFixed(0)}`} />
      <PaLedgerRow label="Trips completed" value={String(tripCount)} />
      <PaLedgerRow label="Average fare" value={`R ${average.toFixed(0)}`} />
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper },
});
