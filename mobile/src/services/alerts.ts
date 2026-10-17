import { useEffect, useRef } from 'react';
import { AppState, Platform } from 'react-native';
import * as Notifications from 'expo-notifications';
import * as Device from 'expo-device';
import Constants from 'expo-constants';
import { api } from '../api/client';

/**
 * Phone alerts with sound for every role (new booking for a doctor, trip
 * offer for a driver, "doctor matched" for a patient, admin broadcasts…).
 *
 * 1. Push: registers this device's Expo push token with the backend
 *    (POST /push-tokens); the server pushes on every in-app notification
 *    (App\Services\Notifications\ExpoPush). Works with the app closed.
 * 2. Fallback while the app is open: polls /notifications/unread-count and
 *    raises a local notification (sound + banner) for anything new — so
 *    alerts still sound even before push credentials are set up.
 */

export const ALERT_CHANNEL = 'alerts';
/**
 * Incoming provider requests (trip / consult / order / lab offers): max
 * importance with the bundled request_alert.wav (registered in app.json's
 * expo-notifications "sounds"). The server pushes offers on this channel
 * (App\Services\Dispatch\ProviderAlerts). Replace the placeholder clip at
 * mobile/assets/sounds/request_alert.wav with the client's sound — Android
 * keeps a channel's sound once created, so bump REQUEST_CHANNEL if it changes.
 */
export const REQUEST_CHANNEL = 'requests';

Notifications.setNotificationHandler({
  handleNotification: async () => ({
    shouldShowAlert: true,
    shouldShowBanner: true,
    shouldShowList: true,
    shouldPlaySound: true,
    shouldSetBadge: true,
  }),
});

let registeredToken: string | null = null;

async function ensureChannel() {
  if (Platform.OS !== 'android') return;
  await Notifications.setNotificationChannelAsync(ALERT_CHANNEL, {
    name: 'Bookings, trips & alerts',
    importance: Notifications.AndroidImportance.MAX,
    sound: 'default',
    vibrationPattern: [0, 300, 200, 300],
    lightColor: '#D0211C',
    lockscreenVisibility: Notifications.AndroidNotificationVisibility.PUBLIC,
    enableVibrate: true,
  });
  await Notifications.setNotificationChannelAsync(REQUEST_CHANNEL, {
    name: 'Incoming requests (trips, consults, orders)',
    importance: Notifications.AndroidImportance.MAX,
    sound: 'request_alert.wav',
    vibrationPattern: [0, 600, 400, 600, 400, 600],
    lightColor: '#D0211C',
    lockscreenVisibility: Notifications.AndroidNotificationVisibility.PUBLIC,
    enableVibrate: true,
    bypassDnd: true,
  });
}

/** Ask permission and register for push. Returns true when push is active. */
export async function registerForAlerts(): Promise<boolean> {
  try {
    await ensureChannel();
    const current = await Notifications.getPermissionsAsync();
    let granted = current.granted;
    if (!granted && current.canAskAgain) {
      granted = (await Notifications.requestPermissionsAsync()).granted;
    }
    if (!granted || !Device.isDevice) return false;

    const projectId = (Constants.expoConfig?.extra as any)?.eas?.projectId ?? (Constants as any).easConfig?.projectId;
    const { data: token } = await Notifications.getExpoPushTokenAsync(projectId ? { projectId } : undefined);
    if (!token) return false;
    await api.post('/push-tokens', { token, platform: Platform.OS, device_name: Device.deviceName ?? undefined });
    registeredToken = token;
    return true;
  } catch {
    // e.g. Android without Firebase credentials yet — the in-app poller covers it.
    return false;
  }
}

/** Call on logout so the next person on this phone doesn't get the old user's alerts. */
export async function unregisterAlerts() {
  if (!registeredToken) return;
  try {
    await api.delete('/push-tokens', { data: { token: registeredToken } });
  } catch {
    /* ignore */
  }
  registeredToken = null;
}

/**
 * Mount once while signed in. `onOpen` receives the tapped notification's
 * data (e.g. { url, type, route, params }).
 */
export function useAlerts(enabled: boolean, onOpen: (data: Record<string, any>) => void) {
  const pushActive = useRef(false);
  const lastCount = useRef<number | null>(null);
  const lastNotes = useRef<number | null>(null);
  const openRef = useRef(onOpen);
  openRef.current = onOpen;

  useEffect(() => {
    if (!enabled) return;
    let alive = true;

    registerForAlerts().then((ok) => {
      pushActive.current = ok;
    });

    const tapSub = Notifications.addNotificationResponseReceivedListener((response) => {
      openRef.current((response.notification.request.content.data ?? {}) as Record<string, any>);
    });

    async function poll() {
      if (AppState.currentState !== 'active') return;
      try {
        const { data } = await api.get('/notifications/unread-count');
        const notes = Number(data?.notifications ?? 0);
        const messages = Number(data?.messages ?? 0);
        const count = notes + messages;
        if (lastCount.current !== null && count > lastCount.current && !pushActive.current) {
          const prevNotes = lastNotes.current ?? notes;
          let title = 'New message';
          let body: string | undefined = 'You have a new chat message.';
          let payload: Record<string, any> = { route: 'ChatList' };
          if (notes > prevNotes) {
            const latest = await api.get('/notifications', { params: { per_page: 5 } }).catch(() => null);
            const item = latest?.data?.data?.[0];
            title = item?.title ?? 'Prompt Aid';
            body = item?.body ?? undefined;
            payload = item?.data ?? {};
          }
          const isRequest = payload?.type === 'dispatch_offer' || !!payload?.offer_key;
          await Notifications.scheduleNotificationAsync({
            content: { title, body, sound: isRequest ? 'request_alert.wav' : 'default', data: payload },
            trigger: Platform.OS === 'android' ? ({ channelId: isRequest ? REQUEST_CHANNEL : ALERT_CHANNEL } as any) : null,
          });
        }
        lastNotes.current = notes;
        if (alive) lastCount.current = count;
      } catch {
        /* offline */
      }
    }

    poll();
    const timer = setInterval(poll, 20000);
    return () => {
      alive = false;
      clearInterval(timer);
      tapSub.remove();
    };
  }, [enabled]);
}
