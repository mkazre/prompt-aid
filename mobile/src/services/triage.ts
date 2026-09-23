/* ===================================================================
 * Prompt Aid — SATS pre-triage engine (TypeScript port)
 * -------------------------------------------------------------------
 * A patient-facing PRE-triage aid. It is NOT the South African Triage
 * Scale itself: real SATS is performed by a trained practitioner using
 * measured vitals. This engine only ever escalates, never reassures.
 *
 * Two mechanisms, exactly as SATS works:
 *   1. TEWS-lite  — a weighted score from layperson-answerable proxies
 *                   for mobility, breathing, consciousness, bleeding,
 *                   temperature, pain and trauma.
 *   2. Discriminators — a hard override list. Any hit sets the colour
 *                   regardless of the score. A discriminator can only
 *                   raise the level, never lower it.
 *
 * This is a FAITHFUL, line-for-line port of
 * new-ui/promptaid-website/assets/js/triage.js (identical to the copy
 * in new-ui/promptaid-mobile/assets/js/triage.js). Do not change the
 * scoring weights, symptom floors or discriminator table without
 * updating that source and the PHP SatsEngine in lockstep — this file
 * has real clinical consequence.
 * =================================================================== */

/** Ascending severity. */
export const LEVELS = ['green', 'yellow', 'orange', 'red'] as const;
export type TriageLevel = (typeof LEVELS)[number];

export interface LevelMeta {
  label: string;
  name: string;
  target: number;
  targetLabel: string;
  colour: string;
}

export const META: Record<TriageLevel, LevelMeta> = {
  red: { label: 'Red', name: 'Emergency', target: 0, targetLabel: 'Immediately', colour: '#C8102E' },
  orange: { label: 'Orange', name: 'Very urgent', target: 10, targetLabel: 'Within 10 minutes', colour: '#E4701E' },
  yellow: { label: 'Yellow', name: 'Urgent', target: 60, targetLabel: 'Within 60 minutes', colour: '#F2C200' },
  green: { label: 'Green', name: 'Routine', target: 240, targetLabel: 'Within 4 hours', colour: '#1F7A4C' },
};

/* --- TEWS-lite weights. Adult bands; paediatric multiplier below. --- */

export type MobilityValue = 'walking' | 'with_help' | 'cannot_walk';
export type BreathingValue = 'normal' | 'short_on_effort' | 'short_at_rest' | 'struggling';
export type ConsciousnessValue = 'alert' | 'drowsy' | 'responds_to_pain' | 'unresponsive';
export type BleedingValue = 'none' | 'minor' | 'soaking';
export type TemperatureValue = 'normal' | 'feverish' | 'burning_or_cold';
export type TraumaValue = 'no' | 'yes';

export interface Observations {
  mobility?: MobilityValue;
  breathing?: BreathingValue;
  consciousness?: ConsciousnessValue;
  bleeding?: BleedingValue;
  temperature?: TemperatureValue;
  trauma?: TraumaValue;
  pain?: number;
}

export const WEIGHTS: {
  mobility: Record<MobilityValue, number>;
  breathing: Record<BreathingValue, number>;
  consciousness: Record<ConsciousnessValue, number>;
  bleeding: Record<BleedingValue, number>;
  temperature: Record<TemperatureValue, number>;
  trauma: Record<TraumaValue, number>;
} = {
  mobility: { walking: 0, with_help: 1, cannot_walk: 2 },
  breathing: { normal: 0, short_on_effort: 1, short_at_rest: 2, struggling: 3 },
  consciousness: { alert: 0, drowsy: 1, responds_to_pain: 2, unresponsive: 3 },
  bleeding: { none: 0, minor: 1, soaking: 3 },
  temperature: { normal: 0, feverish: 1, burning_or_cold: 2 },
  trauma: { no: 0, yes: 1 },
};

function painWeight(score: number): number {
  if (score >= 8) return 3;
  if (score >= 5) return 2;
  if (score >= 3) return 1;
  return 0;
}

/* --- Discriminators. Any match forces at least the stated level. --- */

export interface Discriminator {
  id: string;
  level: TriageLevel;
  label: string;
}

