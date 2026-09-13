/**
 * Prompt Aid design tokens — matched 1:1 to DESIGN_SYSTEM.md / the web
 * (Tailwind) and admin (Filament) palettes so the whole product feels
 * like one system. Font is Inter, loaded via @expo-google-fonts/inter.
 */
export const colors = {
  primary: '#3A57E8',
  primaryDark: '#2F46BA',
  primaryLight: '#EEF1FD',
  secondary: '#001F4D',
  accent: '#079AA2',
  accentLight: '#E5F6F7',
  success: '#1AA053',
  successLight: '#E7F7EE',
  warning: '#F16A1B',
  warningLight: '#FEF0E7',
  danger: '#C03221',
  dangerLight: '#FBEAE8',
  gray50: '#F8F9FA',
  gray100: '#F1F3F5',
  gray200: '#E9ECEF',
  gray300: '#DEE2E6',
  gray400: '#CED4DA',
  gray500: '#ADB5BD',
  gray600: '#6C757D',
  gray700: '#495057',
  gray800: '#343A40',
  gray900: '#212529',
  white: '#FFFFFF',
};

export const spacing = { xs: 4, sm: 8, md: 16, lg: 24, xl: 32, xxl: 48 };

export const radius = { sm: 8, md: 12, lg: 16, xl: 24, full: 999 };

export const font = {
  regular: 'Inter_400Regular',
  medium: 'Inter_500Medium',
  semibold: 'Inter_600SemiBold',
  bold: 'Inter_700Bold',
};

export const shadow = {
  card: {
    shadowColor: '#8898AA',
    shadowOffset: { width: 0, height: 4 },
    shadowOpacity: 0.12,
    shadowRadius: 12,
    elevation: 3,
  },
};

export function statusColor(status: string): { fg: string; bg: string } {
  const success = ['completed', 'confirmed', 'paid', 'active', 'approved', 'success', 'available'];
  const warning = ['pending', 'checked_in', 'unpaid', 'partially_paid', 'pending_approval', 'requested', 'accepted', 'driver_enroute', 'arrived', 'in_progress', 'busy'];
  const danger = ['cancelled', 'no_show', 'failed', 'suspended', 'rejected', 'refunded', 'urgent'];

  if (success.includes(status)) return { fg: colors.success, bg: colors.successLight };
  if (warning.includes(status)) return { fg: colors.warning, bg: colors.warningLight };
  if (danger.includes(status)) return { fg: colors.danger, bg: colors.dangerLight };
  return { fg: colors.gray600, bg: colors.gray100 };
}
