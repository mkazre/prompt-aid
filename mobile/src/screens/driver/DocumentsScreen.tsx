import React from 'react';
import { Alert, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useAuth } from '../../context/AuthContext';
import { PaBadge, PaButton, PaRow } from '../../components/pa';
import { daysUntil, formatDate } from '../../components/pa/driver-extras';
import { pa, type } from '../../theme';

/**
 * Compliance documents — driver-documents.html. There is no document
 * upload/storage endpoint on the backend for drivers (confirmed: no
 * routes on `/driver/*`, no controller action, and DriverProfile has no
 * file columns besides `vehicle_photo`), so this screen shows the real
 * compliance fields that DO exist on DriverProfile (license_no,
 * license_expiry, vehicle registration details) rather than a fake
 * document list. "Upload a document" tells the driver honestly that this
 * isn't wired up yet instead of silently doing nothing.
 * TODO: no backend endpoint yet for document upload/storage — add one
 * (e.g. POST /driver/documents) before building real upload here.
 */
export default function DocumentsScreen() {
  const { user, logout } = useAuth();
  const profile = user?.driver_profile;

  const licenseDays = daysUntil(profile?.license_expiry);

  function requestUpload() {
    Alert.alert(
      'Not available yet',
      'Document upload isn\'t wired up in this version of the app. Please contact Prompt Aid support to update your compliance documents.'
    );
  }

  return (
    <ScrollView style={styles.container} contentContainerStyle={{ padding: 16 }}>
      <Text style={[type.h2, { marginBottom: 12 }]}>Documents</Text>

      <PaRow
        title="Driving licence"
        subtitle={profile?.license_no ? `No. ${profile.license_no}` : 'No licence number on file'}
        right={<ExpiryBadge iso={profile?.license_expiry} days={licenseDays} />}
      />

      <PaRow
        title="Vehicle registration"
        subtitle={[profile?.vehicle_make, profile?.vehicle_model, profile?.vehicle_plate_no].filter(Boolean).join(' · ') || 'No vehicle on file'}
        right={<PaBadge tone={profile?.vehicle_plate_no ? 'go' : 'wait'} label={profile?.vehicle_plate_no ? 'On file' : 'Missing'} />}
      />

      <PaRow
        title="Driver account status"
        subtitle={`Rating ${profile?.rating_avg?.toFixed(1) ?? '—'} · ${profile?.rating_count ?? 0} ratings`}
        right={<PaBadge status={profile?.status ?? 'active'} />}
      />

      <View style={{ marginTop: 16 }}>
        <PaButton title="Upload a document" onPress={requestUpload} variant="ghost" style={{ marginBottom: 10 }} />
        <PaButton title="Sign out" onPress={logout} variant="ghost" />
      </View>
    </ScrollView>
  );
}

function ExpiryBadge({ iso, days }: { iso?: string | null; days: number | null }) {
  if (!iso || days === null) return <PaBadge tone="wait" label="Not on file" />;
  if (days < 0) return <PaBadge tone="stop" label="Expired" />;
  if (days <= 30) return <PaBadge tone="stop" label={`${days} days`} />;
  if (days <= 90) return <PaBadge tone="wait" label={`${days} days`} />;
  return <PaBadge tone="go" label={`Valid to ${formatDate(iso)}`} />;
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper },
});
