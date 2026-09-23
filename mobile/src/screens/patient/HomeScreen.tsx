import React, { useCallback, useMemo, useState } from 'react';
import { RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { api } from '../../api/client';
import { Appointment, Doctor } from '../../api/types';
import { Avatar, Badge, Card, EmptyState, Input, SectionTitle } from '../../components/UI';
import { colors, font, radius, spacing } from '../../theme';
import { useAuth } from '../../context/AuthContext';

const SPECIALTY_ICONS: Record<string, string> = {
  'General Practitioner': '🩺',
  Pediatrician: '👶',
  Dermatologist: '🧴',
  Cardiologist: '❤️',
  Gynaecologist: '🌸',
  'ENT Specialist': '👂',
};

function iconFor(specialization: string) {
  return SPECIALTY_ICONS[specialization] ?? '💉';
}

export default function HomeScreen({ navigation }: any) {
  const { user } = useAuth();
  const [doctors, setDoctors] = useState<Doctor[]>([]);
  const [upcoming, setUpcoming] = useState<Appointment[]>([]);
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(false);

  const load = useCallback(async (q = '') => {
    setLoading(true);
    try {
      const [doctorsRes, apptRes] = await Promise.all([
        api.get('/doctors', { params: q ? { search: q } : {} }),
        api.get('/appointments'),
      ]);
      setDoctors(doctorsRes.data.data);
      const upcomingOnly = (apptRes.data.data as Appointment[])
        .filter((a) => !['completed', 'cancelled', 'no_show'].includes(a.status) && a.date >= new Date().toISOString().slice(0, 10))
        .sort((a, b) => a.date.localeCompare(b.date));
      setUpcoming(upcomingOnly);
    } finally {
      setLoading(false);
    }
  }, []);

  useFocusEffect(
    useCallback(() => {
      load(search);
    }, [load])
  );

  const services = useMemo(() => {
    const seen = new Map<string, number>();
    doctors.forEach((d) => seen.set(d.specialization, (seen.get(d.specialization) ?? 0) + 1));
    return Array.from(seen.keys()).slice(0, 6);
  }, [doctors]);

  const nextAppt = upcoming[0];

  return (
    <ScrollView
      style={styles.container}
      contentContainerStyle={{ padding: spacing.lg, paddingBottom: spacing.xxl }}
      refreshControl={<RefreshControl refreshing={loading} onRefresh={() => load(search)} />}
    >
      <View style={styles.header}>
        <Text style={styles.greeting}>Hi, {user?.name?.split(' ')[0]}</Text>
        <Avatar name={user?.name} seed={user?.id} size={44} />
      </View>

      <View style={styles.sectionHeaderRow}>
        <SectionTitle>Upcoming Appointments</SectionTitle>
        <Text style={styles.viewAll} onPress={() => navigation.getParent()?.navigate('Appointments')}>View all</Text>
      </View>

      {nextAppt ? (
        <Card style={{ marginBottom: spacing.lg }}>
          <View style={styles.apptRow}>
            <View style={{ flex: 1 }}>
              <Text style={styles.apptDoctor}>{nextAppt.doctor?.name}</Text>
              <Text style={styles.apptMeta}>{nextAppt.clinic?.name}</Text>
              <Text style={styles.apptMeta}>{nextAppt.date} · {nextAppt.start_time?.slice(0, 5)}</Text>
            </View>
            <Badge status={nextAppt.status} />
          </View>
        </Card>
      ) : (
        <Card style={{ marginBottom: spacing.lg, alignItems: 'center', paddingVertical: spacing.lg }}>
          <Text style={{ fontSize: 40 }}>🌳🌿</Text>
          <Text style={styles.emptyAppt}>No upcoming appointment</Text>
        </Card>
      )}

      <SectionTitle>Clinic Services</SectionTitle>
      <Text style={styles.subheading}>Find our best services</Text>
      <ScrollView horizontal showsHorizontalScrollIndicator={false} style={{ marginBottom: spacing.lg }}>
        {services.length === 0 ? (
          <Text style={styles.mutedText}>Loading services…</Text>
        ) : (
          services.map((s) => (
            <View key={s} style={styles.serviceItem}>
              <View style={styles.serviceIconCircle}>
                <Text style={{ fontSize: 24 }}>{iconFor(s)}</Text>
              </View>
              <Text style={styles.serviceLabel} numberOfLines={2}>{s}</Text>
            </View>
          ))
        )}
      </ScrollView>

      <View style={styles.sectionHeaderRow}>
        <View>
          <SectionTitle>Top Doctors</SectionTitle>
          <Text style={styles.subheading}>Find the best doctors</Text>
        </View>
        <Text style={styles.viewAll}>View All</Text>
      </View>

      <Input
        placeholder="Search doctors or specialty..."
        value={search}
        onChangeText={setSearch}
        onSubmitEditing={() => load(search)}
      />

      {doctors.length === 0 && !loading ? (
        <EmptyState message="No doctors found." />
      ) : (
        doctors.map((item) => (
          <Card key={item.id} style={{ marginBottom: spacing.sm }}>
            <View style={styles.row} onTouchEnd={() => navigation.navigate('DoctorDetail', { doctorId: item.id })}>
              <Avatar name={item.name} seed={item.id} size={52} />
              <View style={{ flex: 1 }}>
                <View style={styles.nameRow}>
                  <Text style={styles.name}>{item.name}</Text>
                  <View style={[styles.dot, { backgroundColor: item.is_accepting_appointments ? colors.success : colors.gray400 }]} />
                </View>
                <Text style={styles.spec}>{item.specialization}</Text>
                <View style={styles.doctorFooter}>
                  <Text style={styles.bookNow}>Book Now</Text>
                  <Text style={{ fontSize: 14, color: colors.gray400 }}>ⓘ</Text>
                </View>
              </View>
            </View>
          </Card>
        ))
      )}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: colors.gray50 },
  header: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: spacing.lg },
  greeting: { fontFamily: font.bold, fontSize: 22, color: colors.secondary },
  sectionHeaderRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  viewAll: { fontFamily: font.medium, fontSize: 13, color: colors.primary },
  subheading: { fontFamily: font.regular, fontSize: 12, color: colors.gray500, marginTop: -4, marginBottom: spacing.sm },
  apptRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm },
  apptDoctor: { fontFamily: font.semibold, fontSize: 15, color: colors.secondary },
  apptMeta: { fontFamily: font.regular, fontSize: 12, color: colors.gray500, marginTop: 2 },
  emptyAppt: { fontFamily: font.medium, fontSize: 14, color: colors.gray600, marginTop: spacing.sm },
  mutedText: { fontFamily: font.regular, color: colors.gray500, fontSize: 13 },
  serviceItem: { alignItems: 'center', width: 84, marginRight: spacing.md },
  serviceIconCircle: {
    width: 64,
    height: 64,
    borderRadius: 32,
    backgroundColor: colors.white,
    borderWidth: 1,
    borderColor: colors.gray200,
    alignItems: 'center',
    justifyContent: 'center',
  },
  serviceLabel: { marginTop: 8, textAlign: 'center', fontFamily: font.medium, fontSize: 11, color: colors.gray700 },
  row: { flexDirection: 'row', alignItems: 'center', gap: spacing.md },
  nameRow: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  dot: { width: 7, height: 7, borderRadius: 4 },
  name: { fontFamily: font.semibold, fontSize: 15, color: colors.secondary },
  spec: { fontFamily: font.regular, fontSize: 13, color: colors.gray600 },
  doctorFooter: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm, marginTop: 6 },
  bookNow: {
    fontFamily: font.semibold,
    fontSize: 12,
    color: colors.primary,
    borderWidth: 1,
    borderColor: colors.primary,
    borderRadius: radius.full,
    paddingHorizontal: 12,
    paddingVertical: 4,
  },
});
