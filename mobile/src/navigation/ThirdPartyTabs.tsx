import React from 'react';
import { Text } from 'react-native';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import ThirdPartyHomeScreen from '../screens/thirdparty/HomeScreen';
import RequestDetailScreen from '../screens/thirdparty/RequestDetailScreen';
import ProfileScreen from '../screens/ProfileScreen';
import { colors, font } from '../theme';

const Tab = createBottomTabNavigator();
const HomeStack = createNativeStackNavigator();

function HomeStackNavigator() {
  return (
    <HomeStack.Navigator screenOptions={{ headerTintColor: colors.secondary, headerTitleStyle: { fontFamily: font.semibold } }}>
      <HomeStack.Screen name="ThirdPartyHome" component={ThirdPartyHomeScreen} options={{ headerShown: false }} />
      <HomeStack.Screen name="RequestDetail" component={RequestDetailScreen} options={{ title: 'Diagnostic Request' }} />
    </HomeStack.Navigator>
  );
}

function Icon({ label, focused }: { label: string; focused: boolean }) {
  return <Text style={{ fontSize: 20, opacity: focused ? 1 : 0.5 }}>{label}</Text>;
}

export default function ThirdPartyTabs() {
  return (
    <Tab.Navigator
      screenOptions={{
        headerShown: false,
        tabBarActiveTintColor: colors.primary,
        tabBarInactiveTintColor: colors.gray500,
        tabBarLabelStyle: { fontFamily: font.medium, fontSize: 11 },
      }}
    >
      <Tab.Screen name="Requests" component={HomeStackNavigator} options={{ tabBarIcon: ({ focused }) => <Icon label="🧪" focused={focused} /> }} />
      <Tab.Screen name="Profile" component={ProfileScreen} options={{ tabBarIcon: ({ focused }) => <Icon label="👤" focused={focused} /> }} />
    </Tab.Navigator>
  );
}
