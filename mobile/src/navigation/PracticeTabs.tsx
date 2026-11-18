import React from 'react';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import PracticeHomeScreen from '../screens/practice/PracticeHomeScreen';
import { tabBarScreenOptions, tabIcon } from './tabBarTheme';

const Tab = createBottomTabNavigator();

/** Practice reception (role practice_reception): availability + urgent practice calls. */
export default function PracticeTabs() {
  return (
    <Tab.Navigator screenOptions={tabBarScreenOptions}>
      <Tab.Screen name="PracticeHome" component={PracticeHomeScreen} options={{ tabBarIcon: tabIcon('call-outline'), title: 'Calls' }} />
    </Tab.Navigator>
  );
}
