import { useCallback, useEffect, useRef, useState } from 'react';
import { AppState } from 'react-native';
import * as Location from 'expo-location';
import { api, apiErrorMessage } from '../api/client';
import { RiderProfile } from '../api/types';

/**
 * Provider inbox (doctor, driver, pharmacy, partner): every live request
 * offered to this provider across services — shuttle trips, see a doctor
 * now, any-doctor-later, appointment confirmations, medicine orders, script
 * reviews and lab requests. Backed by GET /dispatch/inbox, which also acts
 * as the provider's "available now" heartbeat.
 */

export type OfferState = 'pending' | 'taken' | 'expired' | 'cancelled' | 'declined' | 'accepted';

export interface DispatchOfferCard {
  key: string; // "ride:12" | "dm:5" | "job:9"
  service: 'shuttle' | 'doctor_now' | 'doctor_later' | 'appointment' | 'pharmacy_order' | 'script_review' | 'lab_request' | string;
  service_label: string;
  icon: string;
  state: OfferState;
  request_id: number;
  offer_id: number;
  title: string;
  subtitle: string;
  patient_first_name: string | null;
  rider: RiderProfile | null;
  badges: string[];
  lines: string[];
  earning: number | null;
  fare: number | null;
  distance_km: number | null;
  eta_min: number | null;
  trip_km?: number;
  trip_min?: number | null;
  pickup: { address: string; lat: number; lng: number } | null;
  dropoff: { address: string; lat: number; lng: number } | null;
  when: string | null;
  seconds_left: number;
  timeout_s: number;
  expires_at: string | null;
  swipe: boolean;
  url: string | null;
}

export interface ProviderAvailability {
  role: string;
  now: boolean;
  availability: 'available' | 'busy' | 'offline' | string;
  later: { date: string; from: string; to: string } | null;
  tomorrow: { date: string; from: string; to: string } | null;
  weekly: { weekday: number; start: string; end: string }[];
  max_radius_km: number | null;
  default_radius_km: number;
}

export interface InboxResponse {
  version: string;
  server_time: string;
  offers: DispatchOfferCard[];
  recent: DispatchOfferCard[];
  availability: ProviderAvailability;
  sound: boolean;
  poll_ms: number;
}

export interface AcceptResult {
  message: string;
  next?: { type: string; ride_id?: number; request_id?: number; appointment_id?: number; subject_id?: number; url?: string };
}

export const PROVIDER_ROLES = ['doctor', 'driver', 'pharmacy_admin', 'third_party'];

export async function acceptOffer(key: string): Promise<AcceptResult> {
  const { data } = await api.post(`/dispatch/offers/${encodeURIComponent(key)}/accept`);
  return data as AcceptResult;
}

export async function declineOffer(key: string, reason?: string | null): Promise<void> {
  await api.post(`/dispatch/offers/${encodeURIComponent(key)}/decline`, { reason: reason ?? null });
}

export async function getAvailability(): Promise<ProviderAvailability> {
  const { data } = await api.get('/dispatch/availability');
  return data as ProviderAvailability;
}

/** Body keys are all optional: now, later {from,to}|null, tomorrow {from,to}|null, max_radius_km. */
export async function saveAvailability(body: Record<string, unknown>): Promise<ProviderAvailability> {
  const { data } = await api.post('/dispatch/availability', body);
  return data as ProviderAvailability;
}

export { apiErrorMessage };

/**
 * Polls the inbox while the app is in the foreground (3 s with an open
 * offer, 5 s idle). `pollNow()` is called when a push arrives / is tapped.
 */
export function useDispatchInbox(enabled: boolean, shareLocation: boolean) {
  const [offers, setOffers] = useState<DispatchOfferCard[]>([]);
  const [recent, setRecent] = useState<Record<string, DispatchOfferCard>>({});
  const [availability, setAvailability] = useState<ProviderAvailability | null>(null);
  const [sound, setSound] = useState(true);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const coords = useRef<{ lat: number; lng: number } | null>(null);
  const alive = useRef(true);

  const poll = useCallback(async () => {
    if (timer.current) clearTimeout(timer.current);
    let next = 5000;
    try {
      if (AppState.currentState === 'active') {
        const params = coords.current ? { lat: coords.current.lat, lng: coords.current.lng } : undefined;
        const { data } = await api.get('/dispatch/inbox', { params });
        const inbox = data as InboxResponse;
        if (!alive.current) return;
        setOffers(inbox.offers ?? []);
        setRecent((prev) => {
          const merged = { ...prev };
          (inbox.recent ?? []).forEach((r) => {
            merged[r.key] = r;
          });
          return merged;
        });
        setAvailability(inbox.availability ?? null);
        setSound(inbox.sound !== false);
        next = inbox.poll_ms || 5000;
      } else {
        next = 10000;
      }
    } catch {
      next = 8000;
    }
    if (alive.current && enabled) timer.current = setTimeout(poll, next);
  }, [enabled]);

  useEffect(() => {
    alive.current = true;
    if (!enabled) return;
    if (shareLocation) {
      Location.getForegroundPermissionsAsync()
        .then((p) => (p.granted ? Location.getLastKnownPositionAsync() : null))
        .then((pos) => {
          if (pos) coords.current = { lat: pos.coords.latitude, lng: pos.coords.longitude };
        })
        .catch(() => {});
    }
    poll();
    const sub = AppState.addEventListener('change', (s) => {
      if (s === 'active') poll();
    });
    return () => {
      alive.current = false;
      if (timer.current) clearTimeout(timer.current);
      sub.remove();
    };
  }, [enabled, shareLocation, poll]);

  return { offers, recent, availability, sound, pollNow: poll, setOffers };
}
