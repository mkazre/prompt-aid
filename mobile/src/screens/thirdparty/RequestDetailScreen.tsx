import React, { useCallback, useState } from 'react';
import { Alert, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import * as DocumentPicker from 'expo-document-picker';
import { api, apiErrorMessage } from '../../api/client';
import { LabRequest } from '../../api/types';
import { useAuth } from '../../context/AuthContext';
import { pa, paFonts, type } from '../../theme';
import { PaBadge, PaButton, PaCard, PaInput } from '../../components/pa';
import { PaFileSlot, PaSpreadRow, categoryNoun } from '../../components/pa/partner-extras';

const NEXT_STATUS: Record<string, { next: string; label: string } | undefined> = {
  accepted: { next: 'sample_collected', label: 'Mark started' },
  sample_collected: { next: 'processing', label: 'Mark in progress' },
};

function age(dob?: string | null): number | null {
  if (!dob) return null;
  const d = new Date(dob);
  if (Number.isNaN(d.getTime())) return null;
  const diff = Date.now() - d.getTime();
  return Math.floor(diff / (365.25 * 24 * 60 * 60 * 1000));
}

export default function RequestDetailScreen({ route, navigation }: any) {
  const { requestId } = route.params;
  const { user } = useAuth();
  const profile = user?.third_party_profile;
  const [request, setRequest] = useState<LabRequest | null>(null);
  const [label, setLabel] = useState('');
  const [summary, setSummary] = useState('');
  const [accepting, setAccepting] = useState(false);
  const [updating, setUpdating] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [file, setFile] = useState<DocumentPicker.DocumentPickerAsset | null>(null);

  const load = useCallback(async () => {
    const { data } = await api.get(`/lab-requests/${requestId}`);
    setRequest(data.data);
  }, [requestId]);

  useFocusEffect(useCallback(() => { load(); }, [load]));

  async function accept() {
    if (!request) return;
    setAccepting(true);
    try {
      await api.post(`/third-party/lab-requests/${request.id}/accept`);
      await load();
    } catch (e) {
      Alert.alert('Could not accept', apiErrorMessage(e));
    } finally {
      setAccepting(false);
    }
  }

  async function advance() {
    if (!request) return;
    const step = NEXT_STATUS[request.status];
    if (!step) return;

    setUpdating(true);
    try {
      await api.post(`/third-party/lab-requests/${request.id}/status`, { status: step.next });
      await load();
    } catch (e) {
      Alert.alert('Could not update', apiErrorMessage(e));
    } finally {
      setUpdating(false);
    }
  }

  async function pickFile() {
    const result = await DocumentPicker.getDocumentAsync({ type: ['image/*', 'application/pdf'], copyToCacheDirectory: true });
    if (result.canceled || !result.assets?.[0]) return;
    setFile(result.assets[0]);
  }

  async function uploadResult() {
    if (!request || !label || !file) return;
    setUploading(true);
    try {
      const form = new FormData();
      form.append('label', label);
      if (summary) form.append('summary', summary);
      form.append('file', { uri: file.uri, name: file.name, type: file.mimeType ?? 'application/octet-stream' } as any);

      await api.post(`/third-party/lab-requests/${request.id}/results`, form, { headers: { 'Content-Type': 'multipart/form-data' } });
      setLabel('');
      setSummary('');
      setFile(null);
      Alert.alert('Outcome uploaded', 'The doctor and patient can now see this outcome.', [
        { text: 'OK', onPress: () => navigation.navigate('ThirdPartyHome') },
      ]);
    } catch (e) {
      Alert.alert('Upload failed', apiErrorMessage(e));
    } finally {
      setUploading(false);
    }
  }

  if (!request) return null;

  const noun = categoryNoun(profile?.category, true);
  const needsAccept = request.status === 'requested';
  const step = NEXT_STATUS[request.status];
  const canUpload = ['sample_collected', 'processing'].includes(request.status);
  const patientAge = age(request.patient?.dob);
  const itemsLabel = request.items?.map((i) => i.test_name).join(', ') || 'Request';

  return (
    <ScrollView style={styles.container} contentContainerStyle={{ padding: 20 }}>
      <View style={styles.rowBetween}>
        {request.priority === 'urgent' ? <PaBadge label="Urgent" tone="stop" /> : <PaBadge label="Routine" tone="ink" />}
        <PaBadge status={request.status} />
      </View>

      <Text style={styles.title}>{itemsLabel}</Text>
      <Text style={styles.subtitle}>{request.request_ref} · Referred by Dr. {request.doctor?.name}</Text>

      <PaCard style={{ marginTop: 16 }}>
        <PaSpreadRow label="Patient" value={`${request.patient?.user?.name ?? 'Patient'}${patientAge ? `, ${patientAge}` : ''}`} />
        <PaSpreadRow label="Referring doctor" value={`Dr. ${request.doctor?.name ?? '—'}`} />
        {request.collection_address ? <PaSpreadRow label="Address" value={request.collection_address} /> : null}
      </PaCard>

      <PaCard style={{ marginTop: 12 }}>
        <Text style={styles.sectionTitle}>{categoryNoun(profile?.category, true).replace(/^\w/, (c) => c.toUpperCase())} requested</Text>
        {request.items?.map((item) => (
          <Text key={item.id} style={styles.value}>• {item.test_name} {item.sample_type ? `(${item.sample_type})` : ''}</Text>
        ))}
        {request.clinical_notes ? (
          <>
            <Text style={[styles.label, { marginTop: 10 }]}>Clinical notes</Text>
            <Text style={styles.value}>{request.clinical_notes}</Text>
          </>
        ) : null}
      </PaCard>

      {needsAccept && (
        <View style={{ marginTop: 16, gap: 8 }}>
          <PaButton title={`Accept ${categoryNoun(profile?.category)}`} onPress={accept} loading={accepting} />
        </View>
      )}

      {step && (
        <View style={{ marginTop: 16 }}>
          <PaButton title={step.label} onPress={advance} loading={updating} />
        </View>
      )}

      {canUpload && (
        <PaCard style={{ marginTop: 16 }}>
          <Text style={styles.sectionTitle}>Upload outcome</Text>
          <PaFileSlot label="Attach signed report" fileName={file?.name} onPress={pickFile} />
          <PaInput label="Outcome label" value={label} onChangeText={setLabel} placeholder="e.g. Full Blood Count Report" />
          <PaInput label="Summary (optional)" value={summary} onChangeText={setSummary} placeholder="Key findings..." multiline />
          <PaButton title="Release outcome" onPress={uploadResult} loading={uploading} disabled={!label || !file} />
        </PaCard>
      )}

      {!!request.results?.length && (
        <PaCard style={{ marginTop: 16 }}>
          <Text style={styles.sectionTitle}>Outcomes uploaded</Text>
          {request.results.map((r) => (
            <View key={r.id} style={{ marginBottom: 8 }}>
              <Text style={styles.value}>{r.label}</Text>
              {r.summary ? <Text style={styles.label}>{r.summary}</Text> : null}
            </View>
          ))}
        </PaCard>
      )}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper },
  rowBetween: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  title: { ...type.h2, color: pa.ink, marginTop: 12 },
  subtitle: { fontFamily: paFonts.regular, fontSize: 13, color: pa.muted, marginTop: 2, marginBottom: 4 },
  label: { fontFamily: paFonts.regular, fontSize: 11, color: pa.muted, textTransform: 'uppercase', marginTop: 8 },
  value: { fontFamily: paFonts.regular, fontSize: 14, color: pa.ink, marginTop: 2 },
  sectionTitle: { ...type.label, marginBottom: 10 },
});
