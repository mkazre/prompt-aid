import React from 'react';
import { Image, Linking, Pressable, StyleSheet, Text, View } from 'react-native';
import { RiderProfile } from '../api/types';
import { pa, paFonts } from '../theme';

/**
 * "Who am I picking up" (Bolt/Uber style) for drivers: photo/initials,
 * name, rating (or "New rider"), trips, member since, verification badges
 * (only shown as verified when the server has a real verification), mobility
 * needs, pickup notes, booked-by and — during an active trip — the
 * emergency contact. The server decides what is visible (offered drivers
 * only get first name, rating and needs; phone only while assigned and up
 * to 2 h after the trip).
 */
export default function RiderCard({ rider, dark = false, compact = false }: { rider?: RiderProfile | null; dark?: boolean; compact?: boolean }) {
  if (!rider) return null;
  const v = rider.verified ?? { phone: false, id: false, medical_aid: false };
  const fg = dark ? '#fff' : pa.ink;
  const sub = dark ? '#C9C4BA' : pa.muted;
  const call = (n: string | null | undefined) => {
    if (n) Linking.openURL(`tel:${n.replace(/[^0-9+]/g, '')}`);
  };

  return (
    <View style={[styles.card, dark && styles.cardDark]}>
      <View style={styles.row}>
        <View style={styles.avatar}>
          {rider.photo_url ? <Image source={{ uri: rider.photo_url }} style={styles.photo} /> : <Text style={styles.initials}>{rider.initials}</Text>}
        </View>
        <View style={{ flex: 1, minWidth: 0 }}>
          <Text style={[styles.name, { color: fg }]} numberOfLines={1}>
            {rider.full_name || rider.first_name}
          </Text>
          <Text style={[styles.meta, { color: sub }]} numberOfLines={1}>
            {rider.rating_label} · {rider.trips_completed} trip{rider.trips_completed === 1 ? '' : 's'}
            {rider.member_since ? ` · since ${rider.member_since}` : ''}
          </Text>
        </View>
        {rider.phone ? (
          <Pressable onPress={() => call(rider.phone)} style={styles.callBtn} accessibilityRole="button" accessibilityLabel={`Call ${rider.first_name}`}>
            <Text style={styles.callText}>Call</Text>
          </Pressable>
        ) : null}
      </View>

      <View style={styles.badges}>
        <Badge ok={v.phone} label="Phone" />
        <Badge ok={v.id} label="ID" />
        <Badge ok={v.medical_aid} label="Medical aid" />
      </View>

      {rider.needs?.length ? (
        <View style={styles.need}>
          <Text style={styles.needText}>♿ {rider.needs.join(' · ')}</Text>
        </View>
      ) : null}
      {rider.pickup_notes ? (
        <View style={[styles.need, { backgroundColor: dark ? '#26262A' : pa.surface2, borderColor: dark ? '#3A3A3E' : pa.lineSoft }]}>
          <Text style={[styles.needText, { color: fg }]}>📝 {rider.pickup_notes}</Text>
        </View>
      ) : null}
      {!compact && rider.booked_by ? (
        <Pressable onPress={() => call(rider.booked_by?.phone)} disabled={!rider.booked_by.phone}>
          <Text style={[styles.meta, { color: sub, marginTop: 6 }]}>
            Booked by {rider.booked_by.name}
            {rider.booked_by.phone ? ` · ${rider.booked_by.phone}` : ''}
          </Text>
        </Pressable>
      ) : null}
      {!compact && rider.emergency_contact ? (
        <Pressable onPress={() => call(rider.emergency_contact?.phone)}>
          <Text style={[styles.meta, { color: sub, marginTop: 4 }]}>
            Emergency contact: {rider.emergency_contact.name ?? ''} · {rider.emergency_contact.phone}
          </Text>
        </Pressable>
      ) : null}
    </View>
  );
}

function Badge({ ok, label }: { ok: boolean; label: string }) {
  return (
    <View style={[styles.badge, ok ? styles.badgeOk : styles.badgeNo]}>
      <Text style={[styles.badgeText, { color: ok ? pa.go : pa.muted }]} numberOfLines={1}>
        {ok ? `✓ ${label} verified` : `${label} not verified`}
      </Text>
    </View>
  );
}

const styles = StyleSheet.create({
  card: { backgroundColor: pa.surface2, borderWidth: 1, borderColor: pa.lineSoft, borderRadius: 12, padding: 12, marginBottom: 12 },
  cardDark: { backgroundColor: '#1C1C20', borderColor: '#2E2E33' },
  row: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  avatar: { width: 46, height: 46, borderRadius: 23, backgroundColor: pa.ink, alignItems: 'center', justifyContent: 'center', overflow: 'hidden' },
  photo: { width: 46, height: 46 },
  initials: { color: '#fff', fontFamily: paFonts.black, fontSize: 16 },
  name: { fontFamily: paFonts.black, fontSize: 16 },
  meta: { fontFamily: paFonts.regular, fontSize: 12, marginTop: 2 },
  callBtn: { backgroundColor: pa.go, paddingHorizontal: 14, paddingVertical: 9, borderRadius: 999 },
  callText: { color: '#fff', fontFamily: paFonts.bold, fontSize: 13 },
  badges: { flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginTop: 10 },
  badge: { paddingHorizontal: 8, paddingVertical: 3, borderRadius: 999 },
  badgeOk: { backgroundColor: pa.goWash },
  badgeNo: { backgroundColor: '#EFEBE2' },
  badgeText: { fontFamily: paFonts.bold, fontSize: 11 },
  need: { marginTop: 10, backgroundColor: pa.beaconWash, borderWidth: 1, borderColor: pa.beaconLine, borderRadius: 10, padding: 8 },
  needText: { fontFamily: paFonts.bold, fontSize: 13, color: pa.ink },
});
