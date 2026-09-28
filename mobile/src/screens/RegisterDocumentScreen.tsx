import React, { useState } from 'react';
import { Alert, Image, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import * as ImagePicker from 'expo-image-picker';
import * as DocumentPicker from 'expo-document-picker';
import { apiErrorMessage, useAuth } from '../context/AuthContext';
import { PrimaryButton } from '../components/UI';
import { colors, font, radius, spacing } from '../theme';

interface PickedFile {
  uri: string;
  name: string;
  mimeType?: string | null;
}

const DOCUMENT_TYPES: { key: 'id' | 'medical_aid_card'; label: string }[] = [
  { key: 'id', label: 'ID document' },
  { key: 'medical_aid_card', label: 'Medical aid card' },
];

/**
 * Step 4, final — an ID or medical-aid-card photo. Skippable; the account
 * is created either way, this only decides whether a document rides along
 * with the same POST /auth/register call.
 */
export default function RegisterDocumentScreen({ route }: any) {
  const { account, medical, scheme } = route.params;
  const { register } = useAuth();
  const [documentType, setDocumentType] = useState<'id' | 'medical_aid_card'>('id');
  const [file, setFile] = useState<PickedFile | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function takePhoto() {
    const perm = await ImagePicker.requestCameraPermissionsAsync();
    if (!perm.granted) {
      Alert.alert('Camera permission needed', 'Enable camera access to photograph your document.');
      return;
    }
    const result = await ImagePicker.launchCameraAsync({ quality: 0.8 });
    if (result.canceled || !result.assets?.[0]) return;
    const asset = result.assets[0];
    setFile({ uri: asset.uri, name: asset.fileName ?? `document-${Date.now()}.jpg`, mimeType: asset.mimeType ?? 'image/jpeg' });
  }

  async function pickFile() {
    const result = await DocumentPicker.getDocumentAsync({ type: ['image/*', 'application/pdf'], copyToCacheDirectory: true });
    if (result.canceled || !result.assets?.[0]) return;
    const asset = result.assets[0];
    setFile({ uri: asset.uri, name: asset.name, mimeType: asset.mimeType });
  }

  async function finish(withDocument: boolean) {
    setSubmitting(true);
    try {
      await register({
        ...account,
        ...medical,
        ...scheme,
        role: 'patient',
        document: withDocument && file ? file : undefined,
        document_type: withDocument && file ? documentType : undefined,
      });
    } catch (e) {
      Alert.alert('Could not create account', apiErrorMessage(e, 'Please check your details and try again.'));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <ScrollView style={{ flex: 1, backgroundColor: colors.gray50 }} contentContainerStyle={styles.container}>
      <Text style={styles.title}>ID or medical aid card</Text>
      <Text style={styles.subtitle}>Optional — a photo speeds up verification at a clinic or pharmacy.</Text>

      <View style={styles.typeRow}>
        {DOCUMENT_TYPES.map((t) => (
          <Pressable key={t.key} onPress={() => setDocumentType(t.key)} style={[styles.typeOption, documentType === t.key && styles.typeOptionOn]}>
            <Text style={[styles.typeText, documentType === t.key && styles.typeTextOn]}>{t.label}</Text>
          </Pressable>
        ))}
      </View>

      {file ? (
        <Image source={{ uri: file.uri }} style={styles.preview} resizeMode="cover" />
      ) : (
        <View style={styles.dropzone}>
          <Text style={styles.dropzoneText}>Take a photo or choose a file</Text>
        </View>
      )}

      <View style={styles.pickRow}>
        <View style={{ flex: 1 }}><PrimaryButton title="Camera" variant="outline" onPress={takePhoto} /></View>
        <View style={{ flex: 1 }}><PrimaryButton title="Files" variant="outline" onPress={pickFile} /></View>
      </View>

      <PrimaryButton title="Finish" onPress={() => finish(true)} loading={submitting} disabled={!file} />
      <Text style={styles.skip} onPress={() => finish(false)}>Skip & finish</Text>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flexGrow: 1, padding: spacing.lg },
  title: { fontFamily: font.bold, fontSize: 24, color: colors.secondary },
  subtitle: { fontFamily: font.regular, fontSize: 14, color: colors.gray600, marginTop: spacing.xs, marginBottom: spacing.lg },
  typeRow: { flexDirection: 'row', gap: spacing.sm, marginBottom: spacing.md },
  typeOption: { flex: 1, paddingVertical: 12, borderRadius: radius.md, borderWidth: 1, borderColor: colors.gray300, alignItems: 'center' },
  typeOptionOn: { backgroundColor: colors.primaryLight, borderColor: colors.primary },
  typeText: { fontFamily: font.regular, color: colors.gray600 },
  typeTextOn: { fontFamily: font.semibold, color: colors.primary },
  dropzone: { height: 180, borderWidth: 1, borderStyle: 'dashed', borderColor: colors.gray300, borderRadius: radius.md, alignItems: 'center', justifyContent: 'center', marginBottom: spacing.md, backgroundColor: colors.white },
  dropzoneText: { fontFamily: font.regular, color: colors.gray500 },
  preview: { width: '100%', height: 180, borderRadius: radius.md, marginBottom: spacing.md },
  pickRow: { flexDirection: 'row', gap: spacing.sm, marginBottom: spacing.md },
  skip: { fontFamily: font.semibold, color: colors.gray600, textAlign: 'center', marginTop: spacing.md },
});
