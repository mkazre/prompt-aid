import React from 'react';
import { Alert, View } from 'react-native';
import { useAuth } from '../context/AuthContext';
import { appVersion } from '../services/app';
import { SectionTitle, SettingTile } from './settings-ui';

/**
 * "App settings" tile grid shared by every role's profile screen. The
 * navigator hosting the screen must register `ChangePassword` and `AppPage`.
 */
export default function AccountTiles({ navigation, extra }: { navigation: any; extra?: React.ReactNode }) {
  const { logout } = useAuth();

  function confirmLogout() {
    Alert.alert('Log out', 'Are you sure you want to log out?', [
      { text: 'Cancel', style: 'cancel' },
      { text: 'Log out', style: 'destructive', onPress: logout },
    ]);
  }

  return (
    <View>
      <SectionTitle>App settings</SectionTitle>
      <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 10, marginBottom: 24 }}>
        {extra}
        <SettingTile icon="lock-closed-outline" title="Change password" onPress={() => navigation.navigate('ChangePassword')} />
        <SettingTile icon="document-text-outline" title="T&C" subtitle="Terms & policies" onPress={() => navigation.navigate('AppPage', { key: 'terms' })} />
        <SettingTile icon="information-circle-outline" title="About us" subtitle="About Prompt Aid" onPress={() => navigation.navigate('AppPage', { key: 'about' })} />
        <SettingTile icon="log-out-outline" title="Logout" onPress={confirmLogout} danger />
        <SettingTile icon="phone-portrait-outline" title="App version" subtitle={`v${appVersion()}`} />
      </View>
    </View>
  );
}
