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
  /** Consult hub servers: video | voice (in-app, audio-only). Older servers: absent = video. */
  channel?: HubCallChannel;
  hub_url?: string | null;
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

// ---------------------------------------------------------------------------
// Consult hub (backend: App\Http\Controllers\ConsultHubController,
// /api/consult-hub/{appointment}). One consult for both sides once a doctor
// has it: video (default) → in-app voice → chat; phone / WhatsApp fallback.
// ---------------------------------------------------------------------------

export type HubCallChannel = 'video' | 'voice';
export type HubChannel = HubCallChannel | 'chat' | 'phone' | 'whatsapp';

export interface HubSegment {
  channel: HubChannel;
  label: string;
  started_at: string | null;
  ended_at: string | null;
  seconds: number;
}

export interface HubOutcome {
  value: string;
  label: string;
}

export interface ConsultHub {
  appointment_id: number;
  booking_ref: string | null;
  role: 'patient' | 'doctor';
  mode: 'video' | 'callback';
  status: string;
  is_open: boolean;
  status_text: string;
  server_time: string;
  doctor: { name: string; specialization: string | null; qualification: string | null; rating: number; rating_count: number; photo_url: string | null };
  consult: {
    state: 'waiting' | 'connected' | 'ended';
    opened_at: string | null;
    connected_at: string | null;
    ended_at: string | null;
    first_channel: HubChannel | null;
    current_channel: HubChannel | null;
    connected_seconds: number;
    live_call: boolean;
    outcome: string | null;
    outcome_label: string | null;
    ended_by: string | null;
    channels: HubSegment[];
  };
  presence: { doctor: HubCallChannel | null; patient: HubCallChannel | null };
  ring: RingPayload | null;
  ringing: HubCallChannel | null;
  default_channel: 'video' | 'callback';
  channels: {
    video: { available: boolean; reason: string | null };
    voice: { available: boolean; reason: string | null };
    chat: { available: boolean; conversation_id: number | null };
    phone: { available: boolean };
    whatsapp: { available: boolean };
  };
  callback_requested: boolean;
  callback: Partial<CallbackInfo> | null;
  urls: { hub: string | null; request: string | null };
  // doctor only
  patient?: {
    name: string;
    first_name: string;
    age: number | null;
    sex: string | null;
    blood_group: string | null;
    allergies: string | null;
    chronic_conditions: string | null;
    medications: string[];
    reason: string | null;
    emergency_contact: string | null;
  };
  triage?: TriageSummary | null;
  fallback?: { phone: string | null; phone_display: string | null; tel_url: string | null; whatsapp_url: string | null; whatsapp_note: string };
  outcomes?: HubOutcome[];
  can_end?: boolean;
  // patient only
  emergency?: { phone: string | null; tel_url: string | null } | null;
}

export async function getHub(appointmentId: number): Promise<ConsultHub> {
  const { data } = await api.get(`/consult-hub/${appointmentId}`);
  return data.data as ConsultHub;
}

/** Heartbeat while this side is inside the in-app call (both in = connected). */
export async function hubPresence(appointmentId: number, channel: HubCallChannel, state: 'in' | 'out' = 'in'): Promise<ConsultHub> {
  const { data } = await api.post(`/consult-hub/${appointmentId}/presence`, { channel, state });
  return data.data as ConsultHub;
}

/** Doctor: Start video / Start voice — rings the patient with that channel. */
export async function hubStartCall(appointmentId: number, channel: HubCallChannel): Promise<ConsultHub> {
  const { data } = await api.post(`/consult-hub/${appointmentId}/start`, { channel });
  return data.data as ConsultHub;
}

/** Doctor: tapped Call / WhatsApp in the fallback block. */
export async function hubCalling(appointmentId: number, via: 'phone' | 'whatsapp'): Promise<ConsultHub> {
  const { data } = await api.post(`/consult-hub/${appointmentId}/calling`, { via });
  return data.data as ConsultHub;
}

/** Doctor: the patient answered the phone / WhatsApp call. */
export async function hubConnected(appointmentId: number, via: 'phone' | 'whatsapp'): Promise<ConsultHub> {
  const { data } = await api.post(`/consult-hub/${appointmentId}/connected`, { via });
  return data.data as ConsultHub;
}

export async function hubEnd(appointmentId: number, outcome: string, notes?: string): Promise<ConsultHub> {
  const { data } = await api.post(`/consult-hub/${appointmentId}/end`, { outcome, notes: notes || null });
  return data.data as ConsultHub;
}

/** Patient fallback: ask the doctor to phone / WhatsApp-call them. */
export async function hubRequestCallback(appointmentId: number, via: 'phone' | 'whatsapp', phone?: string): Promise<ConsultHub> {
  const { data } = await api.post(`/consult-hub/${appointmentId}/request-callback`, { via, phone: phone || null });
  return data.data as ConsultHub;
}
