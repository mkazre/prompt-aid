/**
 * Prompt Aid design tokens — "clinical dispatch". Matched 1:1 to the web
 * (Tailwind/promptaid.css) and admin (Filament) palettes. Font is Lato,
 * loaded via @expo-google-fonts/lato. Near-square corners, hairline
 * borders, tabular numerals for numbers.
 *
 * New screens should use `pa` (canonical tokens below, matching
 * mobile/theme.ts from the design package) directly. `colors`, `spacing`,
 * `font` and `radius` are kept as compatibility aliases so every existing
 * screen picks up the new brand without a rewrite — they'll be retired as
 * screens are migrated to `pa` one at a time.
 */
export const pa = {
  ink: '#101012',
  inkSoft: '#3A3A3E',
  paper: '#F5F2EC',
  surface: '#FFFFFF',
  surface2: '#FDFCF9',
  line: '#E0DACB',
  lineSoft: '#F0EBE0',
  muted: '#6E6A62',
  muted2: '#9A948A',
  signal: '#D0211C',
  signalInk: '#9E1813',
  signalWash: '#FBEDEC',
  beacon: '#F2C200',
  beaconWash: '#FEF6DA',
  beaconLine: '#EBD98A',
  go: '#1F7A4C',
  goWash: '#E7F3EC',
  info: '#1F4E7A',
  sats: { red: '#C8102E', orange: '#E4701E', yellow: '#F2C200', green: '#1F7A4C' },
} as const;

export const paFonts = { regular: 'Lato_400Regular', bold: 'Lato_700Bold', black: 'Lato_900Black', light: 'Lato_300Light' };
export const paRadius = { none: 0, sm: 2, pill: 999 };
export const space = [0, 4, 8, 12, 16, 20, 24, 32, 40, 56, 80] as const;
export const type = {
  h1: { fontFamily: paFonts.black, fontSize: 28, letterSpacing: -0.6, lineHeight: 30 },
  h2: { fontFamily: paFonts.black, fontSize: 22, letterSpacing: -0.4, lineHeight: 25 },
  h3: { fontFamily: paFonts.black, fontSize: 17, letterSpacing: -0.2 },
  body: { fontFamily: paFonts.regular, fontSize: 15, lineHeight: 22 },
  small: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted },
  label: { fontFamily: paFonts.black, fontSize: 11, letterSpacing: 1.4, textTransform: 'uppercase' as const, color: pa.muted },
  num: { fontFamily: paFonts.black, fontVariant: ['tabular-nums' as const], letterSpacing: -0.4 },
};
export const hitSlop = 44;
export const paShadow = { shadowColor: '#101012', shadowOpacity: 0.35, shadowRadius: 12, shadowOffset: { width: 0, height: 8 }, elevation: 8 };

// ---------------------------------------------------------------------
// Compatibility layer — old token names, new hex values. Existing screens
// import these unchanged; only the colours/shapes underneath moved.
// ---------------------------------------------------------------------
export const colors = {
  primary: pa.signal,
  primaryDark: pa.signalInk,
  primaryLight: pa.signalWash,
  secondary: pa.ink,
  accent: pa.info,
  accentLight: '#E8EEF4',
  success: pa.go,
  successLight: pa.goWash,
  warning: '#7A5C00',
  warningLight: pa.beaconWash,
  danger: pa.signalInk,
  dangerLight: pa.signalWash,
  beacon: pa.beacon,
  beaconWash: pa.beaconWash,
  gray50: pa.paper,
  gray100: pa.lineSoft,
  gray200: pa.line,
  gray300: '#C7BFAE',
  gray400: '#B8B1A0',
  gray500: pa.muted2,
  gray600: pa.muted,
  gray700: pa.inkSoft,
  gray800: '#26262A',
  gray900: pa.ink,
  white: pa.surface,
};

export const spacing = { xs: 4, sm: 8, md: 16, lg: 24, xl: 32, xxl: 48 };

// Near-square everywhere — the single biggest lever for making the app
// stop reading as a generic admin-template kit. `full` stays for avatars.
export const radius = { sm: 2, md: 2, lg: 2, xl: 2, full: 999 };

export const font = {
  regular: paFonts.regular,
  medium: paFonts.regular,
  semibold: paFonts.bold,
  bold: paFonts.bold,
};

export const shadow = {
  card: paShadow,
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
