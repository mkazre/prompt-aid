import React from 'react';
import { Text } from 'react-native';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import DriverHomeScreen from '../screens/driver/HomeScreen';
import ActiveRideScreen from '../screens/driver/ActiveRideScreen';
import ProfileScreen from '../screens/ProfileScreen';
import { colors, font } from '../theme';

const Tab = createBottomTabNavigator();

function Icon({ label, focused }: { label: string; focused: boolean }) {
  return <Text style={{ fontSize: 20, opacity: focused ? 1 : 0.5 }}>{label}</Text>;
}

export default function DriverTabs() {
  return (
    <Tab.Navigator
      screenOptions={{
        headerShown: false,
        tabBarActiveTintColor: colors.primary,
        tabBarInactiveTintColor: colors.gray500,
        tabBarLabelStyle: { fontFamily: font.medium, fontSize: 11 },
      }}
    >
      <Tab.Screen name="DriverHome" component={DriverHomeScreen} options={{ tabBarIcon: ({ focused }) => <Icon label="🚗" focused={focused} />, title: 'Rides' }} />
      <Tab.Screen name="ActiveRide" component={ActiveRideScreen} options={{ tabBarIcon: ({ focused }) => <Icon label="🧭" focused={focused} />, title: 'Active' }} />
      <Tab.Screen name="Profile" component={ProfileScreen} options={{ tabBarIcon: ({ focused }) => <Icon label="👤" focused={focused} /> }} />
    </Tab.Navigator>
  );
}
