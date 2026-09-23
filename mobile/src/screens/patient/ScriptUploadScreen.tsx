import React, { useCallback, useState } from 'react';
import { Alert, Image, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import * as ImagePicker from 'expo-image-picker';
import * as DocumentPicker from 'expo-document-picker';
import { api, apiErrorMessage } from '../../api/client';
import { Pharmacy } from '../../api/types';
import { PaButton } from '../../components/pa';
import { PaChips } from '../../components/pa/patient-commerce-extras';
import { pa, paFonts, paRadius, type } from '../../theme';

interface PickedFile {
  uri: string;
  name: string;
  mimeType?: string | null;
}

const FULFILMENT_OPTIONS = ['Deliver', 'Collect', 'Collect by shuttle'];

/**
 * Standalone prescription upload — matches `script-upload.html`.
 * `POST /prescriptions` only accepts `file` + `notes`; there's no backend
 * field for "dispense at" pharmacy or fulfilment method, so those choices
 * are folded into the free-text `notes` sent with the upload (a real gap —
 * see final report).
 */
export default function ScriptUploadScreen({ navigation }: any) {
  const [pharmacies, setPharmacies] = useState<Pharmacy[]>([]);
  const [pharmacyId, setPharmacyId] = useState<number | null>(null);
  const [fulfilment, setFulfilment] = useState('Deliver');
  const [file, setFile] = useState<PickedFile | null>(null);
  const [uploading, setUploading] = useState(false);

  const load = useCallback(async () => {
    const { data } = await api.get('/pharmacies');
    setPharmacies(data.data);
    if (data.data[0]) setPharmacyId(data.data[0].id);
  }, []);

  useFocusEffect(useCallback(() => { load(); }, [load]));

  async function takePhoto() {
    const perm = await ImagePicker.requestCameraPermissionsAsync();
    if (!perm.granted) {
      Alert.alert('Camera permission needed', 'Enable camera access to photograph your script.');
      return;
    }
    const result = await ImagePicker.launchCameraAsync({ quality: 0.8 });
    if (result.canceled || !result.assets?.[0]) return;
    const asset = result.assets[0];
    setFile({ uri: asset.uri, name: asset.fileName ?? `script-${Date.now()}.jpg`, mimeType: asset.mimeType ?? 'image/jpeg' });
  }

  async function pickFile() {
    const result = await DocumentPicker.getDocumentAsync({ type: ['image/*', 'application/pdf'], copyToCacheDirectory: true });
    if (result.canceled || !result.assets?.[0]) return;
    const asset = result.assets[0];
    setFile({ uri: asset.uri, name: asset.name, mimeType: asset.mimeType });
  }

  async function submit() {
    if (!file) {
      Alert.alert('Add a photo or file', 'Take a photo of your script or choose a PDF first.');
      return;
    }
    setUploading(true);
    try {
      const pharmacyName = pharmacies.find((p) => p.id === pharmacyId)?.name;
      const form = new FormData();
      form.append('file', { uri: file.uri, name: file.name, type: file.mimeType ?? 'application/octet-stream' } as any);
      form.append('notes', `Dispense at: ${pharmacyName ?? '—'} · Fulfilment: ${fulfilment}`);
      await api.post('/prescriptions', form, { headers: { 'Content-Type': 'multipart/form-data' } });
      Alert.alert('Sent for review', 'A pharmacist will review your script shortly.', [
        { text: 'OK', onPress: () => navigation.navigate('Orders') },
      ]);
    } catch (e) {
      Alert.alert('Upload failed', apiErrorMessage(e));
    } finally {
      setUploading(false);
    }
  }

  return (
    <ScrollView style={styles.container} contentContainerStyle={{ padding: 16, paddingBottom: 40 }}>
      <Text style={[type.h2, { marginBottom: 12 }]}>Upload a script</Text>

      {file ? (
        <Image source={{ uri: file.uri }} style={styles.preview} resizeMode="cover" />
      ) : (
        <View style={styles.dropzone}>
          <Text style={styles.dropzoneText}>Take a photo or choose a PDF</Text>
        </View>
      )}

      <View style={styles.pickRow}>
        <PaButton title="Camera" variant="ghost" onPress={takePhoto} style={{ flex: 1 }} />
        <PaButton title="Files" variant="ghost" onPress={pickFile} style={{ flex: 1 }} />
      </View>

      <Text style={styles.label}>Dispense at</Text>
      <View style={styles.pharmacyList}>
        {pharmacies.map((p) => {
          const on = p.id === pharmacyId;
          return (
            <Text
              key={p.id}
              onPress={() => setPharmacyId(p.id)}
              style={[styles.pharmacyOption, on && styles.pharmacyOptionOn]}
            >
              {p.name} · {p.city}
            </Text>
          );
        })}
        {!pharmacies.length && <Text style={styles.pharmacyEmpty}>Loading pharmacies…</Text>}
      </View>

      <Text style={styles.label}>Fulfilment</Text>
      <PaChips options={FULFILMENT_OPTIONS} value={fulfilment} onChange={setFulfilment} />

      <Text style={styles.note}>Schedule 6 medicines must be collected in person with ID.</Text>

      <PaButton title="Send for review" onPress={submit} loading={uploading} style={{ marginTop: 8 }} />
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper, padding: 16 },
  dropzone: {
    height: 210,
    borderWidth: 1,
    borderStyle: 'dashed',
    borderColor: pa.line,
    borderRadius: paRadius.sm,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: 12,
    backgroundColor: pa.surface,
  },
  dropzoneText: { fontFamily: paFonts.regular, color: pa.muted },
  preview: { width: '100%', height: 210, borderRadius: paRadius.sm, marginBottom: 12 },
  pickRow: { flexDirection: 'row', gap: 8, marginBottom: 16 },
  label: { fontFamily: paFonts.black, fontSize: 11, letterSpacing: 1.2, textTransform: 'uppercase', color: pa.muted, marginBottom: 8 },
  pharmacyList: { marginBottom: 14, gap: 8 },
  pharmacyOption: {
    borderWidth: 1,
    borderColor: pa.line,
    borderRadius: paRadius.sm,
    paddingHorizontal: 14,
    paddingVertical: 12,
    fontFamily: paFonts.regular,
    fontSize: 14,
    color: pa.ink,
    backgroundColor: pa.surface,
  },
  pharmacyOptionOn: { borderColor: pa.ink, backgroundColor: pa.beaconWash, fontFamily: paFonts.bold },
  pharmacyEmpty: { fontFamily: paFonts.regular, color: pa.muted, fontSize: 13 },
  note: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted, backgroundColor: pa.lineSoft, padding: 10, borderRadius: paRadius.sm, marginVertical: 14 },
});
