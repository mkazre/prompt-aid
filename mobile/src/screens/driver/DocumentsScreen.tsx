import React, { useCallback, useState } from 'react';
import { Alert, Pressable, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import * as DocumentPicker from 'expo-document-picker';
import * as ImagePicker from 'expo-image-picker';
import { Ionicons } from '@expo/vector-icons';
import { api, apiErrorMessage } from '../../api/client';
import { useAuth } from '../../context/AuthContext';
import { PaBadge, PaRow } from '../../components/pa';
import { daysUntil, formatDate } from '../../components/pa/driver-extras';
import AccountTiles from '../../components/AccountTiles';
import { DateField, DatePickerSheet } from '../../components/DatePickerSheet';
import { Chip, EmptyNote, Fab, PrimaryButton, SectionTitle, Sheet, SoftCard } from '../../components/settings-ui';
import { pa, paFonts } from '../../theme';
import { addDays, startOfToday, ymd } from '../../services/dates';

interface DriverDoc {
  id: number;
  type: string;
  type_label: string;
  original_name: string;
  expires_at: string | null;
  status: 'pending' | 'approved' | 'rejected';
  review_note: string | null;
  uploaded_at: string | null;
  expiry_status?: 'expired' | 'expiring' | 'valid' | null;
  days_until_expiry?: number | null;
}

/** Expiry line + colour for a document (server computes status in SA time). */
function expiryInfo(d: DriverDoc): { text: string; color: string } | null {
  if (!d.expires_at) return null;
  const days = d.days_until_expiry;
  if (d.expiry_status === 'expired') return { text: `Expired ${formatDate(d.expires_at)} — upload a renewed copy`, color: pa.signal };
  if (d.expiry_status === 'expiring') {
    const when = days === 0 ? 'today' : `in ${days} day${days === 1 ? '' : 's'}`;
    return { text: `Expires ${when} (${formatDate(d.expires_at)})`, color: pa.sats.orange };
  }
  return { text: `Valid until ${formatDate(d.expires_at)}`, color: pa.go };
}

type PickedFile = { uri: string; name: string; mimeType: string };

/**
 * Driver profile: compliance fields on file, uploaded compliance documents
 * (GET/POST/DELETE /driver/documents — reviewed by admins on the Driver
 * Profile page) and account settings.
 */
export default function DocumentsScreen({ navigation }: any) {
  const insets = useSafeAreaInsets();
  const { user } = useAuth();
  const profile = user?.driver_profile;
  const [docs, setDocs] = useState<DriverDoc[]>([]);
  const [types, setTypes] = useState<{ key: string; label: string }[]>([]);
  const [loading, setLoading] = useState(true);
  const [uploading, setUploading] = useState(false);

  const licenseDays = daysUntil(profile?.license_expiry);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const { data } = await api.get('/driver/documents');
      setDocs(data.data);
      setTypes(data.types);
    } catch (e) {
      Alert.alert('Could not load documents', apiErrorMessage(e));
    } finally {
      setLoading(false);
    }
  }, []);

  useFocusEffect(useCallback(() => { load(); }, [load]));

  function withdraw(doc: DriverDoc) {
    Alert.alert('Remove upload', `Remove "${doc.original_name}"?`, [
      { text: 'Cancel', style: 'cancel' },
      {
        text: 'Remove',
        style: 'destructive',
        onPress: async () => {
          try {
            await api.delete(`/driver/documents/${doc.id}`);
            setDocs((d) => d.filter((x) => x.id !== doc.id));
          } catch (e) {
            Alert.alert('Could not remove', apiErrorMessage(e));
          }
        },
      },
    ]);
  }

  return (
    <View style={styles.container}>
      <ScrollView contentContainerStyle={{ padding: 16, paddingTop: insets.top + 12, paddingBottom: insets.bottom + 100 }} refreshControl={<RefreshControl refreshing={loading} onRefresh={load} />}>
        <Text style={styles.title}>Profile & documents</Text>

        <SoftCard style={{ marginBottom: 20, paddingVertical: 4 }}>
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
        </SoftCard>

        <SectionTitle>Uploaded documents</SectionTitle>
        {docs.length === 0 && !loading ? (
          <SoftCard style={{ marginBottom: 24 }}><EmptyNote icon="folder-open-outline" text="No documents uploaded yet. Tap + to upload your licence, PrDP, registration or insurance." /></SoftCard>
        ) : (
          <View style={{ marginBottom: 24 }}>
            {docs.map((d) => (
              <SoftCard key={d.id} style={{ marginBottom: 8, flexDirection: 'row', alignItems: 'center', gap: 12 }}>
                <Ionicons name="document-attach-outline" size={24} color={pa.info} />
                <View style={{ flex: 1 }}>
                  <Text style={styles.docTitle}>{d.type_label}</Text>
                  <Text style={styles.docSub} numberOfLines={1}>
                    {d.original_name}
                  </Text>
                  {expiryInfo(d) ? (
                    <Text style={[styles.docSub, { color: expiryInfo(d)!.color, fontFamily: paFonts.bold }]}>{expiryInfo(d)!.text}</Text>
                  ) : (
                    <Text style={styles.docSub}>No expiry date</Text>
                  )}
                  {d.status === 'rejected' && d.review_note ? <Text style={[styles.docSub, { color: pa.signal }]}>{d.review_note}</Text> : null}
                </View>
                <View style={{ alignItems: 'flex-end', gap: 6 }}>
                  <PaBadge status={d.status === 'pending' ? 'pending' : d.status} label={d.status === 'pending' ? 'In review' : undefined} />
                  {d.status === 'pending' ? (
                    <Pressable onPress={() => withdraw(d)} hitSlop={8}>
                      <Text style={{ fontFamily: paFonts.bold, fontSize: 12, color: pa.signal }}>Remove</Text>
                    </Pressable>
                  ) : null}
                </View>
              </SoftCard>
            ))}
          </View>
        )}

        <AccountTiles navigation={navigation} />
      </ScrollView>

      <Fab label="Upload a document" onPress={() => setUploading(true)} bottom={insets.bottom + 24} />
      {uploading ? <UploadSheet types={types} onClose={() => setUploading(false)} onDone={() => { setUploading(false); load(); }} /> : null}
    </View>
  );
}

