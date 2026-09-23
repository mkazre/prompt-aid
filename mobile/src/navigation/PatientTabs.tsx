import React from 'react';
import { Text } from 'react-native';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import HomeScreen from '../screens/patient/HomeScreen';
import FindCareScreen from '../screens/patient/FindCareScreen';
import DoctorDetailScreen from '../screens/patient/DoctorDetailScreen';
import AppointmentsScreen from '../screens/patient/AppointmentsScreen';
import ShuttleRequestScreen from '../screens/patient/ShuttleRequestScreen';
import RideTrackScreen from '../screens/patient/RideTrackScreen';
import RideSeriesScreen from '../screens/patient/RideSeriesScreen';
import ShopScreen from '../screens/patient/ShopScreen';
import ProductDetailScreen from '../screens/patient/ProductDetailScreen';
import CheckoutScreen from '../screens/patient/CheckoutScreen';
import ScriptUploadScreen from '../screens/patient/ScriptUploadScreen';
import RecordScreen from '../screens/patient/RecordScreen';
import LabResultsScreen from '../screens/patient/LabResultsScreen';
import OrdersScreen from '../screens/patient/OrdersScreen';
import InvoicesScreen from '../screens/patient/InvoicesScreen';
import TriageStartScreen from '../screens/patient/TriageStartScreen';
import TriageFlagsScreen from '../screens/patient/TriageFlagsScreen';
import TriageResultScreen from '../screens/patient/TriageResultScreen';
import ConsultRoomScreen from '../screens/ConsultRoomScreen';
import ProfileScreen from '../screens/ProfileScreen';
import { pa, paFonts } from '../theme';

const Tab = createBottomTabNavigator();
const HomeStack = createNativeStackNavigator();
const CareStack = createNativeStackNavigator();
const ShuttleStack = createNativeStackNavigator();
const PharmacyStack = createNativeStackNavigator();
const RecordStack = createNativeStackNavigator();

const stackHeaderOptions = { headerTintColor: pa.ink, headerTitleStyle: { fontFamily: paFonts.bold } };

function HomeStackNavigator() {
  return (
    <HomeStack.Navigator screenOptions={stackHeaderOptions}>
      <HomeStack.Screen name="PatientHome" component={HomeScreen} options={{ headerShown: false }} />
      {/* Screens below are typed against their own TriageStackParamList, which
          TypeScript can't reconcile with this generically-typed HomeStack —
          casting is safe here since navigation always passes the params
          these screens require (see TriageStartScreen/HomeScreen callers). */}
      <HomeStack.Screen name="TriageStart" component={TriageStartScreen as any} options={{ title: 'Emergency triage' }} />
      <HomeStack.Screen name="TriageFlags" component={TriageFlagsScreen as any} options={{ title: 'Emergency triage' }} />
      <HomeStack.Screen name="TriageResult" component={TriageResultScreen as any} options={{ title: 'Your result', headerBackVisible: false }} />
      <HomeStack.Screen name="ConsultRoom" component={ConsultRoomScreen} options={{ headerShown: false }} />
    </HomeStack.Navigator>
  );
}

function CareStackNavigator() {
  return (
    <CareStack.Navigator screenOptions={stackHeaderOptions}>
      <CareStack.Screen name="FindCare" component={FindCareScreen} options={{ headerShown: false }} />
      <CareStack.Screen name="DoctorDetail" component={DoctorDetailScreen} options={{ title: 'Book' }} />
    </CareStack.Navigator>
  );
}

function ShuttleStackNavigator() {
  return (
    <ShuttleStack.Navigator screenOptions={stackHeaderOptions}>
      <ShuttleStack.Screen name="ShuttleRequest" component={ShuttleRequestScreen} options={{ headerShown: false }} />
      <ShuttleStack.Screen name="RideTrack" component={RideTrackScreen} options={{ title: 'Tracking your shuttle' }} />
      <ShuttleStack.Screen name="RideSeries" component={RideSeriesScreen} options={{ title: 'Recurring trips' }} />
    </ShuttleStack.Navigator>
  );
}

function PharmacyStackNavigator() {
  return (
    <PharmacyStack.Navigator screenOptions={stackHeaderOptions}>
      <PharmacyStack.Screen name="Shop" component={ShopScreen} options={{ headerShown: false }} />
      <PharmacyStack.Screen name="ProductDetail" component={ProductDetailScreen} options={{ title: 'Product' }} />
      <PharmacyStack.Screen name="Checkout" component={CheckoutScreen} options={{ title: 'Checkout' }} />
      <PharmacyStack.Screen name="ScriptUpload" component={ScriptUploadScreen} options={{ title: 'Upload a script' }} />
    </PharmacyStack.Navigator>
  );
}

function RecordStackNavigator() {
  return (
    <RecordStack.Navigator screenOptions={stackHeaderOptions}>
      <RecordStack.Screen name="RecordHome" component={RecordScreen} options={{ headerShown: false }} />
      <RecordStack.Screen name="Appointments" component={AppointmentsScreen} options={{ title: 'Appointments' }} />
      <RecordStack.Screen name="LabResults" component={LabResultsScreen} options={{ title: 'Lab Results' }} />
      <RecordStack.Screen name="Orders" component={OrdersScreen} options={{ title: 'My Orders' }} />
      <RecordStack.Screen name="Invoices" component={InvoicesScreen} options={{ title: 'Invoices' }} />
      <RecordStack.Screen name="ProfileHome" component={ProfileScreen} options={{ title: 'Profile' }} />
    </RecordStack.Navigator>
  );
}

function Icon({ label, focused }: { label: string; focused: boolean }) {
  return <Text style={{ fontSize: 20, opacity: focused ? 1 : 0.5 }}>{label}</Text>;
}

export default function PatientTabs() {
  return (
    <Tab.Navigator
      screenOptions={{
        headerShown: false,
        tabBarActiveTintColor: pa.signal,
        tabBarInactiveTintColor: pa.muted,
        tabBarStyle: { backgroundColor: pa.surface, borderTopColor: pa.line },
        tabBarLabelStyle: { fontFamily: paFonts.bold, fontSize: 11 },
      }}
    >
      <Tab.Screen name="Home" component={HomeStackNavigator} options={{ tabBarIcon: ({ focused }) => <Icon label="🏠" focused={focused} /> }} />
      <Tab.Screen name="Care" component={CareStackNavigator} options={{ tabBarIcon: ({ focused }) => <Icon label="🩺" focused={focused} /> }} />
      <Tab.Screen name="Rides" component={ShuttleStackNavigator} options={{ tabBarIcon: ({ focused }) => <Icon label="🚐" focused={focused} />, title: 'Shuttle' }} />
      <Tab.Screen name="Pharmacy" component={PharmacyStackNavigator} options={{ tabBarIcon: ({ focused }) => <Icon label="💊" focused={focused} /> }} />
      <Tab.Screen name="Profile" component={RecordStackNavigator} options={{ tabBarIcon: ({ focused }) => <Icon label="📋" focused={focused} />, title: 'Record' }} />
    </Tab.Navigator>
  );
}
