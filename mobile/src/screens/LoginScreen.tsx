import React, { useState } from 'react';
import { Alert, KeyboardAvoidingView, Platform, ScrollView, StyleSheet, Text, View } from 'react-native';
import { apiErrorMessage, useAuth } from '../context/AuthContext';
import { Input, PrimaryButton } from '../components/UI';
import { colors, font, spacing } from '../theme';

export default function LoginScreen({ navigation }: any) {
  const { login } = useAuth();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [loading, setLoading] = useState(false);

  async function handleLogin() {
    setLoading(true);
    try {
      await login(email.trim(), password);
    } catch (e) {
      Alert.alert('Sign in failed', apiErrorMessage(e, 'Check your email and password and try again.'));
    } finally {
      setLoading(false);
    }
  }

  return (
    <KeyboardAvoidingView style={{ flex: 1, backgroundColor: colors.gray50 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <ScrollView contentContainerStyle={styles.container}>
        <Text style={styles.title}>Welcome back</Text>
        <Text style={styles.subtitle}>Sign in to manage your appointments and rides.</Text>

        <Input label="Email" autoCapitalize="none" keyboardType="email-address" value={email} onChangeText={setEmail} placeholder="you@example.com" />
        <Input label="Password" secureTextEntry value={password} onChangeText={setPassword} placeholder="••••••••" />

        <PrimaryButton title="Sign in" onPress={handleLogin} loading={loading} disabled={!email || !password} />

        <View style={styles.footer}>
          <Text style={styles.footerText}>No account yet?</Text>
          <Text style={styles.link} onPress={() => navigation.navigate('Register')}> Create one free</Text>
        </View>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  container: { flexGrow: 1, justifyContent: 'center', padding: spacing.lg },
  title: { fontFamily: font.bold, fontSize: 26, color: colors.secondary },
  subtitle: { fontFamily: font.regular, fontSize: 14, color: colors.gray600, marginTop: spacing.xs, marginBottom: spacing.lg },
  footer: { flexDirection: 'row', justifyContent: 'center', marginTop: spacing.lg },
  footerText: { fontFamily: font.regular, color: colors.gray600 },
  link: { fontFamily: font.semibold, color: colors.primary },
});
