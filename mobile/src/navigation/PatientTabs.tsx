import React from 'react';
import { Text } from 'react-native';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import HomeScreen from '../screens/patient/HomeScreen';
import DoctorDetailScreen from '../screens/patient/DoctorDetailScreen';
import AppointmentsScreen from '../screens/patient/AppointmentsScreen';
import RideScreen from '../screens/patient/RideScreen';
import PharmacyListScreen from '../screens/patient/PharmacyListScreen';
import PharmacyDetailScreen from '../screens/patient/PharmacyDetailScreen';
import OrdersScreen from '../screens/patient/OrdersScreen';
import LabResultsScreen from '../screens/patient/LabResultsScreen';
import ProfileScreen from '../screens/ProfileScreen';
import { colors, font } from '../theme';

const Tab = createBottomTabNavigator();
const HomeStack = createNativeStackNavigator();
const PharmacyStack = createNativeStackNavigator();
const ProfileStack = createNativeStackNavigator();

const stackHeaderOptions = { headerTintColor: colors.secondary, headerTitleStyle: { fontFamily: font.semibold } };

function HomeStackNavigator() {
  return (
    <HomeStack.Navigator screenOptions={stackHeaderOptions}>
      <HomeStack.Screen name="FindDoctor" component={HomeScreen} options={{ title: 'Find a Doctor', headerShown: false }} />
      <HomeStack.Screen name="DoctorDetail" component={DoctorDetailScreen} options={{ title: 'Doctor' }} />
    </HomeStack.Navigator>
  );
}

function PharmacyStackNavigator() {
  return (
    <PharmacyStack.Navigator screenOptions={stackHeaderOptions}>
      <PharmacyStack.Screen name="PharmacyList" component={PharmacyListScreen} options={{ headerShown: false }} />
      <PharmacyStack.Screen
        name="PharmacyDetail"
        component={PharmacyDetailScreen}
        options={({ route }: any) => ({ title: route.params?.pharmacyName ?? 'Pharmacy' })}
      />
    </PharmacyStack.Navigator>
  );
}

function ProfileStackNavigator() {
  return (
    <ProfileStack.Navigator screenOptions={stackHeaderOptions}>
      <ProfileStack.Screen name="ProfileHome" component={ProfileScreen} options={{ title: 'Profile', headerShown: false }} />
      <ProfileStack.Screen name="LabResults" component={LabResultsScreen} options={{ title: 'Lab Results' }} />
      <ProfileStack.Screen name="Orders" component={OrdersScreen} options={{ title: 'My Orders' }} />
    </ProfileStack.Navigator>
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
        tabBarActiveTintColor: colors.primary,
        tabBarInactiveTintColor: colors.gray500,
        tabBarLabelStyle: { fontFamily: font.medium, fontSize: 11 },
      }}
    >
      <Tab.Screen name="Home" component={HomeStackNavigator} options={{ tabBarIcon: ({ focused }) => <Icon label="🩺" focused={focused} />, title: 'Doctors' }} />
      <Tab.Screen name="Appointments" component={AppointmentsScreen} options={{ tabBarIcon: ({ focused }) => <Icon label="📅" focused={focused} /> }} />
      <Tab.Screen name="Rides" component={RideScreen} options={{ tabBarIcon: ({ focused }) => <Icon label="🚐" focused={focused} /> }} />
      <Tab.Screen name="Pharmacy" component={PharmacyStackNavigator} options={{ tabBarIcon: ({ focused }) => <Icon label="💊" focused={focused} /> }} />
      <Tab.Screen name="Profile" component={ProfileStackNavigator} options={{ tabBarIcon: ({ focused }) => <Icon label="👤" focused={focused} /> }} />
    </Tab.Navigator>
  );
}
