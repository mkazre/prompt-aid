import React from 'react';
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
import ReferralsScreen from '../screens/patient/ReferralsScreen';
import TriageStartScreen from '../screens/patient/TriageStartScreen';
import TriageFlagsScreen from '../screens/patient/TriageFlagsScreen';
import TriageResultScreen from '../screens/patient/TriageResultScreen';
import ConsultRoomScreen from '../screens/ConsultRoomScreen';
import InstantConsultScreen from '../screens/patient/InstantConsultScreen';
import ProfileScreen from '../screens/ProfileScreen';
import HealthArticlesScreen from '../screens/patient/HealthArticlesScreen';
import HealthArticleDetailScreen from '../screens/patient/HealthArticleDetailScreen';
import SearchScreen from '../screens/patient/SearchScreen';
import ChangePasswordScreen from '../screens/ChangePasswordScreen';
import AppPageScreen from '../screens/AppPageScreen';
import { pa, paFonts } from '../theme';
import { tabBarScreenOptions, tabIcon } from './tabBarTheme';
import { featureOn, useSiteContent } from '../services/siteContent';

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
      <HomeStack.Screen name="InstantConsult" component={InstantConsultScreen} options={{ title: 'See a doctor now' }} />
      <HomeStack.Screen name="HealthArticles" component={HealthArticlesScreen} options={{ title: 'Health articles' }} />
      <HomeStack.Screen name="HealthArticleDetail" component={HealthArticleDetailScreen} options={{ title: '' }} />
      <HomeStack.Screen name="Search" component={SearchScreen} options={{ headerShown: false, animation: 'fade' }} />
    </HomeStack.Navigator>
  );
}

function CareStackNavigator() {
  return (
    <CareStack.Navigator screenOptions={stackHeaderOptions}>
      <CareStack.Screen name="FindCare" component={FindCareScreen} options={{ headerShown: false }} />
      <CareStack.Screen name="DoctorDetail" component={DoctorDetailScreen} options={{ title: 'Book appointment' }} />
      <CareStack.Screen name="Search" component={SearchScreen} options={{ headerShown: false, animation: 'fade' }} />
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
      <RecordStack.Screen name="Referrals" component={ReferralsScreen} options={{ title: 'Invite friends' }} />
      <RecordStack.Screen name="ProfileHome" component={ProfileScreen} options={{ title: 'Profile' }} />
      <RecordStack.Screen name="ChangePassword" component={ChangePasswordScreen} options={{ title: 'Change password' }} />
      <RecordStack.Screen name="AppPage" component={AppPageScreen} options={{ title: '' }} />
    </RecordStack.Navigator>
  );
}

export default function PatientTabs() {
  // Feature switches (Staff → System → Features): switched-off services lose their tab.
  // Fails open — if the flags don't load, every tab shows.
  const content = useSiteContent();
  const shuttleOn = featureOn(content, 'shuttle');
  const shopOn = featureOn(content, 'shop');

  return (
    <Tab.Navigator
      screenOptions={tabBarScreenOptions}
    >
      <Tab.Screen name="Home" component={HomeStackNavigator} options={{ tabBarIcon: tabIcon('home-outline') }} />
      <Tab.Screen name="Care" component={CareStackNavigator} options={{ tabBarIcon: tabIcon('medkit-outline') }} />
      {shuttleOn ? <Tab.Screen name="Rides" component={ShuttleStackNavigator} options={{ tabBarIcon: tabIcon('bus-outline'), title: 'Shuttle' }} /> : null}
      {shopOn ? <Tab.Screen name="Pharmacy" component={PharmacyStackNavigator} options={{ tabBarIcon: tabIcon('bag-handle-outline') }} /> : null}
      <Tab.Screen name="Profile" component={RecordStackNavigator} options={{ tabBarIcon: tabIcon('document-text-outline'), title: 'Record' }} />
    </Tab.Navigator>
  );
}
