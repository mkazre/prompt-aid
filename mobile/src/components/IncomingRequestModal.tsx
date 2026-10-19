import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Animated, Modal, PanResponder, Pressable, ScrollView, StyleSheet, Text, Vibration, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { Ionicons } from '@expo/vector-icons';
import { AudioPlayer, createAudioPlayer, setAudioModeAsync } from 'expo-audio';
import { DispatchOfferCard, acceptOffer, apiErrorMessage, declineOffer, AcceptResult } from '../services/dispatch';
import RideMap from './RideMap';
import RiderCard from './RiderCard';
import { pa, paFonts } from '../theme';

/**
 * Full-screen incoming request alert for providers (driver, doctor,
 * pharmacy, partner) — mounted once at the app root so it appears over Home
 * or any tab. One offer at a time (soonest-expiring first, the rest queued).
 *
 *  - Loops assets/sounds/request_alert.wav (plays in silent mode, ducks
 *    other audio) + repeating vibration until accepted / declined / expired
 *    / taken.
 *  - Countdown bar + seconds; request type badges; earning; pickup → drop-off
 *    with distance/ETA; mini route map; rider card (first name, rating,
 *    needs, notes).
 *  - Drivers SWIPE RIGHT to accept (accident-safe: 85% of the track);
 *    others tap Accept. Decline with an optional reason.
 *  - "Accepted by another provider" state when someone else wins.
 *
 * Keeping the screen awake would need expo-keep-awake (not installed — see
 * the dispatch report); the looping sound + vibration wake the driver.
 */

type Phase = 'offer' | 'working' | 'accepted' | 'taken' | 'expired' | 'cancelled' | 'error';

const ALERT_SOUND = require('../../assets/sounds/request_alert.wav');
const VIBRATION = [0, 600, 400, 600, 1200];
const REASONS = ['Too far', 'Busy right now', 'Not my service', 'Other'];

const ICONS: Record<string, keyof typeof Ionicons.glyphMap> = {
  car: 'car-sport',
  stethoscope: 'medkit',
  calendar: 'calendar',
  bag: 'bag-handle',
  file: 'document-text',
  beaker: 'flask',
  bell: 'notifications',
};

interface Props {
  offers: DispatchOfferCard[];
  recent: Record<string, DispatchOfferCard>;
  sound: boolean;
  onAccepted: (result: AcceptResult, card: DispatchOfferCard) => void;
  onChanged: () => void;
  /** Deep-link mode (TripOfferScreen): show just this offer. */
  only?: string | null;
}

