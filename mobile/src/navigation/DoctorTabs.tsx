import React from 'react';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import DoctorDashboardScreen from '../screens/doctor/DashboardScreen';
import DoctorCalendarScreen from '../screens/doctor/CalendarScreen';
import DoctorEncounterScreen from '../screens/doctor/EncounterScreen';
import DoctorPatientsScreen from '../screens/doctor/PatientsScreen';
import DoctorSettingsScreen from '../screens/doctor/SettingsScreen';
import DoctorServicesScreen from '../screens/doctor/ServicesScreen';
import DoctorHolidayScreen from '../screens/doctor/HolidayScreen';
import DoctorSessionsScreen from '../screens/doctor/SessionsScreen';
import DoctorEditProfileScreen from '../screens/doctor/EditProfileScreen';
import ChangePasswordScreen from '../screens/ChangePasswordScreen';
import AppPageScreen from '../screens/AppPageScreen';
import ConsultRoomScreen from '../screens/ConsultRoomScreen';
import { Appointment } from '../api/types';
import { pa, paFonts } from '../theme';
import { tabBarScreenOptions, tabIcon } from './tabBarTheme';

const Tab = createBottomTabNavigator();
const DashboardStack = createNativeStackNavigator();
const CalendarStack = createNativeStackNavigator();
const SettingsStack = createNativeStackNavigator();

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

function SettingsStackNavigator() {
  return (
    <SettingsStack.Navigator screenOptions={stackHeaderOptions}>
      <SettingsStack.Screen name="DoctorSettingsHome" component={DoctorSettingsScreen} options={{ headerShown: false }} />
      <SettingsStack.Screen name="DoctorServices" component={DoctorServicesScreen} options={{ title: 'Services' }} />
      <SettingsStack.Screen name="DoctorHoliday" component={DoctorHolidayScreen} options={{ title: 'Holiday' }} />
      <SettingsStack.Screen name="DoctorSessions" component={DoctorSessionsScreen} options={{ title: 'Sessions' }} />
      <SettingsStack.Screen name="DoctorEditProfile" component={DoctorEditProfileScreen} options={{ title: 'Edit profile' }} />
      <SettingsStack.Screen name="ChangePassword" component={ChangePasswordScreen} options={{ title: 'Change password' }} />
      <SettingsStack.Screen name="AppPage" component={AppPageScreen} options={{ title: '' }} />
    </SettingsStack.Navigator>
  );
}

export default function DoctorTabs() {
  return (
    <Tab.Navigator
      screenOptions={tabBarScreenOptions}
    >
      <Tab.Screen name="DoctorDashboard" component={DashboardStackNavigator} options={{ tabBarIcon: tabIcon('today-outline'), title: 'Today' }} />
      <Tab.Screen name="DoctorCalendar" component={CalendarStackNavigator} options={{ tabBarIcon: tabIcon('calendar-outline'), title: 'Calendar' }} />
      <Tab.Screen name="DoctorPatients" component={DoctorPatientsScreen} options={{ tabBarIcon: tabIcon('people-outline'), title: 'Patients' }} />
      <Tab.Screen name="DoctorSettings" component={SettingsStackNavigator} options={{ tabBarIcon: tabIcon('settings-outline'), title: 'Settings' }} />
    </Tab.Navigator>
  );
}
