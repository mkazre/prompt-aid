import React, { useState } from 'react';
import { Alert, Image, KeyboardAvoidingView, Platform, Pressable, ScrollView, Switch, Text, View } from 'react-native';
import * as ImagePicker from 'expo-image-picker';
import { Ionicons } from '@expo/vector-icons';
import { api, apiErrorMessage } from '../../api/client';
import { useAuth } from '../../context/AuthContext';
import { PaAvatar } from '../../components/pa';
import { Field, PrimaryButton, SoftCard } from '../../components/settings-ui';
import { avatarUrl } from '../../services/app';
import { pa, paFonts } from '../../theme';

/** Doctor profile editor — POST /doctor/profile (multipart when a photo is chosen). */
export default function DoctorEditProfileScreen({ navigation }: any) {
  const { user, refreshMe } = useAuth();
  const dp = user?.doctor_profile;
  const [name, setName] = useState(user?.name ?? '');
  const [phone, setPhone] = useState(user?.phone ?? '');
  const [specialization, setSpecialization] = useState(dp?.specialization ?? '');
  const [qualification, setQualification] = useState(dp?.qualification ?? '');
  const [experience, setExperience] = useState(dp?.experience_years != null ? String(dp.experience_years) : '');
  const [bio, setBio] = useState(dp?.bio ?? '');
  const [accepting, setAccepting] = useState(dp?.is_accepting_appointments ?? true);
  const [photo, setPhoto] = useState<ImagePicker.ImagePickerAsset | null>(null);
  const [saving, setSaving] = useState(false);

  async function pickPhoto() {
    const perm = await ImagePicker.requestMediaLibraryPermissionsAsync();
    if (!perm.granted) {
      Alert.alert('Permission needed', 'Allow photo access to choose a profile picture.');
      return;
    }
    const res = await ImagePicker.launchImageLibraryAsync({ mediaTypes: ['images'], allowsEditing: true, aspect: [1, 1], quality: 0.7 });
    if (!res.canceled && res.assets[0]) setPhoto(res.assets[0]);
  }

  async function save() {
    if (!name.trim()) {
      Alert.alert('Name required', 'Enter your name.');
      return;
    }
    setSaving(true);
    const fields: Record<string, string> = {
      name: name.trim(),
      phone: phone.trim(),
      specialization: specialization.trim(),
      qualification: qualification.trim(),
      bio: bio.trim(),
      is_accepting_appointments: accepting ? '1' : '0',
    };
    if (experience !== '') fields.experience_years = experience;

    try {
      const form = new FormData();
      Object.entries(fields).forEach(([k, v]) => form.append(k, v));
      if (photo) {
        const ext = (photo.uri.split('.').pop() || 'jpg').toLowerCase();
        form.append('photo', { uri: photo.uri, name: photo.fileName ?? `avatar.${ext}`, type: photo.mimeType ?? `image/${ext === 'jpg' ? 'jpeg' : ext}` } as any);
      }
      await api.post('/doctor/profile', form, { headers: { 'Content-Type': 'multipart/form-data' } });
      await refreshMe();
      navigation.goBack();
    } catch (e) {
      Alert.alert('Could not save profile', apiErrorMessage(e));
    } finally {
      setSaving(false);
    }
  }

  const current = photo?.uri ?? avatarUrl(user?.avatar);

  return (
    <KeyboardAvoidingView style={{ flex: 1, backgroundColor: pa.paper }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }} keyboardShouldPersistTaps="handled">
        <View style={{ alignItems: 'center', marginBottom: 18 }}>
          <Pressable onPress={pickPhoto} accessibilityLabel="Change photo">
            {current ? <Image source={{ uri: current }} style={{ width: 96, height: 96, borderRadius: 48 }} /> : <PaAvatar name={name} size={96} />}
            <View style={{ position: 'absolute', right: 0, bottom: 0, width: 32, height: 32, borderRadius: 16, backgroundColor: pa.signal, alignItems: 'center', justifyContent: 'center', borderWidth: 2, borderColor: '#fff' }}>
              <Ionicons name="camera-outline" size={16} color="#fff" />
            </View>
          </Pressable>
        </View>

        <SoftCard>
          <Field label="Full name" value={name} onChangeText={setName} />
          <Field label="Phone" value={phone} onChangeText={setPhone} keyboardType="phone-pad" />
          <Field label="Speciality" value={specialization} onChangeText={setSpecialization} placeholder="e.g. General Practitioner" />
          <Field label="Qualification" value={qualification} onChangeText={setQualification} placeholder="e.g. MBChB (UCT)" />
          <Field label="Years of experience" value={experience} onChangeText={(t) => setExperience(t.replace(/[^0-9]/g, ''))} keyboardType="number-pad" maxLength={2} />
          <Field label="Bio" value={bio} onChangeText={setBio} multiline maxLength={3000} style={{ minHeight: 100, textAlignVertical: 'top' }} />
          <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16 }}>
            <View style={{ flex: 1 }}>
              <Text style={{ fontFamily: paFonts.bold, fontSize: 14, color: pa.ink }}>Accepting new bookings</Text>
              <Text style={{ fontFamily: paFonts.regular, fontSize: 12, color: pa.muted }}>Turn off to hide your booking calendar from patients.</Text>
            </View>
            <Switch value={accepting} onValueChange={setAccepting} trackColor={{ true: pa.go, false: pa.line }} />
          </View>
          <PrimaryButton title="Save profile" onPress={save} loading={saving} />
        </SoftCard>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}
