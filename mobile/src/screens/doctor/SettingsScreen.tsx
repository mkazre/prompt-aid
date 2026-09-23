import React from 'react';
import { Alert, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useAuth } from '../../context/AuthContext';
import { Avatar } from '../../components/UI';
import { colors, font, spacing, radius } from '../../theme';

export default function DoctorSettingsScreen() {
  const { user, logout } = useAuth();

  function comingSoon(feature: string) {
    Alert.alert(feature, 'This will be available soon.');
  }

  return (
    <ScrollView style={styles.container} contentContainerStyle={{ padding: spacing.lg, paddingBottom: spacing.xxl }}>
      <Text style={styles.title}>SETTINGS</Text>

      <View style={styles.profileRow}>
        <Avatar name={user?.name} seed={user?.id} size={56} />
        <View style={{ flex: 1 }}>
          <Text style={styles.name}>{user?.name}</Text>
          <Text style={styles.phone}>{user?.phone ?? 'No phone on file'}</Text>
        </View>
        <Pressable onPress={() => comingSoon('Edit profile')}>
          <Text style={{ fontSize: 18 }}>✏️</Text>
        </Pressable>
      </View>

      <Text style={styles.sectionLabel}>GENERAL SETTING</Text>
      <View style={styles.grid}>
        <SettingCard icon="⚙️" title="Services" subtitle="Clinic Services" onPress={() => comingSoon('Clinic Services')} />
        <SettingCard icon="☂️" title="Holiday" subtitle="Clinic Holiday" onPress={() => comingSoon('Clinic Holiday')} />
        <SettingCard icon="🕐" title="Sessions" subtitle="Clinic Sessions" onPress={() => comingSoon('Clinic Sessions')} />
      </View>

      <Text style={styles.sectionLabel}>APP SETTINGS</Text>
      <View style={styles.grid}>
        <SettingCard icon="🔒" title="Change Password" onPress={() => comingSoon('Change Password')} />
        <SettingCard icon="📄" title="T&C" subtitle="Clinic Policies" onPress={() => comingSoon('Terms & Conditions')} />
        <SettingCard icon="ℹ️" title="About Us" subtitle="About Clinic" onPress={() => comingSoon('About Us')} />
        <SettingCard icon="↪️" title="Logout" onPress={logout} danger />
        <SettingCard icon="📱" title="App Version" subtitle="1.0.0" onPress={() => {}} />
      </View>
    </ScrollView>
  );
}

function SettingCard({ icon, title, subtitle, onPress, danger }: { icon: string; title: string; subtitle?: string; onPress: () => void; danger?: boolean }) {
  return (
    <Pressable onPress={onPress} style={({ pressed }) => [styles.card, { opacity: pressed ? 0.7 : 1 }]}>
      <Text style={[styles.cardTitle, danger && { color: colors.danger }]}>{title}</Text>
      {subtitle ? <Text style={styles.cardSubtitle}>{subtitle}</Text> : null}
      <Text style={styles.cardIcon}>{icon}</Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: colors.gray50 },
  title: { fontFamily: font.semibold, fontSize: 13, color: colors.gray500, textAlign: 'center', letterSpacing: 1, marginBottom: spacing.lg },
  profileRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.md, marginBottom: spacing.lg, paddingBottom: spacing.lg, borderBottomWidth: 1, borderBottomColor: colors.gray200 },
  name: { fontFamily: font.bold, fontSize: 18, color: colors.gray900 },
  phone: { fontFamily: font.regular, fontSize: 13, color: colors.gray500, marginTop: 2 },
  sectionLabel: { fontFamily: font.semibold, fontSize: 11, color: colors.danger, letterSpacing: 0.5, marginBottom: spacing.sm, marginTop: spacing.sm },
  grid: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.md, marginBottom: spacing.lg },
  card: {
    width: '46%',
    backgroundColor: colors.white,
    borderRadius: radius.lg,
    borderWidth: 1,
    borderColor: colors.gray200,
    padding: spacing.md,
    minHeight: 90,
    justifyContent: 'space-between',
  },
  cardTitle: { fontFamily: font.semibold, fontSize: 14, color: colors.gray900 },
  cardSubtitle: { fontFamily: font.regular, fontSize: 11, color: colors.gray500, marginTop: 2 },
  cardIcon: { fontSize: 18, alignSelf: 'flex-end', marginTop: spacing.sm },
});
