import React, { useState } from 'react';
import { Alert, Image, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { api, apiErrorMessage } from '../api/client';
import { useAuth } from '../context/AuthContext';
import { PaAvatar } from '../components/pa';
import AccountTiles from '../components/AccountTiles';
import { Field, PrimaryButton, SectionTitle, SettingTile, Sheet, SoftCard } from '../components/settings-ui';
import { avatarUrl } from '../services/app';
import { pa, paFonts } from '../theme';

/**
 * Profile & settings for patient, pharmacy vendor and lab/partner roles:
 * editable name/phone (PUT /profile), role shortcuts, and the shared
 * account tiles (password, T&C, about, logout, version).
 */
export default function ProfileScreen({ navigation, route }: any) {
  const insets = useSafeAreaInsets();
  const { user } = useAuth();
  const [editing, setEditing] = useState(false);
  const isPatient = user?.role === 'patient';
  const photo = avatarUrl(user?.avatar);
  // Pushed inside the patient Record stack (has a header) vs. a tab root.
  const hasHeader = route?.name === 'ProfileHome';

  return (
    <ScrollView style={styles.container} contentContainerStyle={{ padding: 16, paddingTop: hasHeader ? 16 : insets.top + 12, paddingBottom: 48 }}>
      {!hasHeader ? <Text style={styles.title}>Profile</Text> : null}

      <SoftCard style={styles.profile}>
        {photo ? <Image source={{ uri: photo }} style={styles.photo} /> : <PaAvatar name={user?.name} size={56} />}
        <View style={{ flex: 1 }}>
          <Text style={styles.name} numberOfLines={1}>{user?.name}</Text>
          <Text style={styles.sub} numberOfLines={1}>{roleLabel(user?.role)}</Text>
          <Text style={styles.sub} numberOfLines={1}>{user?.phone ?? user?.email}</Text>
        </View>
        <Pressable onPress={() => setEditing(true)} hitSlop={12} style={styles.edit} accessibilityLabel="Edit profile">
          <Ionicons name="create-outline" size={20} color={pa.info} />
        </Pressable>
      </SoftCard>

      {isPatient ? (
        <>
          <SectionTitle>My health</SectionTitle>
          <View style={styles.grid}>
            <SettingTile icon="calendar-outline" title="Appointments" onPress={() => navigation.navigate('Appointments')} />
            <SettingTile icon="flask-outline" title="Lab results" onPress={() => navigation.navigate('LabResults')} />
            <SettingTile icon="bag-handle-outline" title="Orders" subtitle="Pharmacy" onPress={() => navigation.navigate('Orders')} />
            <SettingTile icon="receipt-outline" title="Invoices" onPress={() => navigation.navigate('Invoices')} />
          </View>
        </>
      ) : null}

      <AccountTiles navigation={navigation} />

      {editing ? <EditSheet onClose={() => setEditing(false)} /> : null}
    </ScrollView>
  );
}

function EditSheet({ onClose }: { onClose: () => void }) {
  const { user, refreshMe } = useAuth();
  const [name, setName] = useState(user?.name ?? '');
  const [phone, setPhone] = useState(user?.phone ?? '');
  const [saving, setSaving] = useState(false);

  async function save() {
    if (!name.trim()) {
      Alert.alert('Name required', 'Enter your name.');
      return;
    }
    setSaving(true);
    try {
      await api.put('/profile', { name: name.trim(), phone: phone.trim() || null });
      await refreshMe();
      onClose();
    } catch (e) {
      Alert.alert('Could not save', apiErrorMessage(e));
    } finally {
      setSaving(false);
    }
  }

  return (
    <Sheet visible onClose={onClose} title="Edit profile" footer={<PrimaryButton title="Save" onPress={save} loading={saving} />}>
      <Field label="Full name" value={name} onChangeText={setName} />
      <Field label="Phone" value={phone} onChangeText={setPhone} keyboardType="phone-pad" />
      <Text style={{ fontFamily: paFonts.regular, fontSize: 12, color: pa.muted }}>Email: {user?.email}</Text>
    </Sheet>
  );
}

function roleLabel(role?: string) {
  return { driver: 'Shuttle driver', third_party: 'Lab / diagnostics partner', pharmacy_admin: 'Pharmacy vendor', doctor: 'Doctor' }[role ?? ''] ?? 'Patient';
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper },
  title: { fontFamily: paFonts.black, fontSize: 22, color: pa.ink, marginBottom: 16 },
  profile: { flexDirection: 'row', alignItems: 'center', gap: 14, marginBottom: 24 },
  photo: { width: 56, height: 56, borderRadius: 28 },
  name: { fontFamily: paFonts.black, fontSize: 17, color: pa.ink },
  sub: { fontFamily: paFonts.regular, fontSize: 13, color: pa.muted, marginTop: 2 },
  edit: { width: 38, height: 38, borderRadius: 12, backgroundColor: '#EAF1F8', alignItems: 'center', justifyContent: 'center' },
  grid: { flexDirection: 'row', flexWrap: 'wrap', gap: 10, marginBottom: 24 },
});
