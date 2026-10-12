import React, { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, AppState, AppStateStatus, Image, Platform, Pressable, StyleSheet, Text, View } from 'react-native';
import { Feather } from '@expo/vector-icons';
import * as LocalAuthentication from 'expo-local-authentication';
import * as SecureStore from 'expo-secure-store';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useAuth } from './AuthContext';
import { pa, paFonts } from '../theme';

/**
 * Optional "App lock": when on, the app asks for fingerprint / Face ID (or
 * the phone's PIN/passcode) on launch and when it comes back from the
 * background after LOCK_AFTER_MS. The preference is stored per device in
 * SecureStore (same store as the sign-in token). Only applies while signed
 * in. If the phone later has no lock set up at all, the app never traps
 * the user: it skips the lock (there is nothing to unlock with).
 */
const STORE_KEY = 'promptaid_app_lock';
export const LOCK_AFTER_MS = 60 * 1000;

export interface AppLockSupport {
  /** Biometrics or a device PIN/passcode is set up, so the lock can be used. */
  available: boolean;
  hasHardware: boolean;
  enrolled: boolean;
  /** "Face ID", "Fingerprint", "Face unlock", or "Phone PIN" */
  method: string;
  /** Why the toggle is disabled, when it is. */
  reason: string | null;
}

interface AppLockValue {
  enabled: boolean;
  locked: boolean;
  support: AppLockSupport | null;
  setEnabled: (on: boolean) => Promise<{ ok: boolean; message?: string }>;
  refreshSupport: () => Promise<AppLockSupport>;
}

const AppLockContext = createContext<AppLockValue | null>(null);

async function readSupport(): Promise<AppLockSupport> {
  if (Platform.OS === 'web') {
    return { available: false, hasHardware: false, enrolled: false, method: '', reason: 'App lock is only available in the phone app.' };
  }
  try {
    const [hasHardware, enrolled, level, types] = await Promise.all([
      LocalAuthentication.hasHardwareAsync(),
      LocalAuthentication.isEnrolledAsync(),
      LocalAuthentication.getEnrolledLevelAsync(),
      LocalAuthentication.supportedAuthenticationTypesAsync(),
    ]);
    const face = types.includes(LocalAuthentication.AuthenticationType.FACIAL_RECOGNITION);
    const finger = types.includes(LocalAuthentication.AuthenticationType.FINGERPRINT);
    const biometric = hasHardware && enrolled;
    const method = biometric
      ? face
        ? Platform.OS === 'ios' ? 'Face ID' : 'Face unlock'
        : finger
          ? Platform.OS === 'ios' ? 'Touch ID' : 'Fingerprint'
          : 'Biometrics'
      : 'Phone PIN';
    const available = biometric || level !== LocalAuthentication.SecurityLevel.NONE;
    let reason: string | null = null;
    if (!available) {
      reason = hasHardware
        ? 'Set up a fingerprint, face or screen lock (PIN, pattern or passcode) in your phone settings first, then come back to turn on App lock.'
        : 'This phone has no fingerprint or face sensor and no screen lock set up. Add a PIN, pattern or passcode in your phone settings to use App lock.';
    }
    return { available, hasHardware, enrolled, method, reason };
  } catch {
    return { available: false, hasHardware: false, enrolled: false, method: '', reason: 'App lock is not supported on this device.' };
  }
}

