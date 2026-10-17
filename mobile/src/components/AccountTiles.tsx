import React from 'react';
import { Alert, Pressable, StyleSheet, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { useAuth } from '../context/AuthContext';
import { appVersion } from '../services/app';
import { SectionTitle, SettingTile } from './settings-ui';
import { useAppLock } from '../context/AppLock';
import { pa, paFonts } from '../theme';

/**
 * "Login & security" tile — App lock + login activity (SecurityScreen,
 * registered on the signed-in root stack so every role can open it).
 */
export function SecurityTile({ navigation }: { navigation: any }) {
  const { enabled } = useAppLock();
  return (
    <SettingTile
      icon="shield-checkmark-outline"
      title="Login & security"
      subtitle={`App lock ${enabled ? 'on' : 'off'} · Devices`}
      onPress={() => navigation.navigate('Security')}
    />
  );
}

/**
 * Full-width "Log out" button (outlined red) with a confirm dialog. Sits at
 * the very bottom of every role's profile / settings landing screen.
 */
export function LogoutButton({ style }: { style?: object }) {
  const { logout } = useAuth();

  function confirmLogout() {
    Alert.alert('Log out', 'Are you sure you want to log out?', [
      { text: 'Cancel', style: 'cancel' },
      { text: 'Log out', style: 'destructive', onPress: () => { logout(); } },
    ]);
  }

  return (
    <Pressable
      onPress={confirmLogout}
      accessibilityRole="button"
      accessibilityLabel="Log out"
      style={({ pressed }) => [ls.btn, pressed && { backgroundColor: pa.signalWash }, style]}
    >
      <Ionicons name="log-out-outline" size={20} color={pa.signal} />
      <Text style={ls.label}>Log out</Text>
    </Pressable>
  );
}

const ls = StyleSheet.create({
  btn: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    minHeight: 52,
    paddingHorizontal: 16,
    paddingVertical: 12,
    borderRadius: 14,
    borderWidth: 1.5,
    borderColor: pa.signal,
    backgroundColor: pa.surface,
    marginTop: 8,
  },
  label: { fontFamily: paFonts.bold, fontSize: 16, lineHeight: 20, color: pa.signal, includeFontPadding: false },
});

/**
 * "App settings" tile grid shared by every role's profile screen, followed by
 * the full-width Log out button (so it always ends the screen). The navigator
 * hosting the screen must register `ChangePassword` and `AppPage`.
 */
export default function AccountTiles({ navigation, extra }: { navigation: any; extra?: React.ReactNode }) {
  return (
    <View>
      <SectionTitle>App settings</SectionTitle>
      <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 10, marginBottom: 24 }}>
        {extra}
        <SecurityTile navigation={navigation} />
        <SettingTile icon="help-buoy-outline" title="Help & support" subtitle="FAQs & tickets" onPress={() => navigation.navigate('Support')} />
        <SettingTile icon="lock-closed-outline" title="Change password" onPress={() => navigation.navigate('ChangePassword')} />
        <SettingTile icon="document-text-outline" title="T&C" subtitle="Terms & policies" onPress={() => navigation.navigate('AppPage', { key: 'terms' })} />
        <SettingTile icon="information-circle-outline" title="About us" subtitle="About Prompt Aid" onPress={() => navigation.navigate('AppPage', { key: 'about' })} />
        <SettingTile icon="phone-portrait-outline" title="App version" subtitle={`v${appVersion()}`} />
      </View>
      <LogoutButton />
    </View>
  );
}
