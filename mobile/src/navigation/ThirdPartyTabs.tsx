import React from 'react';
import { Text } from 'react-native';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import ThirdPartyHomeScreen from '../screens/thirdparty/HomeScreen';
import RequestDetailScreen from '../screens/thirdparty/RequestDetailScreen';
import ProfileScreen from '../screens/ProfileScreen';
import { useAuth } from '../context/AuthContext';
import { pa, paFonts } from '../theme';
import { categoryEmoji, categoryLabel } from '../components/pa/partner-extras';

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

function Icon({ label, focused }: { label: string; focused: boolean }) {
  return <Text style={{ fontSize: 20, opacity: focused ? 1 : 0.5 }}>{label}</Text>;
}

export default function ThirdPartyTabs() {
  const { user } = useAuth();
  const category = user?.third_party_profile?.category;
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
      <Tab.Screen name="Requests" component={HomeStackNavigator} options={{ tabBarIcon: ({ focused }) => <Icon label={categoryEmoji(category)} focused={focused} /> }} />
      <Tab.Screen name="Profile" component={ProfileScreen} options={{ tabBarIcon: ({ focused }) => <Icon label="👤" focused={focused} /> }} />
    </Tab.Navigator>
  );
}
