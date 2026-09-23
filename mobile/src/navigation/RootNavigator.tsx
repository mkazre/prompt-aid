import React from 'react';
import { ActivityIndicator, View } from 'react-native';
import { NavigationContainer } from '@react-navigation/native';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { useAuth } from '../context/AuthContext';
import OnboardingScreen from '../screens/OnboardingScreen';
import LoginScreen from '../screens/LoginScreen';
import RegisterScreen from '../screens/RegisterScreen';
import PatientTabs from './PatientTabs';
import DriverTabs from './DriverTabs';
import ThirdPartyTabs from './ThirdPartyTabs';
import DoctorTabs from './DoctorTabs';
import PharmacyTabs from './PharmacyTabs';
import { colors, pa } from '../theme';

const Stack = createNativeStackNavigator();

function AuthStack() {
  return (
    <Stack.Navigator screenOptions={{ headerShown: false }}>
      <Stack.Screen name="Onboarding" component={OnboardingScreen} />
      <Stack.Screen name="Login" component={LoginScreen} />
      <Stack.Screen name="Register" component={RegisterScreen} />
    </Stack.Navigator>
  );
}

export default function RootNavigator() {
  const { user, loading } = useAuth();

  if (loading) {
    return (
      <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: pa.paper }}>
        <ActivityIndicator color={pa.signal} size="large" />
      </View>
    );
  }

  return (
    <NavigationContainer>
      {!user ? (
        <AuthStack />
      ) : user.role === 'driver' ? (
        <DriverTabs />
      ) : user.role === 'third_party' ? (
        <ThirdPartyTabs />
      ) : user.role === 'doctor' ? (
        <DoctorTabs />
      ) : user.role === 'pharmacy_admin' ? (
        <PharmacyTabs />
      ) : (
        <PatientTabs />
      )}
    </NavigationContainer>
  );
}
