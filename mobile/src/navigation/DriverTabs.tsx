import React from 'react';
import { Text } from 'react-native';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import DriverHomeScreen from '../screens/driver/HomeScreen';
import ActiveRideScreen from '../screens/driver/ActiveRideScreen';
import OffersScreen from '../screens/driver/OffersScreen';
import TripOfferScreen from '../screens/driver/TripOfferScreen';
import TripsScreen from '../screens/driver/TripsScreen';
import TripDetailScreen from '../screens/driver/TripDetailScreen';
import EarningsScreen from '../screens/driver/EarningsScreen';
import DocumentsScreen from '../screens/driver/DocumentsScreen';
import { pa, paFonts } from '../theme';

const Tab = createBottomTabNavigator();
const DriveStack = createNativeStackNavigator();
const OffersStack = createNativeStackNavigator();
const TripsStack = createNativeStackNavigator();

const stackHeaderOptions = { headerTintColor: pa.ink, headerTitleStyle: { fontFamily: paFonts.bold }, headerStyle: { backgroundColor: pa.surface } };

function DriveStackNavigator() {
  return (
    <DriveStack.Navigator screenOptions={stackHeaderOptions}>
      <DriveStack.Screen name="DriveHome" component={DriverHomeScreen} options={{ headerShown: false }} />
      <DriveStack.Screen name="Trip" component={ActiveRideScreen} options={{ title: 'Active trip' }} />
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
    </TripsStack.Navigator>
  );
}

function Icon({ label, focused }: { label: string; focused: boolean }) {
  return <Text style={{ fontSize: 20, opacity: focused ? 1 : 0.5 }}>{label}</Text>;
}

export default function DriverTabs() {
  return (
    <Tab.Navigator
      screenOptions={{
        headerShown: false,
        tabBarActiveTintColor: pa.signal,
        tabBarInactiveTintColor: pa.muted2,
        tabBarLabelStyle: { fontFamily: paFonts.bold, fontSize: 10 },
        tabBarStyle: { backgroundColor: pa.surface, borderTopColor: pa.line },
      }}
    >
      <Tab.Screen name="Drive" component={DriveStackNavigator} options={{ tabBarIcon: ({ focused }) => <Icon label="🚗" focused={focused} /> }} />
      <Tab.Screen name="Offers" component={OffersStackNavigator} options={{ tabBarIcon: ({ focused }) => <Icon label="📣" focused={focused} /> }} />
      <Tab.Screen name="Trips" component={TripsStackNavigator} options={{ tabBarIcon: ({ focused }) => <Icon label="🧭" focused={focused} /> }} />
      <Tab.Screen name="Earnings" component={EarningsScreen} options={{ tabBarIcon: ({ focused }) => <Icon label="💰" focused={focused} /> }} />
      <Tab.Screen name="Profile" component={DocumentsScreen} options={{ tabBarIcon: ({ focused }) => <Icon label="📄" focused={focused} />, title: 'Documents' }} />
    </Tab.Navigator>
  );
}
