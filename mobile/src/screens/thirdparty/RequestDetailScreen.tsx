import React, { useCallback, useState } from 'react';
import { Alert, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import * as DocumentPicker from 'expo-document-picker';
import { api, apiErrorMessage } from '../../api/client';
import { LabRequest } from '../../api/types';
import { Badge, Card, Input, PrimaryButton } from '../../components/UI';
import { colors, font, spacing } from '../../theme';

const NEXT_STATUS: Record<string, { next: string; label: string } | undefined> = {
  accepted: { next: 'sample_collected', label: 'Mark sample collected' },
  sample_collected: { next: 'processing', label: 'Mark processing' },
};

export default function RequestDetailScreen({ route, navigation }: any) {
  const { requestId } = route.params;
  const [request, setRequest] = useState<LabRequest | null>(null);
  const [label, setLabel] = useState('');
  const [summary, setSummary] = useState('');
  const [updating, setUpdating] = useState(false);
  const [uploading, setUploading] = useState(false);

  const load = useCallback(async () => {
    const { data } = await api.get(`/lab-requests/${requestId}`);
    setRequest(data.data);
  }, [requestId]);

  useFocusEffect(useCallback(() => { load(); }, [load]));

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

  async function uploadResult() {
    if (!request || !label) return;
    const result = await DocumentPicker.getDocumentAsync({ type: ['image/*', 'application/pdf'], copyToCacheDirectory: true });
    if (result.canceled || !result.assets?.[0]) return;

    const file = result.assets[0];
    setUploading(true);
    try {
      const form = new FormData();
      form.append('label', label);
      if (summary) form.append('summary', summary);
      form.append('file', { uri: file.uri, name: file.name, type: file.mimeType ?? 'application/octet-stream' } as any);

      await api.post(`/third-party/lab-requests/${request.id}/results`, form, { headers: { 'Content-Type': 'multipart/form-data' } });
      setLabel('');
      setSummary('');
      Alert.alert('Result uploaded', 'The doctor and patient can now see this result.', [
        { text: 'OK', onPress: () => navigation.navigate('ThirdPartyHome') },
      ]);
    } catch (e) {
      Alert.alert('Upload failed', apiErrorMessage(e));
    } finally {
      setUploading(false);
    }
  }

  if (!request) return null;

  const step = NEXT_STATUS[request.status];
  const canUpload = ['sample_collected', 'processing'].includes(request.status);

  return (
    <ScrollView style={styles.container} contentContainerStyle={{ padding: spacing.lg }}>
      <View style={styles.rowBetween}>
        <Text style={styles.title}>{request.request_ref}</Text>
        <Badge status={request.status} />
      </View>

      <Card style={{ marginTop: spacing.md }}>
        <Text style={styles.label}>Patient</Text>
        <Text style={styles.value}>{request.patient?.user?.name}</Text>
        <Text style={styles.label}>Referring doctor</Text>
        <Text style={styles.value}>{request.doctor?.name}</Text>
        <Text style={styles.label}>Tests requested</Text>
        {request.items?.map((item) => <Text key={item.id} style={styles.value}>• {item.test_name} {item.sample_type ? `(${item.sample_type})` : ''}</Text>)}
        {request.clinical_notes && (
          <>
            <Text style={styles.label}>Clinical notes</Text>
            <Text style={styles.value}>{request.clinical_notes}</Text>
          </>
        )}
        <Text style={styles.label}>Collection address</Text>
        <Text style={styles.value}>📍 {request.collection_address}</Text>
      </Card>

      {step && (
        <View style={{ marginTop: spacing.md }}>
          <PrimaryButton title={step.label} onPress={advance} loading={updating} />
        </View>
      )}

      {canUpload && (
        <Card style={{ marginTop: spacing.md }}>
          <Text style={styles.sectionTitle}>Upload result</Text>
          <Input label="Report label" value={label} onChangeText={setLabel} placeholder="e.g. Full Blood Count Report" />
          <Input label="Summary (optional)" value={summary} onChangeText={setSummary} placeholder="Key findings..." multiline />
          <PrimaryButton title="📎 Choose file & upload" onPress={uploadResult} loading={uploading} disabled={!label} />
        </Card>
      )}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: colors.gray50 },
  rowBetween: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  title: { fontFamily: font.bold, fontSize: 18, color: colors.secondary },
  label: { fontFamily: font.medium, fontSize: 11, color: colors.gray500, textTransform: 'uppercase', marginTop: spacing.sm },
  value: { fontFamily: font.regular, fontSize: 14, color: colors.gray900, marginTop: 2 },
  sectionTitle: { fontFamily: font.bold, fontSize: 15, color: colors.secondary, marginBottom: spacing.sm },
});
