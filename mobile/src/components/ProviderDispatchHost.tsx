import React, { useEffect } from 'react';
import * as Notifications from 'expo-notifications';
import IncomingRequestModal from './IncomingRequestModal';
import { AcceptResult, DispatchOfferCard, PROVIDER_ROLES, useDispatchInbox } from '../services/dispatch';

/**
 * Mounted once at the app root for provider roles (driver, doctor,
 * pharmacy, partner): polls the dispatch inbox and shows the full-screen
 * IncomingRequestModal over whatever screen is open. An incoming push
 * (foreground) or a tapped push for a request triggers an immediate poll so
 * the modal opens for that offer if it is still valid.
 */
export default function ProviderDispatchHost({
  role,
  navigate,
}: {
  role?: string | null;
  navigate: (name: string, params?: Record<string, unknown>) => void;
}) {
  const enabled = !!role && PROVIDER_ROLES.includes(role);
  const { offers, recent, sound, pollNow } = useDispatchInbox(enabled, role === 'third_party' || role === 'pharmacy_admin');

  useEffect(() => {
    if (!enabled) return;
    const isOffer = (data: any) => data && (data.type === 'dispatch_offer' || data.offer_key || data.route === 'driver_offer' || data.route === 'doctor_offer');
    const rx = Notifications.addNotificationReceivedListener((n) => {
      if (isOffer(n.request.content.data)) pollNow();
    });
    const tap = Notifications.addNotificationResponseReceivedListener((r) => {
      if (isOffer(r.notification.request.content.data)) pollNow();
    });
    return () => {
      rx.remove();
      tap.remove();
    };
  }, [enabled, pollNow]);

  if (!enabled) return null;

  function onAccepted(res: AcceptResult, card: DispatchOfferCard) {
    setTimeout(() => {
      const next = res.next;
      if (card.service === 'shuttle') navigate('Main', { screen: 'Drive', params: { screen: 'Trip' } });
      // Video: straight into the in-app call (the patient's phone is ringing). Call back: the call card.
      else if (role === 'doctor' && card.service === 'doctor_now' && next?.appointment_id && next?.mode === 'video')
        navigate('VideoCall', { appointmentId: next.appointment_id, name: next.patient_name ?? card.patient_first_name ?? 'Patient' });
      else if (role === 'doctor' && card.service === 'doctor_now' && next?.appointment_id && next?.mode === 'callback')
        navigate('ConsultCard', { appointmentId: next.appointment_id });
      else if (role === 'doctor') navigate('Main', { screen: 'DoctorDashboard', params: { screen: 'DoctorDashboardHome' } });
      else if (role === 'pharmacy_admin') navigate('Main', { screen: card.service === 'script_review' ? 'Scripts' : 'Orders' });
      else if (role === 'third_party') navigate('Main', { screen: 'Requests' });
    }, 1300);
  }

  return <IncomingRequestModal offers={offers} recent={recent} sound={sound} onAccepted={onAccepted} onChanged={pollNow} />;
}
