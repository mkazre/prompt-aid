import React from 'react';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import ThirdPartyHomeScreen from '../screens/thirdparty/HomeScreen';
import RequestDetailScreen from '../screens/thirdparty/RequestDetailScreen';
import AccountStackNavigator from './AccountStack';
import { useAuth } from '../context/AuthContext';
import { pa, paFonts } from '../theme';
import { tabBarScreenOptions, tabIcon } from './tabBarTheme';
import { categoryLabel } from '../components/pa/partner-extras';

const Tab = createBottomTabNavigator();
const HomeStack = createNativeStackNavigator();

function HomeStackNavigator() {
  const { user } = useAuth();
  const category = user?.third_party_profile?.category;
  return (
    <HomeStack.Navigator screenOptions={{ headerTintColor: pa.ink, headerTitleStyle: { fontFamily: paFonts.bold } }}>
      <HomeStack.Screen name="ThirdPartyHome" component={ThirdPartyHomeScreen} options={{ headerShown: false }} />
      <HomeStack.Screen name="RequestDetail" component={RequestDetailScreen} options={{ title: `${categoryLabel(category)} Request` }} />
    </HomeStack.Navigator>
  );
}

export default function ThirdPartyTabs() {
  return (
    <Tab.Navigator
      screenOptions={tabBarScreenOptions}
    >
      <Tab.Screen name="Requests" component={HomeStackNavigator} options={{ tabBarIcon: tabIcon('file-tray-full-outline') }} />
      <Tab.Screen name="Profile" component={AccountStackNavigator} options={{ tabBarIcon: tabIcon('person-outline') }} />
    </Tab.Navigator>
  );
}
