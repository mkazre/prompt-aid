import React from 'react';
import { ActivityIndicator, View } from 'react-native';
import { NavigationContainer, createNavigationContainerRef } from '@react-navigation/native';
import { Linking } from 'react-native';
import { useAlerts } from '../services/alerts';
import ProviderDispatchHost from '../components/ProviderDispatchHost';
import PatientCallHost from '../components/PatientCallHost';
import ConsultCardScreen from '../screens/comms/ConsultCardScreen';
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
import SecurityScreen from '../screens/SecurityScreen';
import SupportScreen from '../screens/support/SupportScreen';
import SupportNewScreen from '../screens/support/SupportNewScreen';
import SupportThreadScreen from '../screens/support/SupportThreadScreen';
import HelpCentreScreen from '../screens/support/HelpCentreScreen';
import FeedbackScreen from '../screens/support/FeedbackScreen';
import { colors, pa, paFonts } from '../theme';

const Stack = createNativeStackNavigator();
const navRef = createNavigationContainerRef<any>();
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
const supportHeader = {
  headerShown: true,
  headerTintColor: pa.ink,
  headerStyle: { backgroundColor: pa.surface },
  headerTitleStyle: { fontFamily: paFonts.bold },
  headerShadowVisible: false,
};

function SignedInStack({ Tabs }: { Tabs: React.ComponentType<any> }) {
  return (
    <AppStack.Navigator screenOptions={{ headerShown: false }}>
      <AppStack.Screen name="Main" component={Tabs} />
      <AppStack.Screen name="Notifications" component={NotificationsScreen} />
      <AppStack.Screen name="ChatList" component={ChatListScreen} />
      <AppStack.Screen name="Chat" component={ChatScreen} />
      <AppStack.Screen
        name="Security"
        component={SecurityScreen}
        options={{ headerShown: true, title: 'Login & security', headerTintColor: pa.ink, headerTitleStyle: { fontFamily: paFonts.bold }, headerShadowVisible: false }}
      />
            <AppStack.Screen name="VideoCall" component={VideoCallScreen} options={{ presentation: 'fullScreenModal', animation: 'fade', gestureEnabled: false }} />
      {/* Doctor's call-back consult card (orange triage call back / booked call back). */}
      <AppStack.Screen
        name="ConsultCard"
        component={ConsultCardScreen}
        options={{ headerShown: true, title: 'Call-back consult', headerTintColor: pa.ink, headerTitleStyle: { fontFamily: paFonts.bold }, headerShadowVisible: false }}
      />
      {/* Help & support (every role), Help centre FAQs and the NPS survey. */}
      <AppStack.Group screenOptions={supportHeader}>
        <AppStack.Screen name="Support" component={SupportScreen} options={{ title: 'Help & support' }} />
        <AppStack.Screen name="SupportNew" component={SupportNewScreen} options={{ title: 'New ticket' }} />
        <AppStack.Screen name="SupportThread" component={SupportThreadScreen} options={{ title: 'Ticket' }} />
        <AppStack.Screen name="HelpCentre" component={HelpCentreScreen} options={{ title: 'Help centre' }} />
        <AppStack.Screen name="Feedback" component={FeedbackScreen} options={{ title: 'Your feedback', presentation: 'modal' }} />
      </AppStack.Group>
    </AppStack.Navigator>
  );
}

export default function RootNavigator() {
  const { user, loading } = useAuth();

  // Alerts with sound for every role; tapping one opens the right place.
  useAlerts(!!user, (data) => {
    if (!navRef.isReady()) return;
    // Incoming request offers open the full-screen alert (ProviderDispatchHost), not the feed.
    if (data?.type === 'dispatch_offer' || data?.offer_key) return;
    // "Dr X is calling you — tap to join": straight into the in-app video call.
    const callId = Number(data?.appointment_id ?? data?.params?.appointment_id);
    if (data?.type === 'consult_call' && callId) {
      (navRef.navigate as (n: string, p?: object) => void).call(navRef, 'VideoCall', { appointmentId: callId, name: data?.doctor_name });
      return;
    }
    // On-demand request updates (matched, call back accepted, no doctor) open the request screen.
    if (data?.route === 'see_doctor_now' || data?.type === 'consult_callback') {
      (navRef.navigate as (n: string, p?: object) => void).call(navRef, 'Main', { screen: 'Home', params: { screen: 'InstantConsult' } });
      return;
    }
    if (data?.route === 'ChatList' || data?.type === 'chat_message') navRef.navigate('ChatList');
    else if (data?.route === 'support_ticket' && data?.params?.ticket_id) navRef.navigate('SupportThread', { ticketId: data.params.ticket_id });
    else if (data?.route === 'feedback' && data?.params?.token) navRef.navigate('Feedback', { token: data.params.token });
    else if (typeof data?.url === 'string' && /^https?:\/\//.test(data.url)) Linking.openURL(data.url);
    else navRef.navigate('Notifications');
  });

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
    <NavigationContainer ref={navRef}>
      {!user ? <AuthStack /> : <SignedInStack key={user.role} Tabs={Tabs} />}
      {/* Incoming request alert (swipe/accept/decline) over every screen for provider roles. */}
      {user ? <ProviderDispatchHost role={user.role} navigate={(name, params) => navRef.isReady() && (navRef.navigate as (n: string, p?: object) => void).call(navRef, name, params)} /> : null}
      {/* Patients: "Dr X is calling you" — incoming video consult over every screen, auto-joins in-app. */}
      {user ? (
        <PatientCallHost
          enabled={user.role === 'patient'}
          navigate={(name, params) => navRef.isReady() && (navRef.navigate as (n: string, p?: object) => void).call(navRef, name, params)}
        />
      ) : null}
    </NavigationContainer>
  );
}
