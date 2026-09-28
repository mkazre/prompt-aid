import { useEffect, useState } from 'react';
import { api } from '../api/client';
import type { EmergencyContact } from '../api/types';

/**
 * Admin-configurable replacement for the numbers that used to be hardcoded
 * across the triage screens (see backend/app/Models/EmergencyContact.php).
 * Falls back to 10177 — the previous hardcoded value — if the API is
 * unreachable, so the "call ambulance" button never goes dead offline.
 */
const FALLBACK_PRIMARY: EmergencyContact = {
  id: 0,
  label: 'Ambulance',
  phone: '10177',
  tel_url: 'tel:10177',
  is_primary: true,
  sort_order: 0,
};

export function useEmergencyContacts() {
  const [contacts, setContacts] = useState<EmergencyContact[]>([FALLBACK_PRIMARY]);

  useEffect(() => {
    let alive = true;
    api
      .get('/emergency-contacts')
      .then(({ data }) => {
        if (alive && Array.isArray(data.data) && data.data.length) setContacts(data.data);
      })
      .catch(() => {
        /* offline/unreachable — keep the 10177 fallback */
      });
    return () => {
      alive = false;
    };
  }, []);

  const primary = contacts.find((c) => c.is_primary) ?? contacts[0] ?? FALLBACK_PRIMARY;
  return { contacts, primary };
}
