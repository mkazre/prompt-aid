import { api } from '../api/client';

/**
 * Orange "practice call": after a GP practice accepts an urgent request, an
 * in-app voice call rings BOTH the practice (reception / GP) and the patient;
 * each side accepts. During the call the practice books the appointment
 * (in person / video) and can offer a shuttle the patient chooses and pays.
 * Server: App\Http\Controllers\PracticeCallController (/api/practice-calls/*).
 */

export interface PracticeRing {
  call_id: number;
  uuid: string;
  kind: 'practice_call';
  channel: 'voice';
  side: 'patient' | 'practice';
  caller_name: string;
  caller_sub?: string | null;
  recorded: boolean;
  notice?: string | null;
  started_at: string | null;
  seconds_left: number;
  url: string;
}

export interface BookingCard {
  appointment_id: number;
  booking_ref: string;
  status: string;
  mode: 'in_person' | 'video';
  mode_label: string;
  date: string;
  start_time: string;
  end_time: string;
  starts_at: string;
  when_label: string;
  doctor_id: number | null;
  doctor_name: string | null;
  practice_name: string | null;
  practice_address: string | null;
  reason: string | null;
  notes?: string | null;
}

export interface ShuttleQuote {
  vehicle_type: string;
  label: string;
  fare: number;
  distance_km: number;
  eta_minutes: number;
  surge_label?: string | null;
  quote_token?: string;
}

export interface ShuttleOffer {
  status: 'offered' | 'chosen';
  pickup: { lat: number; lng: number; address: string; source: string } | null;
  dropoff: { lat: number; lng: number; address: string } | null;
  return_leg: boolean;
  quotes: ShuttleQuote[];
  vehicle_type: string | null;
  fare: number | null;
  pickup_at: string | null;
  ride: { id: number; ride_ref: string; status: string; scheduled_for: string | null; fare: number; return_ride_id: number | null } | null;
  payment: { required: boolean; state: string; amount: number; payable_type: string; payable_id: number } | null;
}

export interface PracticeCallState {
  id: number;
  role: 'patient' | 'practice';
  status: 'ringing' | 'connected' | 'ended' | 'declined' | 'missed' | 'cancelled';
  status_text: string;
  needs_my_accept: boolean;
  practice_accepted: boolean;
  patient_accepted: boolean;
  connected: boolean;
  can_ring_again: boolean;
  newer_call_id: number | null;
  ring_seconds_left: number;
  connected_at: string | null;
  duration_seconds: number;
  outcome: string | null;
  outcome_label: string;
  server_time: string;
  recorded: boolean;
  notice: string | null;
  practice: { name: string; address: string | null; phone: string | null; user_name: string | null };
  patient: { name: string; phone: string | null; brief: string[] | null; location: { lat: number; lng: number; label: string } | null };
  doctor: { id: number; name: string; specialization: string | null } | null;
  booking: BookingCard | null;
  booking_options: {
    modes: ('in_person' | 'video')[];
    default_mode: 'in_person' | 'video';
    shuttle: boolean;
    doctors: { id: number; name: string; specialization: string | null; availability: string }[];
    default_doctor_id: number | null;
    today: string;
  } | null;
  shuttle: ShuttleOffer | null;
  join: { embed_url?: string; expires_at?: string; counterpart?: string; error?: string } | null;
}

export interface SlotDay {
  doctor_id: number;
  date: string;
  time_off: string | null;
  sessions: { label: string; start: string; end: string; slot_minutes: number; slots: { time: string; available: boolean; booked: boolean; past: boolean; blocked: boolean }[] }[];
  free: string[];
}

/** Patient or practice user: is a practice call ringing for me? (cheap, cache-only on the server) */
export async function getPracticeRing(): Promise<PracticeRing | null> {
  try {
    const { data } = await api.get('/practice-calls/ring', { timeout: 8000 });
    return (data?.data ?? null) as PracticeRing | null;
  } catch {
    return null; // older server: no practice calls
  }
}

export async function getPracticeCall(id: number): Promise<PracticeCallState> {
  const { data } = await api.get(`/practice-calls/${id}`);
  return data.data as PracticeCallState;
}

export async function listPracticeCalls(): Promise<{ id: number; status: string; outcome_label: string; patient_name: string | null; practice_name: string | null; ringing_at: string | null; duration_seconds: number; booked: boolean; open: boolean }[]> {
  const { data } = await api.get('/practice-calls');
  return data.data ?? [];
}

const post = async (id: number, path: string, body: Record<string, unknown> = {}): Promise<PracticeCallState> => {
  const { data } = await api.post(`/practice-calls/${id}/${path}`, body);
  return data.data as PracticeCallState;
};

export const acceptPracticeCall = (id: number) => post(id, 'accept');
export const declinePracticeCall = (id: number, reason?: string) => post(id, 'decline', reason ? { reason } : {});
export const endPracticeCall = (id: number, closeRequest = false) => post(id, 'end', { close_request: closeRequest });
export const ringPatientAgain = (id: number) => post(id, 'ring-again');
export const bookOnCall = (id: number, body: { doctor_profile_id: number; date: string; start_time: string; mode: 'in_person' | 'video'; reason?: string; notes?: string; outside_sessions?: boolean }) => post(id, 'booking', body);
export const offerShuttle = (id: number, returnLeg: boolean) => post(id, 'shuttle/quote', { return_leg: returnLeg });
export const chooseShuttle = (id: number, vehicleType: string) => post(id, 'shuttle/choose', { vehicle_type: vehicleType });

export async function practiceCallPresence(id: number, state: 'in' | 'out'): Promise<void> {
  await api.post(`/practice-calls/${id}/presence`, { state }).catch(() => undefined);
}

export async function sharePracticeCallLocation(id: number, lat: number, lng: number): Promise<void> {
  await api.post(`/practice-calls/${id}/location`, { lat, lng, label: 'My current location' });
}

export async function getCallSlots(id: number, doctorId: number, date: string): Promise<SlotDay> {
  const { data } = await api.get(`/practice-calls/${id}/slots`, { params: { doctor_id: doctorId, date } });
  return data.data as SlotDay;
}

export function fmtDuration(sec: number): string {
  const m = Math.floor(sec / 60);
  const s = sec % 60;
  return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
}
