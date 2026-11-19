import { Linking } from 'react-native';
import { api } from '../api/client';
import type { TriageLevel } from './triage';

/**
 * Patient request scenarios (Staff → Triage → Patient request scenarios):
 * what each emergency-scale colour does, in which order. One resolver for
 * the app and the website: GET /api/triage/scenario/{level}
 * (App\Services\Triage\ScenarioEngine::resolve). Cached in memory for a
 * minute; when the server is older / offline the callers fall back to the
 * built-in behaviour.
 */

export type ScenarioStepType =
  | 'show_message'
  | 'call_emergency'
  | 'offer_ambulance_or_shuttle'
  | 'collect_payment'
  | 'broadcast_to_doctors'
  | 'choose_channel'
  | 'auto_start_channel'
  | 'practice_call'
  | 'capture_booking'
  | 'book_appointment'
  | 'find_care'
  | 'self_care_advice'
  | 'health_article'
  | 'pharmacy'
  | 'end';

export interface AppTarget {
  tab: string;
  screen: string;
  params?: Record<string, unknown>;
}

export interface ScenarioStep {
  id: string;
  type: ScenarioStepType;
  label: string;
  settings: Record<string, any>;
  url?: string | null;
  app?: AppTarget | null;
  phone?: string | null;
  tel?: string | null;
  contacts?: { label: string; phone: string; tel: string; primary: boolean }[];
  ambulance?: { phone: string; tel: string } | null;
  shuttle_url?: string | null;
  lines?: string[];
}

export interface ScenarioPlan {
  has_consult: boolean;
  optional?: boolean;
  auto_request: boolean;
  payment: boolean;
  allow_medical_aid?: boolean;
  payment_note?: string | null;
  specialty?: string | null;
  button_label?: string;
  channel_timing: 'before' | 'after' | 'auto' | 'practice' | 'none';
  /** 'practice' = orange practice call (the practice calls the patient in the app and books the visit). */
  flow?: 'practice' | 'doctor' | 'none';
  practice_call?: boolean;
  booking?: { modes: ('in_person' | 'video')[]; default_mode: string; shuttle: boolean } | null;
  channels: string[];
  default_channel?: string;
  fallback?: string;
}

/** The four triage colours plus 'virtual' (the blue "Virtual / video consult" button). */
export type ScenarioLevel = TriageLevel | 'virtual';

export interface Scenario {
  level: ScenarioLevel;
  level_label: string;
  version: number;
  steps: ScenarioStep[];
  plan: ScenarioPlan;
  entry: { kind: 'dial' | 'consult' | 'steps'; tel: string | null; url: string; app: AppTarget };
  summary: string[];
  emergency: { label: string; phone: string; tel: string } | null;
}

const cache: Partial<Record<ScenarioLevel, { at: number; data: Scenario }>> = {};
// Short: admin changes (Staff → Triage → Patient request scenarios) reach the app within seconds.
const TTL = 15_000;

export async function getScenario(level: ScenarioLevel, opts: { fresh?: boolean; timeoutMs?: number } = {}): Promise<Scenario | null> {
  const hit = cache[level];
  if (!opts.fresh && hit && Date.now() - hit.at < TTL) return hit.data;
  try {
    const { data } = await api.get(`/triage/scenario/${level}`, { timeout: opts.timeoutMs ?? 6000 });
    const sc = (data?.data ?? null) as Scenario | null;
    if (sc && Array.isArray(sc.steps)) {
      cache[level] = { at: Date.now(), data: sc };
      return sc;
    }
  } catch {
    /* older server / offline: caller falls back */
  }
  return hit?.data ?? null;
}

/** Re-load the live flows whenever the emergency sheet opens (picks up admin changes) so a tap acts instantly. */
export function prefetchScenarios() {
  (['red', 'orange', 'yellow', 'green', 'virtual'] as ScenarioLevel[]).forEach((l) => {
    getScenario(l, { fresh: true }).catch(() => undefined);
  });
}

/** Navigate to an AppTarget from any tab. */
export function goToTarget(navigation: any, target: AppTarget | null | undefined): boolean {
  if (!target) return false;
  const tabs = navigation.getParent?.() ?? navigation;
  try {
    tabs.navigate(target.tab, { screen: target.screen, params: target.params ?? {} });
    return true;
  } catch {
    return false;
  }
}

/**
 * What tapping a colour does: dial at once (and show the rest of the
 * scenario behind it), start the doctor request straight away (orange
 * default: no channel question), or open the scenario screen.
 * Returns false when no scenario could be loaded (caller uses its fallback).
 */
export async function openScenarioEntry(navigation: any, level: ScenarioLevel, triageReference?: string): Promise<boolean> {
  const sc = await getScenario(level, { timeoutMs: 2500 });
  if (!sc) return false;
  const refParams = triageReference ? { triageReference } : {};
  if (sc.entry.kind === 'dial' && sc.entry.tel) {
    Linking.openURL(sc.entry.tel).catch(() => undefined);
    goToTarget(navigation, { tab: 'Home', screen: 'TriageScenario', params: { level, ...refParams } });
    return true;
  }
  if (sc.entry.kind === 'consult') {
    // auto=false (blue button): the page asks video / call back first, then the request goes out.
    const auto = sc.entry.app?.params?.auto === false ? false : true;
    return goToTarget(navigation, { tab: 'Home', screen: 'InstantConsult', params: { level, auto, ...refParams } });
  }
  return goToTarget(navigation, { tab: 'Home', screen: 'TriageScenario', params: { level, ...refParams } });
}
