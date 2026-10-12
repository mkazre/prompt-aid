import React from 'react';
import { Alert, Image, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useAuth } from '../../context/AuthContext';
import { PaAvatar } from '../../components/pa';
import { SectionTitle, SettingTile, SoftCard } from '../../components/settings-ui';
import { appVersion, avatarUrl } from '../../services/app';
import { pa, paFonts } from '../../theme';

/**
 * Doctor settings hub: every tile opens a real screen backed by the API
 * (services, holidays, weekly sessions, password, CMS pages, profile).
 */
export default function DoctorSettingsScreen({ navigation }: any) {
  const insets = useSafeAreaInsets();
  const { user, logout } = useAuth();
  const photo = avatarUrl(user?.avatar);

  function confirmLogout() {
    Alert.alert('Log out', 'Are you sure you want to log out?', [
      { text: 'Cancel', style: 'cancel' },
      { text: 'Log out', style: 'destructive', onPress: logout },
    ]);
  }

  return (
    <ScrollView style={styles.container} contentContainerStyle={{ padding: 16, paddingTop: insets.top + 12, paddingBottom: 48 }}>
      <Text style={styles.title}>Settings</Text>

      <SoftCard style={styles.profile}>
        {photo ? <Image source={{ uri: photo }} style={styles.photo} /> : <PaAvatar name={user?.name} size={56} />}
        <View style={{ flex: 1 }}>
          <Text style={styles.name} numberOfLines={1}>{user?.name}</Text>
          <Text style={styles.sub} numberOfLines={1}>{user?.phone ?? 'No phone on file'}</Text>
          {user?.doctor_profile?.specialization ? <Text style={styles.sub} numberOfLines={1}>{user.doctor_profile.specialization}</Text> : null}
        </View>
        <Pressable onPress={() => navigation.navigate('DoctorEditProfile')} hitSlop={12} style={styles.edit} accessibilityLabel="Edit profile">
          <Ionicons name="create-outline" size={20} color={pa.info} />
        </Pressable>
      </SoftCard>

      <SectionTitle>General setting</SectionTitle>
      <View style={styles.grid}>
        <SettingTile icon="medkit-outline" title="Services" subtitle="Services & fees" onPress={() => navigation.navigate('DoctorServices')} />
        <SettingTile icon="umbrella-outline" title="Holiday" subtitle="Time off" onPress={() => navigation.navigate('DoctorHoliday')} />
        <SettingTile icon="time-outline" title="Sessions" subtitle="Weekly hours" onPress={() => navigation.navigate('DoctorSessions')} />
      </View>

      <SectionTitle>App settings</SectionTitle>
      <View style={styles.grid}>
        <SettingTile icon="help-buoy-outline" title="Help & support" subtitle="FAQs & tickets" onPress={() => navigation.navigate('Support')} />
        <SettingTile icon="lock-closed-outline" title="Change password" onPress={() => navigation.navigate('ChangePassword')} />
        <SettingTile icon="document-text-outline" title="T&C" subtitle="Terms & policies" onPress={() => navigation.navigate('AppPage', { key: 'terms' })} />
        <SettingTile icon="information-circle-outline" title="About us" subtitle="About Prompt Aid" onPress={() => navigation.navigate('AppPage', { key: 'about' })} />
        <SettingTile icon="log-out-outline" title="Logout" onPress={confirmLogout} danger />
        <SettingTile icon="phone-portrait-outline" title="App version" subtitle={`v${appVersion()}`} />
      </View>
    </ScrollView>
  );
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
