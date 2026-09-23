import React from 'react';
import { Text } from 'react-native';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import DoctorDashboardScreen from '../screens/doctor/DashboardScreen';
import DoctorCalendarScreen from '../screens/doctor/CalendarScreen';
import DoctorPatientsScreen from '../screens/doctor/PatientsScreen';
import DoctorSettingsScreen from '../screens/doctor/SettingsScreen';
import { colors, font } from '../theme';

const Tab = createBottomTabNavigator();

function Icon({ label, focused }: { label: string; focused: boolean }) {
  return <Text style={{ fontSize: 20, opacity: focused ? 1 : 0.5 }}>{label}</Text>;
}

export default function DoctorTabs() {
  return (
    <Tab.Navigator
      screenOptions={{
        headerShown: false,
        tabBarActiveTintColor: colors.primary,
        tabBarInactiveTintColor: colors.gray500,
        tabBarLabelStyle: { fontFamily: font.medium, fontSize: 11 },
      }}
    >
      <Tab.Screen name="DoctorDashboard" component={DoctorDashboardScreen} options={{ tabBarIcon: ({ focused }) => <Icon label="🖥️" focused={focused} />, title: 'Dashboard' }} />
      <Tab.Screen name="DoctorCalendar" component={DoctorCalendarScreen} options={{ tabBarIcon: ({ focused }) => <Icon label="📅" focused={focused} />, title: 'Calendar' }} />
      <Tab.Screen name="DoctorPatients" component={DoctorPatientsScreen} options={{ tabBarIcon: ({ focused }) => <Icon label="👥" focused={focused} />, title: 'Patients' }} />
      <Tab.Screen name="DoctorSettings" component={DoctorSettingsScreen} options={{ tabBarIcon: ({ focused }) => <Icon label="☰" focused={focused} />, title: 'Settings' }} />
    </Tab.Navigator>
  );
}