function UploadSheet({ types, onClose, onDone }: { types: { key: string; label: string }[]; onClose: () => void; onDone: () => void }) {
  const [type, setType] = useState<string | null>(types[0]?.key ?? null);
  const [file, setFile] = useState<PickedFile | null>(null);
  const [expires, setExpires] = useState<string | null>(null);
  const [picker, setPicker] = useState(false);
  const [saving, setSaving] = useState(false);

  async function pickFile() {
    const res = await DocumentPicker.getDocumentAsync({ type: ['application/pdf', 'image/*'], copyToCacheDirectory: true });
    if (!res.canceled && res.assets[0]) {
      const a = res.assets[0];
      setFile({ uri: a.uri, name: a.name, mimeType: a.mimeType ?? 'application/octet-stream' });
    }
  }

  async function takePhoto() {
    const perm = await ImagePicker.requestCameraPermissionsAsync();
    if (!perm.granted) {
      Alert.alert('Camera permission needed', 'Allow camera access to photograph your document.');
      return;
    }
    const res = await ImagePicker.launchCameraAsync({ mediaTypes: ['images'], quality: 0.7 });
    if (!res.canceled && res.assets[0]) {
      const a = res.assets[0];
      setFile({ uri: a.uri, name: a.fileName ?? 'document.jpg', mimeType: a.mimeType ?? 'image/jpeg' });
    }
  }

  async function upload() {
    if (!type || !file) return;
    setSaving(true);
    try {
      const form = new FormData();
      form.append('type', type);
      if (expires) form.append('expires_at', expires);
      form.append('file', { uri: file.uri, name: file.name, type: file.mimeType } as any);
      await api.post('/driver/documents', form, { headers: { 'Content-Type': 'multipart/form-data' }, timeout: 60000 });
      Alert.alert('Uploaded', 'Your document was sent for review.');
      onDone();
    } catch (e) {
      Alert.alert('Upload failed', apiErrorMessage(e));
    } finally {
      setSaving(false);
    }
  }

  return (
    <Sheet visible onClose={onClose} title="Upload a document" footer={<PrimaryButton title="Upload" onPress={upload} loading={saving} disabled={!type || !file} />}>
      <Text style={styles.label}>Document type</Text>
      <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginBottom: 14 }}>
        {types.map((t) => <Chip key={t.key} label={t.label} active={type === t.key} onPress={() => setType(t.key)} />)}
      </View>

      <Text style={styles.label}>File (PDF or photo, max 10 MB)</Text>
      <View style={{ flexDirection: 'row', gap: 10, marginBottom: 10 }}>
        <PrimaryButton title="Choose file" tone="ghost" icon="folder-open-outline" onPress={pickFile} style={{ flex: 1 }} />
        <PrimaryButton title="Take photo" tone="ghost" icon="camera-outline" onPress={takePhoto} style={{ flex: 1 }} />
      </View>
      {file ? <Text style={[styles.docSub, { marginBottom: 12 }]} numberOfLines={1}>Selected: {file.name}</Text> : null}

      <DateField label="Expiry date (if any)" value={expires} placeholder="No expiry" onPress={() => setPicker(true)} />
      <DatePickerSheet visible={picker} value={expires} min={ymd(addDays(startOfToday(), 1))} onClose={() => setPicker(false)} onPick={setExpires} title="Document expires" />
    </Sheet>
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
  title: { fontFamily: paFonts.black, fontSize: 22, color: pa.ink, marginBottom: 16 },
  docTitle: { fontFamily: paFonts.bold, fontSize: 14, color: pa.ink },
  docSub: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted, marginTop: 2 },
  label: { fontFamily: paFonts.bold, fontSize: 12, color: pa.muted, marginBottom: 6 },
});
