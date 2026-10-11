import React from 'react';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import ProfileScreen from '../screens/ProfileScreen';
import ChangePasswordScreen from '../screens/ChangePasswordScreen';
import AppPageScreen from '../screens/AppPageScreen';
import { pa, paFonts } from '../theme';

const Stack = createNativeStackNavigator();

/** Profile tab for roles without their own settings hub (pharmacy vendor, lab partner). */
export default function AccountStackNavigator() {
  return (
    <Stack.Navigator screenOptions={{ headerTintColor: pa.ink, headerTitleStyle: { fontFamily: paFonts.bold }, headerShadowVisible: false }}>
      <Stack.Screen name="AccountHome" component={ProfileScreen} options={{ headerShown: false }} />
      <Stack.Screen name="ChangePassword" component={ChangePasswordScreen} options={{ title: 'Change password' }} />
      <Stack.Screen name="AppPage" component={AppPageScreen} options={{ title: '' }} />
    </Stack.Navigator>
  );
}
