import React, { useState } from 'react';
import { Alert, KeyboardAvoidingView, Linking, Platform, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { Feather } from '@expo/vector-icons';
import * as DocumentPicker from 'expo-document-picker';
import { api, apiErrorMessage } from '../api/client';
import { Input, PrimaryButton } from '../components/UI';
import AuthBackground, { lookTheme, useLoginLook } from '../components/auth/AuthBackground';
import type { ProviderField, ProviderType } from '../services/signup';
import { websiteUrl } from '../services/siteContent';
import { colors, font, radius, spacing } from '../theme';

type Doc = { uri: string; name: string; mimeType?: string | null };

const SPECIALTIES = ['General practice', 'Paediatrics', 'Gynaecology', 'Internal medicine', 'Psychiatry', 'Dermatology', 'Orthopaedics', 'Cardiology', 'Dentistry', 'Other'];

/**
 * Provider sign-up (doctor, practice, pharmacy, laboratory, radiology,
 * allied). Fields and labels come from the server per type. Creates the
 * provider's own login (inactive) + an application that an admin approves in
 * Staff → Provider applications; approval activates the login.
 */
export default function ProviderSignupScreen({ navigation, route }: any) {
  const type: ProviderType = route.params.type;
  const categories: { key: string; label: string }[] = route.params.categories ?? [];
  const intro: string = route.params.intro ?? '';
  const documentsHint: string = route.params.documentsHint ?? '';
  const look = useLoginLook();
  const t = lookTheme(look, 'signup');
  const has = (f: ProviderField) => type.fields.includes(f);
  const req = (f: ProviderField) => type.required.includes(f);

  const [f, setF] = useState<Record<string, string>>({});
  const [docs, setDocs] = useState<Doc[]>([]);
  const [agree, setAgree] = useState(false);
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState<{ message: string; reference: string } | null>(null);
  const set = (k: string) => (v: string) => setF((s) => ({ ...s, [k]: v }));

  async function addDoc() {
    try {
      const r = await DocumentPicker.getDocumentAsync({ type: ['image/*', 'application/pdf'], copyToCacheDirectory: true, multiple: true });
      if (r.canceled) return;
      const picked = r.assets.map((a) => ({ uri: a.uri, name: a.name, mimeType: a.mimeType }));
      setDocs((d) => [...d, ...picked].slice(0, 6));
    } catch {
      Alert.alert('Could not open files', 'Please try again.');
    }
  }

  const missing = (): string | null => {
    if (!f.name?.trim()) return 'your full name';
    if (!f.email?.trim()) return 'your email';
    if (!f.phone?.trim()) return 'your phone number';
    if ((f.password ?? '').length < 8) return 'a password of at least 8 characters';
    if (type.business_required && !f.business_name?.trim()) return type.business_label.toLowerCase();
    for (const k of type.required) {
      if (!f[k]?.trim()) return k === 'hpcsa_sapc_no' ? type.number_label : k === 'category' ? 'the kind of service' : k.replace('_', ' ');
    }
    if (!agree) return 'your agreement to the terms';
    return null;
  };

  async function submit() {
    const m = missing();
    if (m) {
      Alert.alert('Almost there', `Please add ${m}.`);
      return;
    }
    setBusy(true);
    try {
      const form = new FormData();
      form.append('provider_type', type.key);
      Object.entries(f).forEach(([k, v]) => {
        if (v && v.trim()) form.append(k, k === 'email' ? v.trim().toLowerCase() : v.trim());
      });
      form.append('accept_terms', '1');
      form.append('accept_health_data', '1');
      docs.forEach((d) => form.append('documents[]', { uri: d.uri, name: d.name, type: d.mimeType ?? 'application/octet-stream' } as any));
      const { data } = await api.post('/auth/register-provider', form, { headers: { 'Content-Type': 'multipart/form-data' }, timeout: 120000 });
      setDone({ message: data?.message ?? 'Application received.', reference: data?.data?.reference ?? '' });
    } catch (e) {
      Alert.alert('Could not send your application', apiErrorMessage(e, 'Please check your details and try again.'));
    } finally {
      setBusy(false);
    }
  }

  const labelColor = t.active && !t.card ? t.text : undefined;
  const boxStyle = t.active && t.card ? styles.card : null;

  if (done) {
    return (
      <AuthBackground screen="signup" look={look}>
        <ScrollView contentContainerStyle={styles.container}>
          <View style={[styles.card, { alignItems: 'center' }]}>
            <View style={styles.okIcon}>
              <Feather name="check" size={30} color="#fff" />
            </View>
            <Text style={styles.doneTitle}>Application sent</Text>
            {done.reference ? <Text style={styles.ref}>Reference {done.reference}</Text> : null}
            <Text style={styles.doneBody}>{done.message}</Text>
            <View style={{ alignSelf: 'stretch', marginTop: spacing.lg }}>
              <PrimaryButton title="Back to sign in" onPress={() => navigation.navigate('Login')} />
            </View>
          </View>
        </ScrollView>
      </AuthBackground>
    );
  }

  return (
    <AuthBackground screen="signup" look={look}>
      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView contentContainerStyle={styles.container} keyboardShouldPersistTaps="handled">
          <Pressable onPress={() => navigation.goBack()} hitSlop={12} style={styles.back}>
            <Feather name="arrow-left" size={20} color={t.text} />
            <Text style={[styles.backText, { color: t.text }]}>Back</Text>
          </Pressable>
          <Text style={[styles.title, { color: t.text }]}>Join as: {type.label}</Text>
          <Text style={[styles.subtitle, { color: t.muted }]}>{intro || type.description}</Text>

          <View style={boxStyle}>
            <Text style={[styles.section, { color: labelColor ?? colors.secondary }]}>Your login</Text>
            <Input label={type.key === 'doctor' ? 'Full name (as registered)' : 'Your full name'} labelColor={labelColor} value={f.name ?? ''} onChangeText={set('name')} placeholder="Dr. Jane Doe" />
            <Input label="Email" labelColor={labelColor} autoCapitalize="none" keyboardType="email-address" value={f.email ?? ''} onChangeText={set('email')} placeholder="you@practice.co.za" />
            <Input label="Mobile number" labelColor={labelColor} keyboardType="phone-pad" value={f.phone ?? ''} onChangeText={set('phone')} placeholder="071 234 5678" />
            <Input label="Password" labelColor={labelColor} secureTextEntry value={f.password ?? ''} onChangeText={set('password')} placeholder="At least 8 characters" />

            <Text style={[styles.section, { color: labelColor ?? colors.secondary }]}>Practice details</Text>
            {has('business_name') ? <Input label={type.business_label} labelColor={labelColor} value={f.business_name ?? ''} onChangeText={set('business_name')} /> : null}
            {has('category') && categories.length ? (
              <View style={{ marginBottom: spacing.md }}>
                <Text style={[styles.label, labelColor ? { color: labelColor } : null]}>Kind of service</Text>
                <View style={styles.chips}>
                  {categories.map((c) => (
                    <Pressable key={c.key} onPress={() => set('category')(c.key)} style={[styles.chip, f.category === c.key && styles.chipOn]}>
                      <Text style={[styles.chipText, f.category === c.key && { color: '#fff' }]}>{c.label}</Text>
                    </Pressable>
                  ))}
                </View>
              </View>
            ) : null}
            {has('specialization') ? (
              <View style={{ marginBottom: spacing.md }}>
                <Text style={[styles.label, labelColor ? { color: labelColor } : null]}>Specialty{req('specialization') ? '' : ' (optional)'}</Text>
                <View style={styles.chips}>
                  {SPECIALTIES.map((s) => (
                    <Pressable key={s} onPress={() => set('specialization')(s === 'Other' ? '' : s)} style={[styles.chip, f.specialization === s && styles.chipOn]}>
                      <Text style={[styles.chipText, f.specialization === s && { color: '#fff' }]}>{s}</Text>
                    </Pressable>
                  ))}
                </View>
                {!SPECIALTIES.includes(f.specialization ?? '') ? (
                  <Input value={f.specialization ?? ''} onChangeText={set('specialization')} placeholder="Type your specialty" />
                ) : null}
              </View>
            ) : null}
            {has('hpcsa_sapc_no') ? <Input label={type.number_label + (req('hpcsa_sapc_no') ? '' : ' (optional)')} labelColor={labelColor} autoCapitalize="characters" value={f.hpcsa_sapc_no ?? ''} onChangeText={set('hpcsa_sapc_no')} /> : null}
            {has('registration_no') ? <Input label={'Company / registration number' + (req('registration_no') ? '' : ' (optional)')} labelColor={labelColor} autoCapitalize="characters" value={f.registration_no ?? ''} onChangeText={set('registration_no')} /> : null}
            {has('address') ? <Input label={'Street address' + (req('address') ? '' : ' (optional)')} labelColor={labelColor} value={f.address ?? ''} onChangeText={set('address')} placeholder="12 Main Road, Rosebank" /> : null}
            {has('city') ? <Input label="Town / city" labelColor={labelColor} value={f.city ?? ''} onChangeText={set('city')} placeholder="Johannesburg" /> : null}

            <Text style={[styles.section, { color: labelColor ?? colors.secondary }]}>Documents</Text>
            <Text style={[styles.hint, labelColor ? { color: t.muted } : null]}>{documentsHint || 'Add your registration certificate and ID (PDF or photo).'}</Text>
            {docs.map((d, i) => (
              <View key={d.uri + i} style={styles.doc}>
                <Feather name={d.mimeType?.includes('pdf') ? 'file-text' : 'image'} size={16} color={colors.secondary} />
                <Text style={styles.docName} numberOfLines={1}>{d.name}</Text>
                <Pressable onPress={() => setDocs((x) => x.filter((_, j) => j !== i))} hitSlop={10}>
                  <Feather name="x" size={16} color={colors.gray600} />
                </Pressable>
              </View>
            ))}
            {docs.length < 6 ? <PrimaryButton title={docs.length ? 'Add another document' : 'Add documents'} variant="outline" onPress={addDoc} /> : null}

            <Pressable onPress={() => setAgree((a) => !a)} style={styles.agree}>
              <Feather name={agree ? 'check-square' : 'square'} size={20} color={agree ? colors.primary : labelColor ?? colors.gray600} />
              <Text style={[styles.agreeText, labelColor ? { color: labelColor } : null]}>
                I agree to the{' '}
                <Text style={styles.agreeLink} onPress={() => Linking.openURL(websiteUrl('/terms'))}>terms of use</Text> and{' '}
                <Text style={styles.agreeLink} onPress={() => Linking.openURL(websiteUrl('/privacy'))}>privacy policy (POPIA)</Text>, and confirm my registration details are correct.
              </Text>
            </Pressable>

            <PrimaryButton title="Send application" onPress={submit} loading={busy} />
            <Text style={[styles.hint, { textAlign: 'center', marginTop: spacing.sm }, labelColor ? { color: t.muted } : null]}>You can sign in with this email and password once we approve you.</Text>
          </View>
        </ScrollView>
      </KeyboardAvoidingView>
    </AuthBackground>
  );
}

const styles = StyleSheet.create({
  container: { flexGrow: 1, padding: spacing.lg, paddingTop: spacing.xl * 1.5 },
  back: { flexDirection: 'row', alignItems: 'center', gap: 6, marginBottom: spacing.md },
  backText: { fontFamily: font.semibold, fontSize: 14 },
  title: { fontFamily: font.bold, fontSize: 24, color: colors.secondary },
  subtitle: { fontFamily: font.regular, fontSize: 14, color: colors.gray600, marginTop: spacing.xs, marginBottom: spacing.md, lineHeight: 20 },
  card: { backgroundColor: '#FFFFFF', borderRadius: radius.lg, padding: spacing.lg },
  section: { fontFamily: font.bold, fontSize: 16, marginTop: spacing.sm, marginBottom: spacing.sm },
  label: { fontFamily: font.medium, fontSize: 13, color: colors.gray600, marginBottom: 6 },
  hint: { fontFamily: font.regular, fontSize: 12, color: colors.gray600, marginBottom: spacing.sm, lineHeight: 17 },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 6 },
  chip: { borderWidth: 1, borderColor: colors.gray300, borderRadius: 999, paddingHorizontal: 12, paddingVertical: 7, backgroundColor: '#fff' },
  chipOn: { backgroundColor: colors.primary, borderColor: colors.primary },
  chipText: { fontFamily: font.medium, fontSize: 13, color: colors.secondary },
  doc: { flexDirection: 'row', alignItems: 'center', gap: 8, borderWidth: 1, borderColor: colors.gray300, borderRadius: radius.md, padding: 10, marginBottom: 8, backgroundColor: '#fff' },
  docName: { flex: 1, fontFamily: font.regular, fontSize: 13, color: colors.secondary },
  agree: { flexDirection: 'row', gap: 10, alignItems: 'flex-start', marginVertical: spacing.md },
  agreeText: { flex: 1, fontFamily: font.regular, fontSize: 13, color: colors.gray600, lineHeight: 19 },
  agreeLink: { fontFamily: font.semibold, color: colors.primary },
  okIcon: { width: 60, height: 60, borderRadius: 30, backgroundColor: '#1F7A4C', alignItems: 'center', justifyContent: 'center', marginBottom: spacing.md },
  doneTitle: { fontFamily: font.bold, fontSize: 22, color: colors.secondary },
  ref: { fontFamily: font.semibold, fontSize: 13, color: colors.gray600, marginTop: 4 },
  doneBody: { fontFamily: font.regular, fontSize: 14, color: colors.gray600, textAlign: 'center', marginTop: spacing.sm, lineHeight: 20 },
});
