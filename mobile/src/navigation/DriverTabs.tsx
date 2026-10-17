import React from 'react';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import DriverHomeScreen from '../screens/driver/HomeScreen';
import ActiveRideScreen from '../screens/driver/ActiveRideScreen';
import OffersScreen from '../screens/driver/OffersScreen';
import TripOfferScreen from '../screens/driver/TripOfferScreen';
import TripsScreen from '../screens/driver/TripsScreen';
import TripDetailScreen from '../screens/driver/TripDetailScreen';
import RideRecordingsScreen from '../screens/safety/RideRecordingsScreen';
import EarningsScreen from '../screens/driver/EarningsScreen';
import DocumentsScreen from '../screens/driver/DocumentsScreen';
import ChangePasswordScreen from '../screens/ChangePasswordScreen';
import AppPageScreen from '../screens/AppPageScreen';
import { pa, paFonts } from '../theme';
import { tabBarScreenOptions, tabIcon } from './tabBarTheme';

const Tab = createBottomTabNavigator();
const DriveStack = createNativeStackNavigator();
const OffersStack = createNativeStackNavigator();
const TripsStack = createNativeStackNavigator();
const ProfileStack = createNativeStackNavigator();

const stackHeaderOptions = { headerTintColor: pa.ink, headerTitleStyle: { fontFamily: paFonts.bold }, headerStyle: { backgroundColor: pa.surface } };

function DriveStackNavigator() {
  return (
    <DriveStack.Navigator screenOptions={stackHeaderOptions}>
      <DriveStack.Screen name="DriveHome" component={DriverHomeScreen} options={{ headerShown: false }} />
      <DriveStack.Screen name="Trip" component={ActiveRideScreen} options={{ title: 'Active trip' }} />
      <DriveStack.Screen name="RideRecordings" component={RideRecordingsScreen} options={{ title: 'Trip recordings' }} />
    </DriveStack.Navigator>
  );
}

function OffersStackNavigator() {
  return (
    <OffersStack.Navigator screenOptions={stackHeaderOptions}>
      <OffersStack.Screen name="OffersList" component={OffersScreen} options={{ headerShown: false }} />
      <OffersStack.Screen name="OfferDetail" component={TripOfferScreen} options={{ title: 'Trip offer', headerStyle: { backgroundColor: '#101012' }, headerTintColor: '#fff' }} />
    </OffersStack.Navigator>
  );
}

function TripsStackNavigator() {
  return (
    <TripsStack.Navigator screenOptions={stackHeaderOptions}>
      <TripsStack.Screen name="TripsList" component={TripsScreen} options={{ headerShown: false }} />
      <TripsStack.Screen name="TripDetail" component={TripDetailScreen} options={{ title: 'Trip' }} />
      <TripsStack.Screen name="RideRecordings" component={RideRecordingsScreen} options={{ title: 'Trip recordings' }} />
    </TripsStack.Navigator>
  );
}

function ProfileStackNavigator() {
  return (
    <ProfileStack.Navigator screenOptions={stackHeaderOptions}>
      <ProfileStack.Screen name="DriverProfileHome" component={DocumentsScreen} options={{ headerShown: false }} />
      <ProfileStack.Screen name="ChangePassword" component={ChangePasswordScreen} options={{ title: 'Change password' }} />
      <ProfileStack.Screen name="AppPage" component={AppPageScreen} options={{ title: '' }} />
    </ProfileStack.Navigator>
  );
}

export default function DriverTabs() {
  return (
    <Tab.Navigator
      screenOptions={tabBarScreenOptions}
    >
      <Tab.Screen name="Drive" component={DriveStackNavigator} options={{ tabBarIcon: tabIcon('car-outline') }} />
      <Tab.Screen name="Offers" component={OffersStackNavigator} options={{ tabBarIcon: tabIcon('megaphone-outline') }} />
      <Tab.Screen name="Trips" component={TripsStackNavigator} options={{ tabBarIcon: tabIcon('navigate-outline') }} />
      <Tab.Screen name="Earnings" component={EarningsScreen} options={{ tabBarIcon: tabIcon('wallet-outline') }} />
      <Tab.Screen name="Profile" component={ProfileStackNavigator} options={{ tabBarIcon: tabIcon('person-circle-outline'), title: 'Profile' }} />
    </Tab.Navigator>
  );
}
