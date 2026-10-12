import React, { useCallback, useState } from 'react';
import { ActivityIndicator, Alert, RefreshControl, ScrollView, StyleSheet, Switch, Text, View } from 'react-native';
import { Feather } from '@expo/vector-icons';
import { useFocusEffect } from '@react-navigation/native';
import { api, apiErrorMessage } from '../api/client';
import { useAppLock, LOCK_AFTER_MS } from '../context/AppLock';
import { EmptyNote, PrimaryButton, SectionTitle, SoftCard } from '../components/settings-ui';
import { pa, paFonts } from '../theme';

interface LoginRow {
  id: number;
  channel: 'web' | 'staff' | 'app';
  channel_label: string;
  device: string;
  ip: string | null;
  success: boolean;
  created_at: string;
  current: boolean;
}

const CHANNEL_ICON: Record<LoginRow['channel'], React.ComponentProps<typeof Feather>['name']> = {
  app: 'smartphone',
  web: 'globe',
  staff: 'briefcase',
};

/**
 * Login & security (every role): App lock (biometric / PIN, stored on this
 * phone) and Login activity — recent sign-ins to this account from the app,
 * the website and the staff panels, with "Sign out all other devices".
 * API: GET /security/logins, POST /security/logout-others.
 */
export default function SecurityScreen() {
  const lock = useAppLock();
  const [rows, setRows] = useState<LoginRow[]>([]);
  const [otherDevices, setOtherDevices] = useState(0);
  const [retention, setRetention] = useState<number | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [signingOut, setSigningOut] = useState(false);
  const [toggling, setToggling] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const { data } = await api.get('/security/logins');
      setRows(data.data ?? []);
      setOtherDevices(Number(data.other_devices ?? 0));
      setRetention(data.retention_days ?? null);
    } catch (e) {
      setError(apiErrorMessage(e, 'Could not load your login activity.'));
    } finally {
      setLoading(false);
    }
  }, []);

  useFocusEffect(
    useCallback(() => {
      load();
      lock.refreshSupport();
      // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [load]),
  );

  async function toggleLock(on: boolean) {
    setToggling(true);
    try {
      const result = await lock.setEnabled(on);
      if (!result.ok && result.message) Alert.alert('App lock', result.message);
    } finally {
      setToggling(false);
    }
  }

  function confirmSignOutOthers() {
    Alert.alert(
      'Sign out other devices?',
      'This signs your account out of the app on every other phone, and out of the website and staff panels everywhere. This phone stays signed in.',
      [
        { text: 'Cancel', style: 'cancel' },
        {
          text: 'Sign out others',
          style: 'destructive',
          onPress: async () => {
            setSigningOut(true);
            try {
              const { data } = await api.post('/security/logout-others');
              Alert.alert('Done', data.message ?? 'Signed out of all other devices.');
              load();
            } catch (e) {
              Alert.alert('Could not sign out other devices', apiErrorMessage(e));
            } finally {
              setSigningOut(false);
            }
          },
        },
      ],
    );
  }

  const support = lock.support;
  const lockDisabled = !support?.available || toggling;

  return (
    <ScrollView style={styles.container} contentContainerStyle={{ padding: 16, paddingBottom: 48 }} refreshControl={<RefreshControl refreshing={false} onRefresh={load} />}>
      <SectionTitle>App lock</SectionTitle>
      <SoftCard style={{ marginBottom: 24 }}>
        <View style={styles.lockRow}>
          <View style={styles.lockIcon}>
            <Feather name="lock" size={18} color={pa.info} />
          </View>
          <View style={{ flex: 1 }}>
            <Text style={styles.rowTitle}>Lock Prompt Aid</Text>
            <Text style={styles.rowSub}>
              {support?.available
                ? `Ask for ${support.method === 'Phone PIN' ? "your phone's PIN" : `${support.method} (or your phone's PIN)`} when the app opens and after ${Math.round(LOCK_AFTER_MS / 60000)} minute in the background.`
                : 'Checking this phone…'}
            </Text>
          </View>
          {toggling ? (
            <ActivityIndicator color={pa.signal} />
          ) : (
            <Switch
              value={lock.enabled}
              onValueChange={toggleLock}
              disabled={lockDisabled && !lock.enabled}
              trackColor={{ true: pa.signal, false: pa.line }}
              thumbColor="#fff"
              accessibilityLabel="App lock"
            />
          )}
        </View>
        {support && !support.available ? (
          <View style={styles.notice}>
            <Feather name="info" size={14} color={pa.muted} />
            <Text style={styles.noticeText}>{support.reason}</Text>
          </View>
        ) : null}
        <Text style={styles.small}>Saved on this phone only. Your health information is never stored in the lock.</Text>
      </SoftCard>

      <SectionTitle>Login activity</SectionTitle>
      <SoftCard style={{ marginBottom: 16 }}>
        <Text style={styles.rowSub}>
          {otherDevices > 0
            ? `${otherDevices} other ${otherDevices === 1 ? 'device or session is' : 'devices or sessions are'} signed in to your account.`
            : 'No other devices are signed in right now.'}
        </Text>
        <PrimaryButton
          title="Sign out all other devices"
          tone="danger"
          icon="log-out-outline"
          onPress={confirmSignOutOthers}
          loading={signingOut}
          style={{ marginTop: 12 }}
        />
      </SoftCard>

      {loading && rows.length === 0 ? (
        <ActivityIndicator color={pa.signal} style={{ marginTop: 24 }} />
      ) : error ? (
        <EmptyNote icon="cloud-offline-outline" text={error} />
      ) : rows.length === 0 ? (
        <EmptyNote text="No sign-ins recorded yet." />
      ) : (
        <SoftCard style={{ paddingVertical: 4 }}>
          {rows.map((r, i) => (
            <View key={r.id} style={[styles.row, i < rows.length - 1 && styles.rowBorder]}>
              <View style={[styles.rowIconWrap, !r.success && { backgroundColor: pa.signalWash }]}>
                <Feather name={r.success ? CHANNEL_ICON[r.channel] ?? 'log-in' : 'alert-triangle'} size={16} color={r.success ? pa.info : pa.signal} />
              </View>
              <View style={{ flex: 1 }}>
                <Text style={styles.rowTitle} numberOfLines={1}>
                  {r.success ? r.channel_label : `Wrong password · ${r.channel_label}`}
                  {r.current ? '  · This phone' : ''}
                </Text>
                <Text style={styles.rowSub} numberOfLines={1}>{r.device}{r.ip ? ` · ${r.ip}` : ''}</Text>
              </View>
              <Text style={styles.when}>{formatWhen(r.created_at)}</Text>
            </View>
          ))}
        </SoftCard>
      )}
      {retention ? <Text style={[styles.small, { marginTop: 12 }]}>Sign-ins are kept for {retention} days. Don't recognise one? Sign out other devices and change your password.</Text> : null}
    </ScrollView>
  );
}

function formatWhen(iso: string) {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  const mins = Math.round((Date.now() - d.getTime()) / 60000);
  if (mins < 1) return 'Just now';
  if (mins < 60) return `${mins} min ago`;
  if (mins < 24 * 60) return `${Math.round(mins / 60)} h ago`;
  return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short' }) + ' ' + d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: pa.paper },
  lockRow: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  lockIcon: { width: 36, height: 36, borderRadius: 10, backgroundColor: '#EAF1F8', alignItems: 'center', justifyContent: 'center' },
  notice: { flexDirection: 'row', gap: 8, backgroundColor: pa.surface2, borderWidth: 1, borderColor: pa.line, borderRadius: 10, padding: 10, marginTop: 12 },
  noticeText: { flex: 1, fontFamily: paFonts.regular, fontSize: 12, color: pa.inkSoft, lineHeight: 17 },
  small: { fontFamily: paFonts.regular, fontSize: 11, color: pa.muted, marginTop: 10 },
  row: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 12 },
  rowBorder: { borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: pa.line },
  rowIconWrap: { width: 32, height: 32, borderRadius: 10, backgroundColor: '#EAF1F8', alignItems: 'center', justifyContent: 'center' },
  rowTitle: { fontFamily: paFonts.bold, fontSize: 14, color: pa.ink },
  rowSub: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted, marginTop: 2 },
  when: { fontFamily: paFonts.regular, fontSize: 11, color: pa.muted },
});