export const DISCRIMINATORS: Discriminator[] = [
  // --- RED ---
  { id: 'not_breathing', level: 'red', label: 'Not breathing or gasping' },
  { id: 'unresponsive', level: 'red', label: 'Cannot be woken' },
  { id: 'seizure_now', level: 'red', label: 'Fitting right now' },
  { id: 'chest_pain', level: 'red', label: 'Chest pain with sweating or nausea' },
  { id: 'stroke_signs', level: 'red', label: 'Face droop, arm weakness or slurred speech' },
  { id: 'bleeding_heavy', level: 'red', label: 'Bleeding that will not stop' },
  { id: 'poisoning', level: 'red', label: 'Swallowed poison or overdosed' },
  { id: 'snakebite', level: 'red', label: 'Snake or scorpion bite' },
  { id: 'burn_major', level: 'red', label: "Burn larger than the person's chest" },
  { id: 'birth_imminent', level: 'red', label: 'Baby is coming now' },
  { id: 'self_harm_now', level: 'red', label: 'About to harm yourself or someone else' },
  { id: 'anaphylaxis', level: 'red', label: 'Swelling of lips or tongue after a sting, food or medicine' },
  // --- ORANGE ---
  { id: 'burn_sensitive', level: 'orange', label: 'Burn to the face, hands, feet or genitals' },
  { id: 'fracture_open', level: 'orange', label: 'Bone visible or limb bent the wrong way' },
  { id: 'head_injury', level: 'orange', label: 'Head knock with vomiting or confusion' },
  { id: 'infant_fever', level: 'orange', label: 'Baby under 3 months with a fever' },
  { id: 'pregnancy_bleed', level: 'orange', label: 'Bleeding or severe pain while pregnant' },
  { id: 'severe_pain', level: 'orange', label: 'Pain you would score 8 or more out of 10' },
  { id: 'child_floppy', level: 'orange', label: 'Child who is floppy or will not feed' },
  { id: 'diabetic_crisis', level: 'orange', label: 'Diabetic, very thirsty, confused or drowsy' },
  { id: 'assault', level: 'orange', label: 'Assaulted, including sexual assault' },
  // --- YELLOW ---
  { id: 'fever_adult', level: 'yellow', label: 'Fever for more than three days' },
  { id: 'vomiting', level: 'yellow', label: 'Vomiting or diarrhoea that will not stop' },
  { id: 'wound', level: 'yellow', label: 'A cut that may need stitches' },
  { id: 'infection', level: 'yellow', label: 'A wound that is hot, swollen or leaking' },
  { id: 'mental_health', level: 'yellow', label: 'Struggling to cope, needs to talk to someone today' },
];

/* --- Symptom categories offered after the colour choice. --- */

export type FacilitySystem =
  | 'respiratory'
  | 'cardiac'
  | 'trauma'
  | 'burns'
  | 'neuro'
  | 'abdominal'
  | 'infection'
  | 'derm'
  | 'obstetric'
  | 'paediatric'
  | 'mental'
  | 'ophthal'
  | 'dental'
  | 'chronic'
  | 'toxicology'
  | 'urology';

export interface Symptom {
  id: string;
  label: string;
  system: FacilitySystem;
  floor: TriageLevel;
}

export const SYMPTOMS: Symptom[] = [
  { id: 'breathing', label: 'Breathing', system: 'respiratory', floor: 'orange' },
  { id: 'chest', label: 'Chest or heart', system: 'cardiac', floor: 'orange' },
  { id: 'bleeding', label: 'Bleeding', system: 'trauma', floor: 'yellow' },
  { id: 'burns', label: 'Burns', system: 'burns', floor: 'orange' },
  { id: 'injury', label: 'Injury or fall', system: 'trauma', floor: 'yellow' },
  { id: 'head', label: 'Head or neck', system: 'neuro', floor: 'yellow' },
  { id: 'headache', label: 'Headache', system: 'neuro', floor: 'green' },
  { id: 'abdominal', label: 'Stomach or gut', system: 'abdominal', floor: 'yellow' },
  { id: 'fever', label: 'Fever or flu', system: 'infection', floor: 'green' },
  { id: 'rash', label: 'Skin or rash', system: 'derm', floor: 'green' },
  { id: 'pregnancy', label: 'Pregnancy', system: 'obstetric', floor: 'orange' },
  { id: 'child', label: 'A sick child', system: 'paediatric', floor: 'yellow' },
  { id: 'mental', label: 'Mental health', system: 'mental', floor: 'yellow' },
  { id: 'eye', label: 'Eyes', system: 'ophthal', floor: 'yellow' },
  { id: 'dental', label: 'Teeth', system: 'dental', floor: 'green' },
  { id: 'chronic', label: 'Chronic medicine', system: 'chronic', floor: 'green' },
  { id: 'poisoning', label: 'Poisoning or bite', system: 'toxicology', floor: 'red' },
  { id: 'urinary', label: 'Waterworks', system: 'urology', floor: 'green' },
];

/* --- Which facility types can actually treat each system. --- */

export type FacilityType = 'emergency' | 'clinic' | 'doctor' | 'pharmacy';

