import React, { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Alert, KeyboardAvoidingView, Platform, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { Feather } from '@expo/vector-icons';
import { apiErrorMessage, useAuth } from '../context/AuthContext';
import { Input, PrimaryButton } from '../components/UI';
import AuthBackground, { lookTheme, useLoginLook } from '../components/auth/AuthBackground';
import { cachedSignupOptions, getSignupOptions, ProviderType, SignupOptions } from '../services/signup';
import { colors, font, radius, spacing } from '../theme';

type Choice = 'patient' | 'driver' | string; // string = a provider type key

/**
 * "Create an account": choose who you are first.
 *  - Patient → this form, then the medical / scheme / document wizard.
 *  - Driver  → this form (single step; approved in Staff → Drivers).
 *  - Doctor, practice, pharmacy, laboratory, radiology, allied → the provider
 *    form (ProviderSignup); their login works once an admin approves it.
 * The list comes from the server (Staff → Site content → Sign up & sign in).
 */
export default function RegisterScreen({ navigation, route }: any) {
  const { register } = useAuth();
  const look = useLoginLook();
  const t = lookTheme(look, 'signup');
  const [opts, setOpts] = useState<SignupOptions | null>(cachedSignupOptions());
  const [choice, setChoice] = useState<Choice | null>(route?.params?.providers ? null : 'patient');
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [phone, setPhone] = useState('');
  const [password, setPassword] = useState('');
  const [referralCode, setReferralCode] = useState('');
  const [loading, setLoading] = useState(false);
  const scroller = useRef<ScrollView>(null);

  useEffect(() => {
    getSignupOptions().then((o) => {
      setOpts(o);
      // A role the admin switched off can't stay selected.
      setChoice((c) => (c === 'patient' && !o.patient_open ? null : c === 'driver' && !o.driver_open ? null : c));
    });
  }, []);

  function pick(c: Choice, provider?: ProviderType) {
    if (provider) {
      navigation.navigate('ProviderSignup', { type: provider, categories: opts?.categories ?? [], intro: opts?.intro ?? '', documentsHint: opts?.documents_hint ?? '' });
      return;
    }
    setChoice(c);
    setTimeout(() => scroller.current?.scrollToEnd({ animated: true }), 150);
  }

  async function handleRegister() {
    const account = {
      name,
      email: email.trim(),
      phone: phone || undefined,
      password,
      referral_code: referralCode.trim().toUpperCase() || undefined,
    };

    // Drivers have no medical/scheme/document fields to collect, so their
    // signup stays a single step; patients continue into the wizard and
    // only hit the API once, on its final screen.
    if (choice === 'driver') {
      setLoading(true);
      try {
        await register({ ...account, role: 'driver' });
      } catch (e) {
        Alert.alert('Could not create account', apiErrorMessage(e, 'Please check your details and try again.'));
      } finally {
        setLoading(false);
      }
      return;
    }

    navigation.navigate('RegisterMedical', { account });
  }

  const showPatient = opts ? opts.patient_open : true;
  const showDriver = opts ? opts.driver_open : true;
  const labelColor = t.active && !t.card ? t.text : undefined;
  const formOpen = choice === 'patient' || choice === 'driver';

  return (
    <AuthBackground screen="signup" look={look}>
      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView ref={scroller} contentContainerStyle={styles.container} keyboardShouldPersistTaps="handled">
          <Text style={[styles.title, { color: t.text }]}>Create your account</Text>
          <Text style={[styles.subtitle, { color: t.muted }]}>Who are you joining as?</Text>

          <View style={styles.grid}>
            {showPatient ? <Tile icon="user" label="Patient" sub="Book doctors, shuttles and medicine" on={choice === 'patient'} onPress={() => pick('patient')} /> : null}
            {showDriver ? <Tile icon="truck" label="Shuttle driver" sub="Drive patients to their care" on={choice === 'driver'} onPress={() => pick('driver')} /> : null}
            {(opts?.types ?? []).map((p) => (
              <Tile key={p.key} icon={(p.icon as any) || 'briefcase'} label={p.label} sub={p.description} onPress={() => pick(p.key, p)} provider />
            ))}
          </View>
          {!opts ? <ActivityIndicator color={t.text} style={{ marginBottom: spacing.md }} /> : null}
          {opts && opts.types.length ? (
            <Text style={[styles.note, { color: t.muted }]}>Healthcare providers: we verify your registration before your profile goes live. You choose your own password now and can sign in once approved.</Text>
          ) : null}

          {formOpen ? (
            <View style={t.active && t.card ? styles.card : { marginTop: spacing.md }}>
              <Text style={[styles.formTitle, { color: t.active && !t.card ? t.text : colors.secondary }]}>{choice === 'driver' ? 'Driver account' : 'Patient account'}</Text>
              <Input label="Full name" labelColor={labelColor} value={name} onChangeText={setName} placeholder="Jane Doe" />
              <Input label="Email" labelColor={labelColor} autoCapitalize="none" keyboardType="email-address" value={email} onChangeText={setEmail} placeholder="you@example.com" />
              <Input label="Phone" labelColor={labelColor} keyboardType="phone-pad" value={phone} onChangeText={setPhone} placeholder="+27 71 234 5678" />
              <Input label="Password" labelColor={labelColor} secureTextEntry value={password} onChangeText={setPassword} placeholder="At least 8 characters" />
              <Input
                label="Referral code (optional)"
                labelColor={labelColor}
                autoCapitalize="characters"
                autoCorrect={false}
                value={referralCode}
                onChangeText={setReferralCode}
                placeholder="From a friend's invite"
                maxLength={20}
              />
              <PrimaryButton title={choice === 'driver' ? 'Create driver account' : 'Continue'} onPress={handleRegister} loading={loading} disabled={!name || !email || password.length < 8} />
            </View>
          ) : null}

          <View style={styles.footer}>
            <Text style={[styles.footerText, { color: t.muted }]}>Already have an account?</Text>
            <Text style={[styles.link, { color: t.link }]} onPress={() => navigation.navigate('Login')}>
              {' '}Sign in
            </Text>
          </View>
        </ScrollView>
      </KeyboardAvoidingView>
    </AuthBackground>
  );
}

function Tile({ icon, label, sub, on, onPress, provider }: { icon: keyof typeof Feather.glyphMap; label: string; sub: string; on?: boolean; onPress: () => void; provider?: boolean }) {
  return (
    <Pressable onPress={onPress} style={({ pressed }) => [styles.tile, on && styles.tileOn, pressed && { opacity: 0.85 }]}>
      <View style={[styles.tileIcon, on && { backgroundColor: colors.primary }]}>
        <Feather name={Feather.glyphMap[icon] ? icon : 'briefcase'} size={18} color={on ? '#fff' : colors.primary} />
      </View>
      <View style={{ flex: 1 }}>
        <Text style={styles.tileLabel}>{label}</Text>
        <Text style={styles.tileSub} numberOfLines={2}>{sub}</Text>
      </View>
      <Feather name={provider ? 'chevron-right' : on ? 'check-circle' : 'circle'} size={18} color={on ? colors.primary : colors.gray500} />
    </Pressable>
  );
}

const styles = StyleSheet.create({
  container: { flexGrow: 1, justifyContent: 'center', padding: spacing.lg, paddingTop: spacing.xl * 1.5 },
  title: { fontFamily: font.bold, fontSize: 26, color: colors.secondary },
  subtitle: { fontFamily: font.regular, fontSize: 14, color: colors.gray600, marginTop: spacing.xs, marginBottom: spacing.md },
  grid: { gap: spacing.sm, marginBottom: spacing.md },
  tile: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm, backgroundColor: '#FFFFFF', borderRadius: radius.md, borderWidth: 1, borderColor: colors.gray300, padding: spacing.sm + 2 },
  tileOn: { borderColor: colors.primary, backgroundColor: colors.primaryLight },
  tileIcon: { width: 36, height: 36, borderRadius: 18, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.primaryLight },
  tileLabel: { fontFamily: font.semibold, fontSize: 15, color: colors.secondary },
  tileSub: { fontFamily: font.regular, fontSize: 12, color: colors.gray600, marginTop: 1 },
  note: { fontFamily: font.regular, fontSize: 12, lineHeight: 17, marginBottom: spacing.sm },
  card: { backgroundColor: '#FFFFFF', borderRadius: radius.lg, padding: spacing.lg, marginTop: spacing.md },
  formTitle: { fontFamily: font.bold, fontSize: 18, marginBottom: spacing.md },
  footer: { flexDirection: 'row', justifyContent: 'center', marginTop: spacing.lg },
  footerText: { fontFamily: font.regular, color: colors.gray600 },
  link: { fontFamily: font.semibold, color: colors.primary },
});
