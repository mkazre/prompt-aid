import React from 'react';
import { Text } from 'react-native';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import PharmacyOrdersScreen from '../screens/pharmacy/OrdersScreen';
import PharmacyOrderDetailScreen from '../screens/pharmacy/OrderDetailScreen';
import PharmacyScriptsScreen from '../screens/pharmacy/ScriptsScreen';
import PharmacyStockScreen from '../screens/pharmacy/StockScreen';
import ProfileScreen from '../screens/ProfileScreen';
import { pa, paFonts } from '../theme';

const Tab = createBottomTabNavigator();
const OrdersStack = createNativeStackNavigator();

function OrdersStackNavigator() {
  return (
    <OrdersStack.Navigator screenOptions={{ headerTintColor: pa.ink, headerTitleStyle: { fontFamily: paFonts.bold } }}>
      <OrdersStack.Screen name="PharmacyOrders" component={PharmacyOrdersScreen} options={{ headerShown: false }} />
      <OrdersStack.Screen name="PharmacyOrderDetail" component={PharmacyOrderDetailScreen} options={{ title: 'Order' }} />
    </OrdersStack.Navigator>
  );
}

function Icon({ label, focused }: { label: string; focused: boolean }) {
  return <Text style={{ fontSize: 20, opacity: focused ? 1 : 0.5 }}>{label}</Text>;
}

export default function PharmacyTabs() {
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
      <Tab.Screen name="Orders" component={OrdersStackNavigator} options={{ tabBarIcon: ({ focused }) => <Icon label="📦" focused={focused} /> }} />
      <Tab.Screen name="Scripts" component={PharmacyScriptsScreen} options={{ tabBarIcon: ({ focused }) => <Icon label="📋" focused={focused} /> }} />
      <Tab.Screen name="Stock" component={PharmacyStockScreen} options={{ tabBarIcon: ({ focused }) => <Icon label="💊" focused={focused} /> }} />
      <Tab.Screen name="Profile" component={ProfileScreen} options={{ tabBarIcon: ({ focused }) => <Icon label="👤" focused={focused} /> }} />
    </Tab.Navigator>
  );
}
