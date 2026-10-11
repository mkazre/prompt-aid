import React from 'react';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import PharmacyOrdersScreen from '../screens/pharmacy/OrdersScreen';
import PharmacyOrderDetailScreen from '../screens/pharmacy/OrderDetailScreen';
import PharmacyScriptsScreen from '../screens/pharmacy/ScriptsScreen';
import PharmacyStockScreen from '../screens/pharmacy/StockScreen';
import AccountStackNavigator from './AccountStack';
import { pa, paFonts } from '../theme';
import { tabBarScreenOptions, tabIcon } from './tabBarTheme';

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

export default function PharmacyTabs() {
  return (
    <Tab.Navigator
      screenOptions={tabBarScreenOptions}
    >
      <Tab.Screen name="Orders" component={OrdersStackNavigator} options={{ tabBarIcon: tabIcon('cube-outline') }} />
      <Tab.Screen name="Scripts" component={PharmacyScriptsScreen} options={{ tabBarIcon: tabIcon('clipboard-outline') }} />
      <Tab.Screen name="Stock" component={PharmacyStockScreen} options={{ tabBarIcon: tabIcon('medical-outline') }} />
      <Tab.Screen name="Profile" component={AccountStackNavigator} options={{ tabBarIcon: tabIcon('person-outline') }} />
    </Tab.Navigator>
  );
}