export default function IncomingRequestModal({ offers, recent, sound, onAccepted, onChanged, only }: Props) {
  const insets = useSafeAreaInsets();
  const [dismissed, setDismissed] = useState<Record<string, true>>({});
  const [current, setCurrent] = useState<DispatchOfferCard | null>(null);
  const [phase, setPhase] = useState<Phase>('offer');
  const [message, setMessage] = useState('');
  const [secondsLeft, setSecondsLeft] = useState(0);
  const [showReasons, setShowReasons] = useState(false);
  const player = useRef<AudioPlayer | null>(null);
  const deadline = useRef(0);
  const closeTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

  const queue = useMemo(
    () => offers.filter((o) => o.state === 'pending' && o.seconds_left > 0 && !dismissed[o.key] && (!only || o.key === only)),
    [offers, dismissed, only],
  );

  // ---------------------------------------------------------- sound + vibration
  const startAlert = useCallback(async () => {
    Vibration.vibrate(VIBRATION, true);
    if (!sound) return;
    try {
      await setAudioModeAsync({ playsInSilentMode: true, interruptionMode: 'duckOthers', shouldPlayInBackground: false });
      if (!player.current) player.current = createAudioPlayer(ALERT_SOUND);
      player.current.loop = true;
      player.current.volume = 1;
      player.current.seekTo(0).catch(() => {});
      player.current.play();
    } catch {
      /* vibration still runs */
    }
  }, [sound]);

  const stopAlert = useCallback(() => {
    Vibration.cancel();
    try {
      player.current?.pause();
    } catch {
      /* ignore */
    }
  }, []);

  useEffect(
    () => () => {
      stopAlert();
      try {
        player.current?.remove();
      } catch {
        /* ignore */
      }
      player.current = null;
      if (closeTimer.current) clearTimeout(closeTimer.current);
    },
    [stopAlert],
  );

  // ---------------------------------------------------------- pick the next offer
  useEffect(() => {
    if (current && phase !== 'offer') return; // showing a result state
    if (current) {
      const still = offers.find((o) => o.key === current.key && o.state === 'pending');
      if (!still) {
        // It vanished from the live list: taken by someone else, expired or withdrawn.
        const r = recent[current.key];
        finish(r?.state === 'taken' ? 'taken' : r?.state === 'cancelled' ? 'cancelled' : 'expired');
        return;
      }
      return;
    }
    const next = queue[0];
    if (next) {
      setCurrent(next);
      setPhase('offer');
      setShowReasons(false);
      deadline.current = Date.now() + next.seconds_left * 1000;
      setSecondsLeft(next.seconds_left);
      startAlert();
    } else {
      stopAlert();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [offers, recent, queue, current, phase]);

  // ---------------------------------------------------------- countdown
  useEffect(() => {
    if (!current || phase !== 'offer') return;
    const t = setInterval(() => {
      const left = Math.max(0, Math.ceil((deadline.current - Date.now()) / 1000));
      setSecondsLeft(left);
      if (left <= 0) finish('expired');
    }, 250);
    return () => clearInterval(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [current?.key, phase]);

  function finish(state: Phase, msg = '') {
    stopAlert();
    setPhase(state);
    setMessage(msg);
    if (current) setDismissed((d) => ({ ...d, [current.key]: true }));
    if (closeTimer.current) clearTimeout(closeTimer.current);
    closeTimer.current = setTimeout(
      () => {
        setCurrent(null);
        setPhase('offer');
        onChanged();
      },
      state === 'accepted' ? 1200 : 2600,
    );
  }

  async function accept() {
    if (!current || phase !== 'offer') return;
    setPhase('working');
    stopAlert();
    try {
      const res = await acceptOffer(current.key);
      Vibration.vibrate(80);
      finish('accepted', res.message);
      onAccepted(res, current);
    } catch (e: any) {
      const status = e?.response?.status;
      const msg = apiErrorMessage(e);
      if (status === 409) finish(/another/i.test(msg) ? 'taken' : 'expired', msg);
      else finish('error', msg);
    }
  }

  async function decline(reason?: string) {
    if (!current) return;
    const key = current.key;
    stopAlert();
    setDismissed((d) => ({ ...d, [key]: true }));
    setCurrent(null);
    setPhase('offer');
    try {
      await declineOffer(key, reason ?? null);
    } catch {
      /* offer may already be gone */
    }
    onChanged();
  }

  if (!current) return null;
  const c = current;
  const total = Math.max(1, c.timeout_s || c.seconds_left || 30);
  const pct = Math.max(0, Math.min(1, secondsLeft / total));

  return (
    <Modal visible animationType="slide" transparent={false} onRequestClose={() => decline()} statusBarTranslucent>
      <View style={[styles.screen, { paddingTop: insets.top + 8, paddingBottom: insets.bottom + 12 }]}>
        {phase === 'offer' || phase === 'working' ? (
          <>
            <View style={styles.head}>
              <View style={styles.iconWrap}>
                <Ionicons name={ICONS[c.icon] ?? 'notifications'} size={26} color="#fff" />
              </View>
              <View style={{ flex: 1 }}>
                <Text style={styles.kicker}>{c.service_label}</Text>
                <Text style={styles.title} numberOfLines={2}>
                  {c.title}
                </Text>
                {queue.length > 1 ? <Text style={styles.queue}>+{queue.length - 1} more waiting</Text> : null}
              </View>
              <View style={styles.timer}>
                <Text style={styles.timerNum}>{secondsLeft > 99 ? `${Math.ceil(secondsLeft / 60)}m` : secondsLeft}</Text>
                <Text style={styles.timerLbl}>sec</Text>
              </View>
            </View>
            <View style={styles.barTrack}>
              <View style={[styles.barFill, { width: `${pct * 100}%`, backgroundColor: pct < 0.3 ? pa.signal : pa.beacon }]} />
            </View>

            <ScrollView style={{ flex: 1 }} contentContainerStyle={{ padding: 16 }}>
              {c.badges?.length ? (
                <View style={styles.badges}>
                  {c.badges.map((b) => (
                    <View key={b} style={[styles.badge, /emergency|urgent/i.test(b) && styles.badgeRed]}>
                      <Text style={styles.badgeText}>{b}</Text>
                    </View>
                  ))}
                </View>
              ) : null}

              <View style={styles.money}>
                <View>
                  <Text style={styles.moneyLbl}>{c.earning != null ? (c.service === 'shuttle' ? 'Trip earning' : 'Fee') : 'Patient'}</Text>
                  <Text style={styles.moneyVal}>{c.earning != null ? `R ${Math.round(c.earning)}` : c.patient_first_name ?? ''}</Text>
                </View>
                <View style={{ alignItems: 'flex-end', flexShrink: 1 }}>
                  {c.distance_km != null ? <Text style={styles.metaStrong}>{c.distance_km.toFixed(1)} km away</Text> : null}
                  {c.eta_min != null ? <Text style={styles.meta}>{c.eta_min} min to pickup</Text> : null}
                  {c.trip_km ? (
                    <Text style={styles.meta}>
                      Trip {c.trip_km.toFixed(1)} km{c.trip_min ? ` · ~${c.trip_min} min` : ''}
                    </Text>
                  ) : null}
                  {c.when ? <Text style={styles.meta}>{new Date(c.when).toLocaleString([], { weekday: 'short', hour: '2-digit', minute: '2-digit' })}</Text> : null}
                </View>
              </View>

              {c.service !== 'shuttle' && c.subtitle ? <Text style={styles.subtitle}>{c.subtitle}</Text> : null}

              {c.pickup && c.dropoff ? (
                <>
                  <View style={styles.route}>
                    <View style={styles.routeRow}>
                      <View style={[styles.dot, { backgroundColor: pa.go }]} />
                      <Text style={styles.addr}>{c.pickup.address}</Text>
                    </View>
                    <View style={styles.routeLine} />
                    <View style={styles.routeRow}>
                      <View style={[styles.dot, { backgroundColor: pa.signal, borderRadius: 2 }]} />
                      <Text style={styles.addr}>{c.dropoff.address}</Text>
                    </View>
                  </View>
                  <View style={styles.map}>
                    <RideMap pickup={c.pickup} dropoff={c.dropoff} height={150} />
                  </View>
                </>
              ) : null}

              {c.rider ? <RiderCard rider={c.rider} dark compact /> : c.patient_first_name && c.earning != null ? <Text style={styles.subtitle}>Patient: {c.patient_first_name}</Text> : null}
              {c.lines?.map((l) => (
                <Text key={l} style={styles.line}>
                  {l}
                </Text>
              ))}
            </ScrollView>

            <View style={styles.actions}>
              {c.swipe ? (
                <SwipeToAccept disabled={phase === 'working'} onComplete={accept} />
              ) : (
                <Pressable style={[styles.acceptBtn, phase === 'working' && { opacity: 0.6 }]} onPress={accept} disabled={phase === 'working'} accessibilityRole="button">
                  <Text style={styles.acceptText}>{phase === 'working' ? 'Accepting…' : 'Accept'}</Text>
                </Pressable>
              )}
              {showReasons ? (
                <View style={styles.reasons}>
                  {REASONS.map((r) => (
                    <Pressable key={r} style={styles.reason} onPress={() => decline(r)}>
                      <Text style={styles.reasonText}>{r}</Text>
                    </Pressable>
                  ))}
                  <Pressable style={styles.reason} onPress={() => decline()}>
                    <Text style={styles.reasonText}>Skip</Text>
                  </Pressable>
                </View>
              ) : (
                <Pressable style={styles.declineBtn} onPress={() => setShowReasons(true)} disabled={phase === 'working'} accessibilityRole="button">
                  <Text style={styles.declineText}>Decline</Text>
                </Pressable>
              )}
            </View>
          </>
        ) : (
          <ResultState phase={phase} message={message} icon={ICONS[c.icon] ?? 'notifications'} service={c.service} />
        )}
      </View>
    </Modal>
  );
}

function ResultState({ phase, message, icon, service }: { phase: Phase; message: string; icon: keyof typeof Ionicons.glyphMap; service?: string }) {
  const map: Record<string, [string, string, string]> = {
    accepted: ['Accepted', message || 'The patient has been notified.', pa.go],
    taken: service === 'doctor_now' || service === 'doctor_later'
      ? ['Taken by another doctor', 'Another doctor accepted this consult first.', pa.sats.orange]
      : ['Accepted by another provider', 'This request is no longer available.', pa.sats.orange],
    expired: ['Request expired', message || 'You did not respond in time.', pa.muted],
    cancelled: ['Request cancelled', 'The patient or our team cancelled this request.', pa.muted],
    error: ['Could not accept', message || 'Please try again.', pa.signal],
  };
  const [title, body, colour] = map[phase] ?? map.expired;
  return (
    <View style={styles.result}>
      <View style={[styles.iconWrap, { backgroundColor: colour, width: 72, height: 72, borderRadius: 20 }]}>
        <Ionicons name={phase === 'accepted' ? 'checkmark' : icon} size={36} color="#fff" />
      </View>
      <Text style={styles.resultTitle}>{title}</Text>
      <Text style={styles.resultBody}>{body}</Text>
    </View>
  );
}

/** Swipe-right-to-accept slider (PanResponder; completes at 85% of the track). */
export function SwipeToAccept({ onComplete, disabled }: { onComplete: () => void; disabled?: boolean }) {
  const x = useRef(new Animated.Value(0)).current;
  const [width, setWidth] = useState(0);
  const [done, setDone] = useState(false);
  const max = Math.max(0, width - 64);
  const maxRef = useRef(0);
  maxRef.current = max;

  const responder = useMemo(
    () =>
      PanResponder.create({
        onStartShouldSetPanResponder: () => !disabled && !done,
        onMoveShouldSetPanResponder: (_e, g) => !disabled && !done && Math.abs(g.dx) > 4,
        onPanResponderMove: (_e, g) => x.setValue(Math.max(0, Math.min(maxRef.current, g.dx))),
        onPanResponderRelease: (_e, g) => {
          if (g.dx >= maxRef.current * 0.85 && maxRef.current > 0) {
            Animated.timing(x, { toValue: maxRef.current, duration: 80, useNativeDriver: false }).start();
            setDone(true);
            Vibration.vibrate(60);
            onComplete();
          } else {
            Animated.spring(x, { toValue: 0, useNativeDriver: false }).start();
          }
        },
        onPanResponderTerminate: () => Animated.spring(x, { toValue: 0, useNativeDriver: false }).start(),
      }),
    [disabled, done, onComplete, x],
  );

  const fillWidth = x.interpolate({ inputRange: [0, Math.max(1, max)], outputRange: [64, Math.max(64, width)], extrapolate: 'clamp' });

  return (
    <View
      style={[styles.swipe, done && { backgroundColor: '#14532F' }]}
      onLayout={(e) => setWidth(e.nativeEvent.layout.width)}
      accessible
      accessibilityRole="adjustable"
      accessibilityLabel="Swipe right to accept"
      accessibilityActions={[{ name: 'activate', label: 'Accept' }]}
      onAccessibilityAction={() => {
        if (!disabled && !done) {
          setDone(true);
          onComplete();
        }
      }}
    >
      <Animated.View style={[styles.swipeFill, { width: fillWidth }]} />
      <Text style={styles.swipeLabel}>{done ? 'Accepting…' : 'Swipe right to accept  ›››'}</Text>
      <Animated.View style={[styles.thumb, { transform: [{ translateX: x }] }]} {...responder.panHandlers}>
        <Ionicons name="arrow-forward" size={30} color={pa.go} />
      </Animated.View>
    </View>
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: '#101012' },
  head: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingHorizontal: 16, paddingVertical: 10 },
  iconWrap: { width: 52, height: 52, borderRadius: 14, backgroundColor: pa.signal, alignItems: 'center', justifyContent: 'center' },
  kicker: { color: pa.beacon, fontFamily: paFonts.black, fontSize: 11, letterSpacing: 1.4, textTransform: 'uppercase' },
  title: { color: '#fff', fontFamily: paFonts.black, fontSize: 20, marginTop: 2 },
  queue: { color: pa.beacon, fontFamily: paFonts.bold, fontSize: 12, marginTop: 2 },
  timer: { width: 62, height: 62, borderRadius: 31, borderWidth: 4, borderColor: pa.beacon, alignItems: 'center', justifyContent: 'center' },
  timerNum: { color: '#fff', fontFamily: paFonts.black, fontSize: 20 },
  timerLbl: { color: '#C9C4BA', fontFamily: paFonts.bold, fontSize: 9, marginTop: -2 },
  barTrack: { height: 6, backgroundColor: '#2A2A2E', marginHorizontal: 16, borderRadius: 3, overflow: 'hidden' },
  barFill: { height: 6, borderRadius: 3 },
  badges: { flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginBottom: 14 },
  badge: { backgroundColor: '#26262A', borderWidth: 1, borderColor: '#3A3A3E', paddingHorizontal: 10, paddingVertical: 5, borderRadius: 999 },
  badgeRed: { backgroundColor: '#5A1210', borderColor: pa.signal },
  badgeText: { color: '#fff', fontFamily: paFonts.bold, fontSize: 12 },
  money: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-end', marginBottom: 14, gap: 12 },
  moneyLbl: { color: '#C9C4BA', fontFamily: paFonts.black, fontSize: 11, letterSpacing: 1.2, textTransform: 'uppercase' },
  moneyVal: { color: '#fff', fontFamily: paFonts.black, fontSize: 40, letterSpacing: -1 },
  metaStrong: { color: '#fff', fontFamily: paFonts.bold, fontSize: 14 },
  meta: { color: '#C9C4BA', fontFamily: paFonts.regular, fontSize: 13, marginTop: 2, textAlign: 'right' },
  subtitle: { color: '#fff', fontFamily: paFonts.bold, fontSize: 16, marginBottom: 12 },
  route: { backgroundColor: '#1C1C20', borderRadius: 12, padding: 12, marginBottom: 12 },
  routeRow: { flexDirection: 'row', alignItems: 'flex-start', gap: 10 },
  routeLine: { width: 2, height: 14, backgroundColor: '#3A3A3E', marginLeft: 5, marginVertical: 3 },
  dot: { width: 12, height: 12, borderRadius: 6, marginTop: 3 },
  addr: { color: '#fff', fontFamily: paFonts.bold, fontSize: 15, flex: 1 },
  map: { borderRadius: 12, overflow: 'hidden', marginBottom: 12 },
  line: { color: '#C9C4BA', fontFamily: paFonts.regular, fontSize: 14, marginBottom: 4 },
  actions: { paddingHorizontal: 16, paddingTop: 8, gap: 10 },
  acceptBtn: { backgroundColor: pa.go, borderRadius: 14, paddingVertical: 18, alignItems: 'center' },
  acceptText: { color: '#fff', fontFamily: paFonts.black, fontSize: 19 },
  declineBtn: { borderWidth: 2, borderColor: '#5A5A60', borderRadius: 14, paddingVertical: 13, alignItems: 'center' },
  declineText: { color: '#fff', fontFamily: paFonts.bold, fontSize: 16 },
  reasons: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, justifyContent: 'center' },
  reason: { borderWidth: 1, borderColor: '#5A5A60', borderRadius: 999, paddingHorizontal: 14, paddingVertical: 9 },
  reasonText: { color: '#fff', fontFamily: paFonts.bold, fontSize: 13 },
  swipe: { height: 68, borderRadius: 34, backgroundColor: pa.go, justifyContent: 'center', overflow: 'hidden' },
  swipeFill: { position: 'absolute', left: 0, top: 0, bottom: 0, backgroundColor: 'rgba(0,0,0,0.2)' },
  swipeLabel: { position: 'absolute', left: 0, right: 0, textAlign: 'center', color: '#fff', fontFamily: paFonts.black, fontSize: 17 },
  thumb: { position: 'absolute', left: 4, width: 60, height: 60, borderRadius: 30, backgroundColor: '#fff', alignItems: 'center', justifyContent: 'center' },
  result: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 24 },
  resultTitle: { color: '#fff', fontFamily: paFonts.black, fontSize: 24, marginTop: 18, textAlign: 'center' },
  resultBody: { color: '#C9C4BA', fontFamily: paFonts.regular, fontSize: 15, marginTop: 8, textAlign: 'center' },
});
