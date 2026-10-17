import { api } from '../api/client';
import { Ride } from '../api/types';

/** Ride JSON may carry server-computed flags (dispatch contract) and timestamps. */
export type RideLike = Partial<Ride> & {
  id: number;
  status: string;
  is_active?: boolean;
  is_terminal?: boolean;
  updated_at?: string | null;
};

/** Statuses after which a ride is over. */
export const TERMINAL_RIDE_STATUSES = ['completed', 'cancelled', 'no_drivers', 'expired', 'no_show'];

const DAY_MS = 24 * 60 * 60 * 1000;

function ageMs(iso?: string | null): number | null {
  if (!iso) return null;
  const t = new Date(iso).getTime();
  return Number.isFinite(t) ? Date.now() - t : null;
}

/**
 * True only for a ride that is genuinely in progress / upcoming. Uses the
 * server's `is_active` flag when present, else the status, and as a safety
 * net treats anything not touched for 24h (or scheduled more than 24h ago)
 * as stale — so a days-old "requested" ride never resurfaces.
 */
export function isRideActive(r: RideLike | null | undefined): boolean {
  if (!r || !r.id) return false;
  if (r.is_terminal === true) return false;
  const active = r.is_active ?? !TERMINAL_RIDE_STATUSES.includes(String(r.status));
  if (!active) return false;

  const scheduledAge = ageMs(r.scheduled_for);
  if (scheduledAge != null) {
    // Future booking (negative age) is fine; one that was due >24h ago is stale.
    return scheduledAge < DAY_MS;
  }
  const touched = ageMs(r.updated_at ?? r.accepted_at ?? r.requested_at ?? null);
  if (touched != null && touched > DAY_MS) return false;
  return true;
}

/**
 * The patient's truly active ride, or null. Prefers `GET /rides/active`
 * (dispatch contract); falls back to filtering `GET /rides` on older
 * backends. Throws only if both requests fail.
 */
export async function fetchActiveRide(): Promise<RideLike | null> {
  try {
    const { data } = await api.get('/rides/active');
    const ride = data && typeof data === 'object' && 'data' in data ? data.data : data;
    return ride && typeof ride === 'object' && ride.id && isRideActive(ride) ? (ride as RideLike) : null;
  } catch {
    // Endpoint not deployed yet (404) or a transient error — use the list instead.
  }
  const { data } = await api.get('/rides');
  const rides: RideLike[] = Array.isArray(data?.data) ? data.data : Array.isArray(data) ? data : [];
  return rides.find(isRideActive) ?? null;
}
