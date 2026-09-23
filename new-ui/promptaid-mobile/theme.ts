// Prompt Aid — mobile/src/theme/index.ts (replaces the Hope UI palette)
// Font: npx expo install @expo-google-fonts/lato expo-font
export const colors = {
  ink: '#101012', inkSoft: '#3A3A3E', paper: '#F5F2EC', surface: '#FFFFFF', surface2: '#FDFCF9',
  line: '#E0DACB', lineSoft: '#F0EBE0', muted: '#6E6A62', muted2: '#9A948A',
  signal: '#D0211C', signalInk: '#9E1813', signalWash: '#FBEDEC',
  beacon: '#F2C200', beaconWash: '#FEF6DA', beaconLine: '#EBD98A',
  go: '#1F7A4C', goWash: '#E7F3EC', info: '#1F4E7A',
  sats: { red: '#C8102E', orange: '#E4701E', yellow: '#F2C200', green: '#1F7A4C' },
} as const;
export const fonts = { regular: 'Lato_400Regular', bold: 'Lato_700Bold', black: 'Lato_900Black', light: 'Lato_300Light' };
export const radius = { none: 0, sm: 2, pill: 999 };
export const space = [0, 4, 8, 12, 16, 20, 24, 32, 40, 56, 80] as const;
export const type = {
  h1: { fontFamily: fonts.black, fontSize: 28, letterSpacing: -0.6, lineHeight: 30 },
  h2: { fontFamily: fonts.black, fontSize: 22, letterSpacing: -0.4, lineHeight: 25 },
  h3: { fontFamily: fonts.black, fontSize: 17, letterSpacing: -0.2 },
  body: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 22 },
  small: { fontFamily: fonts.regular, fontSize: 12, color: colors.muted },
  label: { fontFamily: fonts.black, fontSize: 11, letterSpacing: 1.4, textTransform: 'uppercase' as const, color: colors.muted },
  num: { fontFamily: fonts.black, fontVariant: ['tabular-nums' as const], letterSpacing: -0.4 },
};
export const hitSlop = 44; // minimum tap target
export const shadow = { shadowColor: '#101012', shadowOpacity: 0.35, shadowRadius: 12, shadowOffset: { width: 0, height: 8 }, elevation: 8 }; // sheets & modals only
