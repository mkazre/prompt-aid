import React from 'react';
import { Text } from 'react-native';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import DoctorDashboardScreen from '../screens/doctor/DashboardScreen';
import DoctorCalendarScreen from '../screens/doctor/CalendarScreen';
import DoctorEncounterScreen from '../screens/doctor/EncounterScreen';
import DoctorPatientsScreen from '../screens/doctor/PatientsScreen';
import DoctorSettingsScreen from '../screens/doctor/SettingsScreen';
import ConsultRoomScreen from '../screens/ConsultRoomScreen';
import { Appointment } from '../api/types';
import { pa, paFonts } from '../theme';

const Tab = createBottomTabNavigator();
const DashboardStack = createNativeStackNavigator();
const CalendarStack = createNativeStackNavigator();

/** Shared across both stacks below — the encounter and consult-room screens are pushed from either "My day" or the calendar. */
export type DoctorStackParamList = {
  DoctorDashboardHome: undefined;
  DoctorCalendarHome: undefined;
  DoctorEncounter: { appointment: Appointment };
  ConsultRoom: { appointment: Appointment };
};

const stackHeaderOptions = {
  headerTintColor: pa.ink,
  headerStyle: { backgroundColor: pa.surface },
  headerTitleStyle: { fontFamily: paFonts.bold },
  headerShadowVisible: false,
};

function DashboardStackNavigator() {
  return (
    <DashboardStack.Navigator screenOptions={stackHeaderOptions}>
      <DashboardStack.Screen name="DoctorDashboardHome" component={DoctorDashboardScreen} options={{ title: 'My day', headerShown: false }} />
      <DashboardStack.Screen name="DoctorEncounter" component={DoctorEncounterScreen} options={{ title: 'Consult' }} />
      <DashboardStack.Screen name="ConsultRoom" component={ConsultRoomScreen} options={{ title: 'Video consult', headerTintColor: '#fff', headerStyle: { backgroundColor: pa.ink } }} />
    </DashboardStack.Navigator>
  );
}

function CalendarStackNavigator() {
  return (
    <CalendarStack.Navigator screenOptions={stackHeaderOptions}>
      <CalendarStack.Screen name="DoctorCalendarHome" component={DoctorCalendarScreen} options={{ title: 'Calendar', headerShown: false }} />
      <CalendarStack.Screen name="DoctorEncounter" component={DoctorEncounterScreen} options={{ title: 'Consult' }} />
      <CalendarStack.Screen name="ConsultRoom" component={ConsultRoomScreen} options={{ title: 'Video consult', headerTintColor: '#fff', headerStyle: { backgroundColor: pa.ink } }} />
    </CalendarStack.Navigator>
  );
}

function Icon({ label, focused }: { label: string; focused: boolean }) {
  return <Text style={{ fontSize: 20, opacity: focused ? 1 : 0.5 }}>{label}</Text>;
}

export default function DoctorTabs() {
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
      <Tab.Screen name="DoctorDashboard" component={DashboardStackNavigator} options={{ tabBarIcon: ({ focused }) => <Icon label="🖥️" focused={focused} />, title: 'Today' }} />
      <Tab.Screen name="DoctorCalendar" component={CalendarStackNavigator} options={{ tabBarIcon: ({ focused }) => <Icon label="📅" focused={focused} />, title: 'Calendar' }} />
      <Tab.Screen name="DoctorPatients" component={DoctorPatientsScreen} options={{ tabBarIcon: ({ focused }) => <Icon label="👥" focused={focused} />, title: 'Patients' }} />
      <Tab.Screen name="DoctorSettings" component={DoctorSettingsScreen} options={{ tabBarIcon: ({ focused }) => <Icon label="☰" focused={focused} />, title: 'Settings' }} />
    </Tab.Navigator>
  );
}
