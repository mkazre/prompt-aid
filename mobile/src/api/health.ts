import { api } from './client';

/**
 * Patient self-care API (backend routes/api_health.php): favourite doctors,
 * family members, medications, vitals, medical ID, reschedule, calendar.
 */

export type HealthFeature = 'favourites' | 'family' | 'medications' | 'vitals' | 'medical_id' | 'reschedule' | 'calendar' | 'medication_reminders';

export interface VitalRange {
  label: string;
  unit: string;
  normal_min: number;
  normal_max: number;
  critical_min: number;
  critical_max: number;
}

export interface HealthConfig {
  features: Record<HealthFeature, boolean>;
  vital_ranges: Record<string, VitalRange>;
  vitals_note: string;
  medical_id_note: string;
  relationships: Record<string, string>;
  blood_groups: string[];
}

export interface FavouriteDoctor {
  id: number;
  name: string;
  specialization: string | null;
  avatar_url: string | null;
  rating_avg: number;
  rating_count: number;
  consultation_fee: number;
  clinic: string | null;
  live_status: string | null;
}

export interface FamilyMember {
  id: number;
  name: string;
  relationship: string;
  relationship_label: string;
  dob: string | null;
  age: number | null;
  gender: string | null;
  id_number: string | null;
  medical_aid_scheme: string | null;
  medical_aid_number: string | null;
  allergies: string | null;
}

export type DoseStatus = 'taken' | 'due' | 'missed' | 'upcoming';

export interface DoseToday {
  time: string;
  status: DoseStatus;
  taken_at: string | null;
}

export interface Medication {
  id: number;
  name: string;
  dose: string;
  times: string[];
  start_date: string;
  end_date: string | null;
  notes: string | null;
  reminders_enabled: boolean;
  active: boolean;
  today: DoseToday[];
}

export interface MedicationsPayload {
  data: Medication[];
  today: (DoseToday & { medication_id: number; name: string; dose: string })[];
  taken_log: { medication: string; dose: string; date: string; time: string; taken_at: string }[];
  reminders_enabled: boolean;
  local_date: string;
  local_time: string;
}

export type VitalMetric = 'systolic' | 'diastolic' | 'pulse' | 'glucose' | 'weight' | 'temperature' | 'spo2';

export interface VitalReading {
  id: number;
  recorded_at: string;
  systolic: number | null;
  diastolic: number | null;
  pulse: number | null;
  glucose: number | null;
  weight: number | null;
  temperature: number | null;
  spo2: number | null;
  note: string | null;
  flags: Partial<Record<VitalMetric, 'orange' | 'red'>>;
}

export interface VitalsPayload {
  data: VitalReading[];
  series: Partial<Record<VitalMetric, number[]>>;
  ranges: Record<string, VitalRange>;
  note: string;
}

export interface MedicalId {
  name: string;
  initials: string;
  photo_url: string | null;
  dob: string | null;
  age: number | null;
  gender: string | null;
  phone: string | null;
  blood_group: string | null;
  allergies: string | null;
  chronic_conditions: string | null;
  medications: { name: string; dose: string; times: string[] }[];
  emergency_contact: { name: string | null; phone: string | null };
  emergency_numbers: { label: string; phone: string; is_primary: boolean }[];
  medical_aid: { scheme: string | null; member_number: string; dependant_code: string | null; main_member: string | null } | null;
  note: string;
}

let configCache: { at: number; value: HealthConfig } | null = null;

/** Feature switches + ranges (cached for a minute). */
export async function getHealthConfig(force = false): Promise<HealthConfig> {
  if (!force && configCache && Date.now() - configCache.at < 60_000) return configCache.value;
  const { data } = await api.get<HealthConfig>('/health/config');
  configCache = { at: Date.now(), value: data };
  return data;
}

export const VITAL_META: Record<VitalMetric, { label: string; unit: string; icon: string; color: string }> = {
  systolic: { label: 'Systolic BP', unit: 'mmHg', icon: 'activity', color: '#C8102E' },
  diastolic: { label: 'Diastolic BP', unit: 'mmHg', icon: 'activity', color: '#E4701E' },
  pulse: { label: 'Pulse', unit: 'bpm', icon: 'heart', color: '#D0211C' },
  glucose: { label: 'Glucose', unit: 'mmol/L', icon: 'droplet', color: '#6A4C93' },
  weight: { label: 'Weight', unit: 'kg', icon: 'trending-up', color: '#1F4E7A' },
  temperature: { label: 'Temperature', unit: '°C', icon: 'thermometer', color: '#B4520F' },
  spo2: { label: 'Oxygen (SpO₂)', unit: '%', icon: 'wind', color: '#1F7A4C' },
};

export const FLAG_COLORS = {
  red: { fg: '#C8102E', bg: '#FBEAEA' },
  orange: { fg: '#B4520F', bg: '#FCEEE2' },
} as const;
