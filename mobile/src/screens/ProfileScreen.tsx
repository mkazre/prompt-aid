import React from 'react';
import { Image, Pressable, StyleSheet, Text, View } from 'react-native';
import { useAuth } from '../context/AuthContext';
import { Card, PrimaryButton } from '../components/UI';
import { colors, font, spacing } from '../theme';

export default function ProfileScreen({ navigation }: any) {
  const { user, logout } = useAuth();
  const isPatient = user?.role === 'patient';

  return (
    <View style={styles.container}>
      <View style={styles.header}>
        <Image source={require('../../assets/icon.png')} style={styles.avatar} />
        <Text style={styles.name}>{user?.name}</Text>
        <Text style={styles.role}>{roleLabel(user?.role)}</Text>
      </View>

      <Card style={{ marginTop: spacing.lg }}>
        <Row label="Email" value={user?.email} />
        <Row label="Phone" value={user?.phone ?? '—'} />
        <Row label="Status" value={user?.status} />
      </Card>

      {isPatient && (
        <Card style={{ marginTop: spacing.md, padding: 0 }}>
          <MenuItem icon="🧪" label="My Lab & Diagnostic Results" onPress={() => navigation.navigate('LabResults')} />
          <MenuItem icon="💊" label="My Pharmacy Orders" onPress={() => navigation.navigate('Orders')} last />
        </Card>
      )}

      <View style={{ marginTop: spacing.lg }}>
        <PrimaryButton title="Sign out" variant="danger" onPress={logout} />
      </View>
    </View>
  );
}

function MenuItem({ icon, label, onPress, last }: { icon: string; label: string; onPress: () => void; last?: boolean }) {
  return (
    <Pressable onPress={onPress} style={[styles.menuItem, !last && styles.menuItemBorder]}>
      <Text style={styles.menuIcon}>{icon}</Text>
      <Text style={styles.menuLabel}>{label}</Text>
      <Text style={styles.menuChevron}>›</Text>
    </Pressable>
  );
}

function Row({ label, value }: { label: string; value?: string | null }) {
  return (
    <View style={styles.row}>
      <Text style={styles.rowLabel}>{label}</Text>
      <Text style={styles.rowValue}>{value}</Text>
    </View>
  );
}

function roleLabel(role?: string) {
  return { driver: 'Shuttle Driver', third_party: 'Lab / Diagnostics Partner', pharmacy_admin: 'Pharmacy Vendor' }[role ?? ''] ?? 'Patient';
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: colors.gray50, padding: spacing.lg },
  header: { alignItems: 'center', marginTop: spacing.lg },
  avatar: { width: 84, height: 84, borderRadius: 42 },
  name: { fontFamily: font.bold, fontSize: 20, color: colors.secondary, marginTop: spacing.sm },
  role: { fontFamily: font.medium, fontSize: 13, color: colors.accent, marginTop: 2 },
  row: { flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 10, borderBottomWidth: 1, borderBottomColor: colors.gray100 },
  rowLabel: { fontFamily: font.regular, color: colors.gray600 },
  rowValue: { fontFamily: font.medium, color: colors.gray900 },
  menuItem: { flexDirection: 'row', alignItems: 'center', padding: spacing.md },
  menuItemBorder: { borderBottomWidth: 1, borderBottomColor: colors.gray100 },
  menuIcon: { fontSize: 18, marginRight: spacing.sm },
  menuLabel: { flex: 1, fontFamily: font.medium, fontSize: 14, color: colors.gray800 },
  menuChevron: { fontSize: 18, color: colors.gray400 },
});
