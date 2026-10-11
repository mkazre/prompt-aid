import { Feather } from '@expo/vector-icons';
import { WidgetSettings } from './types';

export type FeatherName = keyof typeof Feather.glyphMap;

/** A Feather icon name if it exists, otherwise the fallback. */
export function safeIcon(name: string | undefined | null, fallback: FeatherName = 'grid'): FeatherName {
  return name && name in Feather.glyphMap ? (name as FeatherName) : fallback;
}

/** Best-guess icon for a free-text category/speciality — mirrors WidgetCatalog::iconFor(). */
export function iconFor(name?: string | null): FeatherName {
  const n = (name ?? '').toLowerCase();
  const map: [string, FeatherName][] = [
    ['dent', 'smile'], ['tooth', 'smile'], ['eye', 'eye'], ['opt', 'eye'], ['vision', 'eye'],
    ['cardi', 'heart'], ['heart', 'heart'], ['lab', 'droplet'], ['blood', 'droplet'], ['patho', 'droplet'],
    ['imag', 'activity'], ['x-ray', 'activity'], ['radio', 'activity'], ['physio', 'activity'],
    ['pharm', 'package'], ['medic', 'package'], ['vitamin', 'package'], ['supplement', 'package'],
    ['baby', 'smile'], ['child', 'smile'], ['paed', 'smile'], ['pediat', 'smile'],
    ['emerg', 'alert-triangle'], ['ambul', 'truck'], ['shuttle', 'navigation'], ['transport', 'navigation'],
    ['video', 'video'], ['tele', 'video'], ['virtual', 'video'],
    ['gp', 'user'], ['general', 'user'], ['family', 'users'], ['consult', 'user'],
    ['nurs', 'plus-square'], ['home', 'plus-square'], ['hospital', 'plus-square'], ['clinic', 'plus-square'],
    ['fever', 'thermometer'], ['flu', 'thermometer'], ['device', 'thermometer'],
    ['skin', 'shield'], ['derm', 'shield'], ['immun', 'shield'], ['vaccin', 'shield'],
    ['mental', 'heart'], ['psych', 'heart'], ['counsel', 'heart'], ['pain', 'zap'], ['first aid', 'plus-square'],
  ];
  for (const [needle, icon] of map) if (n.includes(needle)) return icon;
  return 'plus-square';
}

export function accentOf(settings: WidgetSettings, fallback: string): string {
  const a = settings.accent;
  return a && /^#[0-9a-fA-F]{3,8}$/.test(a) ? a : fallback;
}

/** `#1F4E7A` + alpha → `#1F4E7A1A` (6-digit hex only; otherwise a neutral wash). */
export function tint(hex: string, alpha = '1A'): string {
  return /^#[0-9a-fA-F]{6}$/.test(hex) ? hex + alpha : '#F0EBE0';
}

export function intSetting(settings: WidgetSettings, key: string, fallback: number): number {
  const n = parseInt(settings[key] ?? '', 10);
  return Number.isFinite(n) && n > 0 ? n : fallback;
}

/**
 * Navigate to "Tab" or "Tab/Screen". React Navigation bubbles navigate()
 * up to the parent tab navigator when the current stack doesn't own the
 * route, so this works from any nested stack.
 */
export function goTo(navigation: any, target: string | undefined, params?: object) {
  if (!target || !navigation) return;
  const [tab, screen] = target.split('/');
  try {
    if (screen) navigation.navigate(tab, { screen, params });
    else navigation.navigate(tab, params);
  } catch {
    // Unknown route for this role — ignore rather than crash the screen.
  }
}

export function initials(name?: string | null) {
  const parts = (name ?? '')
    .trim()
    .split(/\s+/)
    .filter((p) => p && !/^(dr|mr|mrs|ms|miss|prof|sr|jr|md|i{1,3}|iv|v)\.?$/i.test(p));
  if (!parts.length) return '?';
  return ((parts[0][0] ?? '') + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
}

export function money(n: number | null | undefined, decimals = 0) {
  const v = Number(n ?? 0);
  return 'R ' + v.toFixed(decimals).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

export function shortDate(iso?: string | null) {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return d.toLocaleDateString('en-ZA', { day: '2-digit', month: 'short' });
}

export function timeAgo(iso?: string | null) {
  if (!iso) return '';
  const mins = Math.round((Date.now() - new Date(iso).getTime()) / 60000);
  if (mins < 1) return 'just now';
  if (mins < 60) return `${mins} min ago`;
  const h = Math.round(mins / 60);
  if (h < 24) return `${h} h ago`;
  return `${Math.round(h / 24)} d ago`;
}

export function headline(s?: string | null) {
  return (s ?? '').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}
