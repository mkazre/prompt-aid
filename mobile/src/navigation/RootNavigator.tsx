import React from 'react';
import { ActivityIndicator, View } from 'react-native';
import { NavigationContainer } from '@react-navigation/native';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { useAuth } from '../context/AuthContext';
import OnboardingScreen from '../screens/OnboardingScreen';
import LoginScreen from '../screens/LoginScreen';
import RegisterScreen from '../screens/RegisterScreen';
import RegisterMedicalScreen from '../screens/RegisterMedicalScreen';
import RegisterSchemeScreen from '../screens/RegisterSchemeScreen';
import RegisterDocumentScreen from '../screens/RegisterDocumentScreen';
import PatientTabs from './PatientTabs';
import DriverTabs from './DriverTabs';
import ThirdPartyTabs from './ThirdPartyTabs';
import DoctorTabs from './DoctorTabs';
import PharmacyTabs from './PharmacyTabs';
import NotificationsScreen from '../screens/comms/NotificationsScreen';
import ChatListScreen from '../screens/comms/ChatListScreen';
import ChatScreen from '../screens/comms/ChatScreen';
import VideoCallScreen from '../screens/comms/VideoCallScreen';
import { colors, pa } from '../theme';

const Stack = createNativeStackNavigator();
const AppStack = createNativeStackNavigator();

function AuthStack() {
  return (
    <Stack.Navigator screenOptions={{ headerShown: false }}>
      <Stack.Screen name="Onboarding" component={OnboardingScreen} />
      <Stack.Screen name="Login" component={LoginScreen} />
      <Stack.Screen name="Register" component={RegisterScreen} />
      <Stack.Screen name="RegisterMedical" component={RegisterMedicalScreen} />
      <Stack.Screen name="RegisterScheme" component={RegisterSchemeScreen} />
      <Stack.Screen name="RegisterDocument" component={RegisterDocumentScreen} />
    </Stack.Navigator>
  );
}

/**
 * Signed-in shell: the role's tab navigator ("Main") plus screens every
 * role can reach from anywhere — notifications, chat and the in-app video
 * call. `navigation.navigate('Chat' | 'ChatList' | 'Notifications' |
 * 'VideoCall', ...)` from any nested tab screen bubbles up to here.
 */
function SignedInStack({ Tabs }: { Tabs: React.ComponentType<any> }) {
  return (
    <AppStack.Navigator screenOptions={{ headerShown: false }}>
      <AppStack.Screen name="Main" component={Tabs} />
      <AppStack.Screen name="Notifications" component={NotificationsScreen} />
      <AppStack.Screen name="ChatList" component={ChatListScreen} />
      <AppStack.Screen name="Chat" component={ChatScreen} />
      <AppStack.Screen name="VideoCall" component={VideoCallScreen} options={{ presentation: 'fullScreenModal', animation: 'fade', gestureEnabled: false }} />
    </AppStack.Navigator>
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

  const Tabs =
    user?.role === 'driver'
      ? DriverTabs
      : user?.role === 'third_party'
        ? ThirdPartyTabs
        : user?.role === 'doctor'
          ? DoctorTabs
          : user?.role === 'pharmacy_admin'
            ? PharmacyTabs
            : PatientTabs;

  return (
    <NavigationContainer>
      {!user ? <AuthStack /> : <SignedInStack key={user.role} Tabs={Tabs} />}
    </NavigationContainer>
  );
}
