import React from 'react';
import { StyleSheet } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import type { BottomTabNavigationOptions } from '@react-navigation/bottom-tabs';
import { paFonts } from '../theme';

/**
 * Shared bottom-tab styling for every role's tab navigator: clean white bar,
 * hairline top border, thin black outline icons (active) / grey (inactive).
 */
export const TAB_ACTIVE = '#111111';
export const TAB_INACTIVE = '#8A8A8E';
const TAB_ICON_SIZE = 23;

export type TabIconName = React.ComponentProps<typeof Ionicons>['name'];

export const tabBarScreenOptions: BottomTabNavigationOptions = {
  headerShown: false,
  tabBarActiveTintColor: TAB_ACTIVE,
  tabBarInactiveTintColor: TAB_INACTIVE,
  tabBarStyle: {
    backgroundColor: '#FFFFFF',
    borderTopColor: '#E6E6E9',
    borderTopWidth: StyleSheet.hairlineWidth,
    elevation: 0,
    shadowOpacity: 0,
  },
  tabBarLabelStyle: { fontFamily: paFonts.bold, fontSize: 11 },
};

/** `tabBarIcon` renderer for an Ionicons outline glyph, tinted by focus state. */
export function tabIcon(name: TabIconName) {
  return ({ color }: { focused: boolean; color: string; size: number }) => (
    <Ionicons name={name} size={TAB_ICON_SIZE} color={color} />
  );
}