export function AppLockProvider({ children }: { children: React.ReactNode }) {
  const { user, loading: authLoading, logout } = useAuth();
  const [enabled, setEnabledState] = useState(false);
  const [loaded, setLoaded] = useState(false);
  const [locked, setLocked] = useState(false);
  const [support, setSupport] = useState<AppLockSupport | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const backgroundAt = useRef<number | null>(null);
  const authenticating = useRef(false);
  const signedIn = !!user;

  const refreshSupport = useCallback(async () => {
    const s = await readSupport();
    setSupport(s);
    return s;
  }, []);

  // Load the saved preference once; lock on launch when it is on.
  useEffect(() => {
    let alive = true;
    (async () => {
      let on = false;
      try {
        on = (await SecureStore.getItemAsync(STORE_KEY)) === '1';
      } catch {
        on = false;
      }
      const s = await refreshSupport();
      if (!alive) return;
      setEnabledState(on);
      setLocked(on && s.available);
      setLoaded(true);
    })();
    return () => {
      alive = false;
    };
  }, [refreshSupport]);

  const unlock = useCallback(async () => {
    if (authenticating.current) return;
    authenticating.current = true;
    setBusy(true);
    setError(null);
    try {
      const s = await refreshSupport();
      if (!s.available) {
        // Nothing to unlock with any more (screen lock removed) — don't trap the user.
        setLocked(false);
        return;
      }
      const result = await LocalAuthentication.authenticateAsync({
        promptMessage: 'Unlock Prompt Aid',
        cancelLabel: 'Cancel',
        fallbackLabel: 'Use passcode',
        disableDeviceFallback: false,
      });
      if (result.success) {
        setLocked(false);
      } else if (result.error && !['user_cancel', 'system_cancel', 'app_cancel'].includes(result.error)) {
        setError('Could not verify it\'s you. Try again.');
      }
    } catch {
      setError('Could not verify it\'s you. Try again.');
    } finally {
      authenticating.current = false;
      setBusy(false);
    }
  }, [refreshSupport]);

  // A fresh sign-in (password just typed) doesn't need the lock as well:
  // only a session restored at launch or resumed from background does.
  const sawSignedOut = useRef(false);
  useEffect(() => {
    if (authLoading) return;
    if (!user) {
      sawSignedOut.current = true;
    } else if (sawSignedOut.current) {
      sawSignedOut.current = false;
      setLocked(false);
    }
  }, [user, authLoading]);

  // Re-lock after LOCK_AFTER_MS in the background.
  useEffect(() => {
    const sub = AppState.addEventListener('change', (state: AppStateStatus) => {
      if (authenticating.current) return; // the system prompt itself moves the app to inactive/background
      if (state === 'background') {
        backgroundAt.current = Date.now();
      } else if (state === 'active') {
        const since = backgroundAt.current;
        backgroundAt.current = null;
        if (enabled && signedIn && since !== null && Date.now() - since >= LOCK_AFTER_MS) {
          setLocked(true);
        }
      }
    });
    return () => sub.remove();
  }, [enabled, signedIn]);

  // Ask straight away whenever the lock screen appears.
  const showLock = loaded && enabled && signedIn && locked;
  useEffect(() => {
    if (showLock && AppState.currentState === 'active') {
      unlock();
    }
  }, [showLock, unlock]);

  const setEnabled = useCallback(async (on: boolean) => {
    if (on) {
      const s = await refreshSupport();
      if (!s.available) return { ok: false, message: s.reason ?? 'App lock is not available on this phone.' };
      authenticating.current = true;
      try {
        const result = await LocalAuthentication.authenticateAsync({
          promptMessage: 'Turn on App lock',
          cancelLabel: 'Cancel',
          fallbackLabel: 'Use passcode',
          disableDeviceFallback: false,
        });
        if (!result.success) return { ok: false, message: 'App lock was not turned on — we could not confirm it\'s you.' };
      } finally {
        authenticating.current = false;
      }
    }
    try {
      await SecureStore.setItemAsync(STORE_KEY, on ? '1' : '0');
    } catch {
      return { ok: false, message: 'Could not save the setting on this phone.' };
    }
    setEnabledState(on);
    if (!on) setLocked(false);
    return { ok: true };
  }, [refreshSupport]);

  const value = useMemo(() => ({ enabled, locked: showLock, support, setEnabled, refreshSupport }), [enabled, showLock, support, setEnabled, refreshSupport]);

  return (
    <AppLockContext.Provider value={value}>
      <View style={{ flex: 1 }}>
        {children}
        {showLock ? <LockScreen method={support?.method ?? ''} busy={busy} error={error} onUnlock={unlock} onSignOut={logout} /> : null}
      </View>
    </AppLockContext.Provider>
  );
}

export function useAppLock(): AppLockValue {
  const ctx = useContext(AppLockContext);
  if (!ctx) throw new Error('useAppLock must be used inside AppLockProvider');
  return ctx;
}

function LockScreen({ method, busy, error, onUnlock, onSignOut }: { method: string; busy: boolean; error: string | null; onUnlock: () => void; onSignOut: () => void }) {
  const insets = useSafeAreaInsets();
  return (
    <View style={[styles.overlay, { paddingTop: insets.top + 24, paddingBottom: insets.bottom + 24 }]} accessibilityViewIsModal>
      <View style={styles.center}>
        <Image source={require('../../assets/logo.png')} style={styles.logo} resizeMode="contain" />
        <View style={styles.lockBadge}>
          <Feather name="lock" size={26} color={pa.signal} />
        </View>
        <Text style={styles.title}>Prompt Aid is locked</Text>
        <Text style={styles.sub}>
          {method && method !== 'Phone PIN' ? `Use ${method} or your phone's PIN to unlock.` : "Use your phone's PIN, pattern or passcode to unlock."}
        </Text>
        {error ? <Text style={styles.error}>{error}</Text> : null}
        <Pressable onPress={onUnlock} disabled={busy} style={({ pressed }) => [styles.btn, { opacity: pressed || busy ? 0.8 : 1 }]} accessibilityRole="button" accessibilityLabel="Unlock">
          {busy ? <ActivityIndicator color="#fff" /> : (
            <>
              <Feather name={method === 'Face ID' || method === 'Face unlock' ? 'smile' : 'unlock'} size={18} color="#fff" />
              <Text style={styles.btnText}>Unlock</Text>
            </>
          )}
        </Pressable>
      </View>
      <Pressable onPress={onSignOut} hitSlop={12} style={styles.signOut} accessibilityRole="button">
        <Text style={styles.signOutText}>Sign out instead</Text>
      </Pressable>
    </View>
  );
}

const styles = StyleSheet.create({
  overlay: { position: 'absolute', top: 0, right: 0, bottom: 0, left: 0, backgroundColor: pa.paper, zIndex: 1000, elevation: 1000, paddingHorizontal: 28, justifyContent: 'space-between' },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center' },
  logo: { width: 180, height: 64, marginBottom: 36 },
  lockBadge: { width: 64, height: 64, borderRadius: 32, backgroundColor: pa.signalWash, alignItems: 'center', justifyContent: 'center', marginBottom: 18 },
  title: { fontFamily: paFonts.black, fontSize: 22, color: pa.ink, marginBottom: 8, textAlign: 'center' },
  sub: { fontFamily: paFonts.regular, fontSize: 14, color: pa.muted, textAlign: 'center', marginBottom: 24, maxWidth: 300 },
  error: { fontFamily: paFonts.bold, fontSize: 13, color: pa.signal, marginBottom: 14, textAlign: 'center' },
  btn: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 10, backgroundColor: pa.signal, paddingVertical: 15, paddingHorizontal: 40, minWidth: 220, minHeight: 52 },
  btnText: { fontFamily: paFonts.bold, fontSize: 16, color: '#fff' },
  signOut: { alignSelf: 'center', padding: 8 },
  signOutText: { fontFamily: paFonts.bold, fontSize: 13, color: pa.muted, textDecorationLine: 'underline' },
});
