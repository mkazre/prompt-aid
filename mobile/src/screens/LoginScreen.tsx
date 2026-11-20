import React, { useState } from 'react';
import { Alert, KeyboardAvoidingView, Platform, ScrollView, StyleSheet, Text, View } from 'react-native';
import { StatusBar } from 'expo-status-bar';
import { apiErrorMessage, useAuth } from '../context/AuthContext';
import { Input, PrimaryButton } from '../components/UI';
import AuthBackground, { lookTheme, useLoginLook } from '../components/auth/AuthBackground';
import { colors, font, radius, spacing } from '../theme';

/**
 * Sign in. Background photo / overlay / heading come from the admin
 * (Staff → Site content → Sign up & sign in) — no app update needed.
 */
export default function LoginScreen({ navigation }: any) {
  const { login } = useAuth();
  const look = useLoginLook();
  const t = lookTheme(look, 'login');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [loading, setLoading] = useState(false);

  async function handleLogin() {
    setLoading(true);
    try {
      await login(email.trim(), password);
    } catch (e: any) {
      // Provider sign-ups waiting for approval get the "being reviewed" message from the server.
      const pending = e?.response?.data?.code === 'pending_approval';
      Alert.alert(pending ? 'Application in review' : 'Sign in failed', apiErrorMessage(e, 'Check your email and password and try again.'));
    } finally {
      setLoading(false);
    }
  }

  const labelColor = t.card ? undefined : t.text;

  return (
    <AuthBackground screen="login" look={look}>
      {t.active ? <StatusBar style={look?.text_theme === 'dark' ? 'dark' : 'light'} /> : null}
      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView contentContainerStyle={styles.container} keyboardShouldPersistTaps="handled">
          <Text style={[styles.title, { color: t.text }]}>{look?.title || 'Welcome back'}</Text>
          <Text style={[styles.subtitle, { color: t.muted }]}>{look?.subtitle || 'Sign in to manage your appointments and rides.'}</Text>

          <View style={t.active && t.card ? styles.card : null}>
            <Input label="Email" labelColor={labelColor} autoCapitalize="none" keyboardType="email-address" value={email} onChangeText={setEmail} placeholder="you@example.com" />
            <Input label="Password" labelColor={labelColor} secureTextEntry value={password} onChangeText={setPassword} placeholder="••••••••" />
            <PrimaryButton title="Sign in" onPress={handleLogin} loading={loading} disabled={!email || !password} />
          </View>

          <View style={styles.footer}>
            <Text style={[styles.footerText, { color: t.muted }]}>No account yet?</Text>
            <Text style={[styles.link, { color: t.link }]} onPress={() => navigation.navigate('Register')}>
              {' '}Create one free
            </Text>
          </View>
          <Text style={[styles.providers, { color: t.muted }]} onPress={() => navigation.navigate('Register', { providers: true })}>
            Doctor, practice, pharmacy, lab or driver? <Text style={{ color: t.link, fontFamily: font.semibold }}>Join Prompt Aid</Text>
          </Text>
        </ScrollView>
      </KeyboardAvoidingView>
    </AuthBackground>
  );
}

const styles = StyleSheet.create({
  container: { flexGrow: 1, justifyContent: 'center', padding: spacing.lg },
  title: { fontFamily: font.bold, fontSize: 26, color: colors.secondary },
  subtitle: { fontFamily: font.regular, fontSize: 14, color: colors.gray600, marginTop: spacing.xs, marginBottom: spacing.lg },
  card: { backgroundColor: '#FFFFFF', borderRadius: radius.lg, padding: spacing.lg, shadowColor: '#000', shadowOpacity: 0.18, shadowRadius: 18, shadowOffset: { width: 0, height: 8 }, elevation: 6 },
  footer: { flexDirection: 'row', justifyContent: 'center', marginTop: spacing.lg },
  footerText: { fontFamily: font.regular, color: colors.gray600 },
  link: { fontFamily: font.semibold, color: colors.primary },
  providers: { fontFamily: font.regular, fontSize: 13, textAlign: 'center', marginTop: spacing.md },
});
