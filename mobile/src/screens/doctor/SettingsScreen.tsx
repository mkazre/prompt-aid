import React, { useState } from 'react';
import { Alert, Modal, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { api, apiErrorMessage } from '../../api/client';
import { useAuth } from '../../context/AuthContext';
import { PaAvatar, PaButton, PaInput, PaSectionLabel } from '../../components/pa';
import { pa, paFonts, paRadius, type } from '../../theme';

export default function DoctorSettingsScreen() {
  const { user, logout } = useAuth();
  const [editing, setEditing] = useState(false);

  function comingSoon(feature: string) {
    Alert.alert(feature, 'This will be available soon.');
  }

  return (
    <ScrollView style={styles.container} contentContainerStyle={{ padding: 16, paddingBottom: 48 }}>
      <PaSectionLabel style={{ textAlign: 'center', marginBottom: 16 }}>Settings</PaSectionLabel>

      <View style={styles.profileRow}>
        <PaAvatar name={user?.name} size={56} />
        <View style={{ flex: 1 }}>
          <Text style={styles.name}>{user?.name}</Text>
          <Text style={styles.phone}>{user?.phone ?? 'No phone on file'}</Text>
          <Text style={styles.phone}>{user?.doctor_profile?.specialization ?? ''}</Text>
        </View>
        <Pressable onPress={() => setEditing(true)} hitSlop={12}>
          <Text style={{ fontSize: 18 }}>✏️</Text>
        </Pressable>
      </View>

      <PaSectionLabel style={{ marginBottom: 10 }}>General</PaSectionLabel>
      <View style={styles.grid}>
        <SettingCard icon="⚙️" title="Services" subtitle="Clinic services" onPress={() => comingSoon('Clinic Services')} />
        <SettingCard icon="☂️" title="Holiday" subtitle="Clinic holiday" onPress={() => comingSoon('Clinic Holiday')} />
        <SettingCard icon="🕐" title="Sessions" subtitle="Clinic sessions" onPress={() => comingSoon('Clinic Sessions')} />
      </View>

      <PaSectionLabel style={{ marginBottom: 10 }}>App</PaSectionLabel>
      <View style={styles.grid}>
        <SettingCard icon="🔒" title="Change password" onPress={() => comingSoon('Change Password')} />
        <SettingCard icon="📄" title="T&C" subtitle="Clinic policies" onPress={() => comingSoon('Terms & Conditions')} />
        <SettingCard icon="ℹ️" title="About us" subtitle="About clinic" onPress={() => comingSoon('About Us')} />
        <SettingCard icon="↪️" title="Logout" onPress={logout} danger />
        <SettingCard icon="📱" title="App version" subtitle="1.0.0" onPress={() => {}} />
      </View>

      <EditProfileModal visible={editing} onClose={() => setEditing(false)} />
    </ScrollView>
  );
}

/** Real edit — PUT /profile (see backend/app/Http/Controllers/Api/ProfileController.php::update, which accepts name/phone for every role). */
function EditProfileModal({ visible, onClose }: { visible: boolean; onClose: () => void }) {
  const { user, refreshMe } = useAuth();
  const [name, setName] = useState(user?.name ?? '');
  const [phone, setPhone] = useState(user?.phone ?? '');
  const [saving, setSaving] = useState(false);

  async function save() {
    setSaving(true);
    try {
      await api.put('/profile', { name, phone: phone || undefined });
      await refreshMe();
      onClose();
    } catch (e) {
      Alert.alert('Could not save', apiErrorMessage(e));
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal visible={visible} animationType="slide" transparent onRequestClose={onClose}>
      <View style={styles.modalScrim}>
        <View style={styles.modalSheet}>
          <Text style={type.h3}>Edit profile</Text>
          <View style={{ height: 16 }} />
          <PaInput label="Name" value={name} onChangeText={setName} />
          <PaInput label="Phone" value={phone ?? ''} onChangeText={setPhone} keyboardType="phone-pad" />
          <View style={{ flexDirection: 'row', gap: 10, marginTop: 4 }}>
            <PaButton title="Cancel" variant="ghost" onPress={onClose} style={{ flex: 1 }} />
            <PaButton title="Save" loading={saving} onPress={save} style={{ flex: 1 }} />
          </View>
        </View>
      </View>
    </Modal>
  );
}

function SettingCard({ icon, title, subtitle, onPress, danger }: { icon: string; title: string; subtitle?: string; onPress: () => void; danger?: boolean }) {
  return (
    <Pressable onPress={onPress} style={({ pressed }) => [styles.card, { opacity: pressed ? 0.7 : 1 }]}>
      <Text style={[styles.cardTitle, danger && { color: pa.signalInk }]}>{title}</Text>
      {subtitle ? <Text style={styles.cardSubtitle}>{subtitle}</Text> : null}
      <Text style={styles.cardIcon}>{icon}</Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper },
  profileRow: { flexDirection: 'row', alignItems: 'center', gap: 12, marginBottom: 20, paddingBottom: 16, borderBottomWidth: 1, borderBottomColor: pa.line },
  name: { fontFamily: paFonts.black, fontSize: 18, color: pa.ink },
  phone: { fontFamily: paFonts.regular, fontSize: 13, color: pa.muted, marginTop: 2 },
  grid: { flexDirection: 'row', flexWrap: 'wrap', gap: 10, marginBottom: 20 },
  card: {
    width: '47%',
    backgroundColor: pa.surface,
    borderRadius: paRadius.sm,
    borderWidth: 1,
    borderColor: pa.line,
    padding: 14,
    minHeight: 90,
    justifyContent: 'space-between',
  },
  cardTitle: { fontFamily: paFonts.bold, fontSize: 14, color: pa.ink },
  cardSubtitle: { fontFamily: paFonts.regular, fontSize: 11, color: pa.muted, marginTop: 2 },
  cardIcon: { fontSize: 18, alignSelf: 'flex-end', marginTop: 8 },
  modalScrim: { flex: 1, backgroundColor: 'rgba(16,16,18,0.55)', justifyContent: 'flex-end' },
  modalSheet: { backgroundColor: pa.surface, borderTopWidth: 1, borderTopColor: pa.ink, padding: 20, paddingBottom: 32 },
});