export const CAPABILITY: Record<FacilitySystem, FacilityType[]> = {
  respiratory: ['emergency', 'clinic', 'doctor'],
  cardiac: ['emergency'],
  trauma: ['emergency', 'clinic'],
  burns: ['emergency'],
  neuro: ['emergency', 'clinic', 'doctor'],
  abdominal: ['emergency', 'clinic', 'doctor'],
  infection: ['clinic', 'doctor', 'pharmacy'],
  derm: ['doctor', 'clinic', 'pharmacy'],
  obstetric: ['emergency', 'clinic'],
  paediatric: ['emergency', 'clinic', 'doctor'],
  mental: ['doctor', 'clinic'],
  ophthal: ['doctor', 'clinic'],
  dental: ['doctor'],
  chronic: ['pharmacy', 'doctor'],
  toxicology: ['emergency'],
  urology: ['doctor', 'clinic', 'pharmacy'],
};

export function rank(level: TriageLevel): number {
  return LEVELS.indexOf(level);
}

export function escalate(current: TriageLevel, candidate: TriageLevel): TriageLevel {
  return rank(candidate) > rank(current) ? candidate : current;
}

export type AgeBand = 'infant' | 'child' | 'adult' | 'older';

export interface AssessInput {
  selfReported?: TriageLevel;
  ageBand?: AgeBand;
  pregnant?: boolean;
  symptoms?: string[];
  discriminators?: string[];
  observations?: Observations;
}

export interface AssessResult {
  level: TriageLevel;
  meta: LevelMeta;
  score: number;
  reasons: string[];
  facilityTypes: FacilityType[];
  callAmbulance: boolean;
  selfTransportSafe: boolean;
  reference: string;
}

/**
 * assess(input) -> result
 * input = {
 *   selfReported : 'red'|'orange'|'yellow'|'green',
 *   ageBand      : 'infant'|'child'|'adult'|'older',
 *   pregnant     : bool,
 *   symptoms     : [symptomId],
 *   discriminators: [discriminatorId],
 *   observations : { mobility, breathing, consciousness, bleeding, temperature, trauma, pain }
 * }
 */
export function assess(input: AssessInput = {}): AssessResult {
  const obs = input.observations ?? {};
  const reasons: string[] = [];

  /* 1 — TEWS-lite score */
  let score = 0;
  (Object.keys(WEIGHTS) as Array<keyof typeof WEIGHTS>).forEach((k) => {
    const v = obs[k as keyof Observations] as string | undefined;
    const weightsForKey = WEIGHTS[k] as Record<string, number>;
    if (v && weightsForKey[v] != null) score += weightsForKey[v];
  });
  if (obs.pain != null) score += painWeight(Number(obs.pain));

  // Infants and the frail decompensate faster — SATS uses separate
  // paediatric charts; this is the simplified stand-in.
  if (input.ageBand === 'infant') score += 2;
  else if (input.ageBand === 'child' || input.ageBand === 'older') score += 1;
  if (input.pregnant) score += 1;

  let level: TriageLevel = score >= 7 ? 'red' : score >= 5 ? 'orange' : score >= 3 ? 'yellow' : 'green';
  if (score > 0) reasons.push('TEWS-lite score ' + score);

  /* 2 — symptom floors */
  (input.symptoms ?? []).forEach((id) => {
    const s = SYMPTOMS.find((x) => x.id === id);
    if (s && rank(s.floor) > rank(level)) {
      level = s.floor;
      reasons.push(s.label + ' is never below ' + META[s.floor].label);
    }
  });

  /* 3 — discriminators override everything */
  (input.discriminators ?? []).forEach((id) => {
    const d = DISCRIMINATORS.find((x) => x.id === id);
    if (d) {
      const next = escalate(level, d.level);
      if (next !== level) reasons.push(d.label);
      level = next;
    }
  });

  /* 4 — never go below what the patient told us they felt */
  if (input.selfReported && rank(input.selfReported) > rank(level)) {
    level = input.selfReported;
    reasons.push('You told us it feels this serious');
  }

  /* 5 — capability routing */
  const systems = (input.symptoms ?? [])
    .map((id) => SYMPTOMS.find((x) => x.id === id)?.system)
    .filter((s): s is FacilitySystem => Boolean(s));

  const facilities: Partial<Record<FacilityType, true>> = {};
  systems.forEach((sys) => {
    (CAPABILITY[sys] ?? []).forEach((f) => {
      facilities[f] = true;
    });
  });
  if (level === 'red') facilities.emergency = true;

  return {
    level,
    meta: META[level],
    score,
    reasons,
    facilityTypes: Object.keys(facilities) as FacilityType[],
    callAmbulance: level === 'red',
    selfTransportSafe: level === 'yellow' || level === 'green',
    reference: 'TRI-' + Math.random().toString(36).slice(2, 7).toUpperCase(),
  };
}
