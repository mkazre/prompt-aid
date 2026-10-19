import { api } from '../api/client';

/**
 * Orange triage "speak to a doctor now" after a doctor has the consult
 * (backend: App\Http\Controllers\ConsultCallController).
 *  - Patients: the incoming video call ("Dr X is calling you") — polled by
 *    PatientCallHost and returned inside the doctor-match payload as `ring`.
 *  - Doctors: the call-back card (Call / WhatsApp / outcomes), Start
 *    (rings the patient) and "start video instead".
 */

export interface RingPayload {
  appointment_id: number;
  mode: 'video';
  doctor_name: string;
  doctor_specialization: string | null;
  doctor_photo_url: string | null;
  call_url: string | null;
  auto_join_seconds: number;
  started_at: string;
  seconds_left: number;
}

export type CallbackChannel = 'phone' | 'whatsapp' | 'either';
export type CallOutcome = 'completed' | 'no_answer' | 'escalated';

export interface CallbackInfo {
  phone: string | null;
  phone_display: string | null;
  channel: CallbackChannel;
  channel_label: string;
  note: string | null;
  target_minutes: number;
  due_at: string | null;
  overdue: boolean;
  called_at: string | null;
  call_attempts: number;
  outcome: CallOutcome | null;
  outcome_at: string | null;
  tel_url?: string | null;
  whatsapp_url?: string | null;
  whatsapp_note?: string;
}

export interface TriageSummary {
  id: number;
  reference: string;
  level: 'red' | 'orange' | 'yellow' | 'green';
  level_label: string;
  age_band: string | null;
  pregnant: boolean;
  symptoms: string[];
  discriminators: string[];
  reasons: string[];
}

export interface ConsultCard {
  appointment_id: number;
  booking_ref: string | null;
  mode: 'callback' | 'video' | 'in_person';
  status: string;
  is_open: boolean;
  reason: string | null;
  when: string;
  patient: { name: string; first_name: string; age: number | null; sex: string | null; allergies: string | null; chronic_conditions: string | null };
  triage: TriageSummary | null;
  callback: CallbackInfo | null;
  can_start_video: boolean;
  ringing: boolean;
}

export async function getRing(): Promise<RingPayload | null> {
  const { data } = await api.get('/consults/ring');
  return (data?.data ?? null) as RingPayload | null;
}

export async function answerRing(appointmentId: number): Promise<void> {
  await api.post(`/consults/${appointmentId}/ring/answer`);
}

export async function declineRing(appointmentId: number): Promise<void> {
  await api.post(`/consults/${appointmentId}/ring/decline`);
}

export async function getConsultCard(appointmentId: number): Promise<ConsultCard> {
  const { data } = await api.get(`/consults/${appointmentId}/card`);
  return data.data as ConsultCard;
}

/** Doctor pressed Start: video → the patient's phone rings; call back → the card. */
export async function startConsult(appointmentId: number): Promise<{ card: ConsultCard; rang: boolean }> {
  const { data } = await api.post(`/consults/${appointmentId}/start`);
  return { card: data.data as ConsultCard, rang: !!data.rang };
}

export async function markCalling(appointmentId: number, via: 'phone' | 'whatsapp'): Promise<ConsultCard> {
  const { data } = await api.post(`/consults/${appointmentId}/calling`, { via });
  return data.data as ConsultCard;
}

export async function recordOutcome(appointmentId: number, outcome: CallOutcome): Promise<ConsultCard> {
  const { data } = await api.post(`/consults/${appointmentId}/outcome`, { outcome });
  return data.data as ConsultCard;
}

export async function switchToVideo(appointmentId: number): Promise<ConsultCard> {
  const { data } = await api.post(`/consults/${appointmentId}/video`);
  return data.data as ConsultCard;
}

/** Display a South African number as 082 123 4567 (input may be +27…, 27…, 0…). */
export function displayPhone(raw?: string | null): string {
  const d = String(raw ?? '').replace(/\D+/g, '');
  const local = d.startsWith('27') && d.length === 11 ? `0${d.slice(2)}` : d;
  return local.length === 10 ? `${local.slice(0, 3)} ${local.slice(3, 6)} ${local.slice(6)}` : String(raw ?? '');
}
