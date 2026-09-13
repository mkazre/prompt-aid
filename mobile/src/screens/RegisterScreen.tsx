import React, { useState } from 'react';
import { Alert, KeyboardAvoidingView, Platform, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { apiErrorMessage, useAuth } from '../context/AuthContext';
import { Input, PrimaryButton } from '../components/UI';
import { colors, font, radius, spacing } from '../theme';
import { Role } from '../api/types';

export default function RegisterScreen({ navigation }: any) {
  const { register } = useAuth();
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [phone, setPhone] = useState('');
  const [password, setPassword] = useState('');
  const [role, setRole] = useState<Role>('patient');
  const [loading, setLoading] = useState(false);

  async function handleRegister() {
    setLoading(true);
    try {
      await register({ name, email: email.trim(), phone: phone || undefined, password, role });
    } catch (e) {
      Alert.alert('Could not create account', apiErrorMessage(e, 'Please check your details and try again.'));
    } finally {
      setLoading(false);
    }
  }

  return (
    <KeyboardAvoidingView style={{ flex: 1, backgroundColor: colors.gray50 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <ScrollView contentContainerStyle={styles.container}>
        <Text style={styles.title}>Create your account</Text>
        <Text style={styles.subtitle}>Book doctors and request your patient shuttle in minutes.</Text>

        <View style={styles.roleToggle}>
          {(['patient', 'driver'] as Role[]).map((r) => (
            <Pressable key={r} onPress={() => setRole(r)} style={[styles.roleOption, role === r && styles.roleOptionActive]}>
              <Text style={[styles.roleText, role === r && styles.roleTextActive]}>{r === 'patient' ? '🧑 Patient' : '🚗 Driver'}</Text>
            </Pressable>
          ))}
        </View>

        <Input label="Full name" value={name} onChangeText={setName} placeholder="Jane Doe" />
        <Input label="Email" autoCapitalize="none" keyboardType="email-address" value={email} onChangeText={setEmail} placeholder="you@example.com" />
        <Input label="Phone" keyboardType="phone-pad" value={phone} onChangeText={setPhone} placeholder="+27 71 234 5678" />
        <Input label="Password" secureTextEntry value={password} onChangeText={setPassword} placeholder="At least 8 characters" />

        <PrimaryButton title="Create account" onPress={handleRegister} loading={loading} disabled={!name || !email || password.length < 8} />

        <View style={styles.footer}>
          <Text style={styles.footerText}>Already have an account?</Text>
          <Text style={styles.link} onPress={() => navigation.navigate('Login')}> Sign in</Text>
        </View>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  container: { flexGrow: 1, justifyContent: 'center', padding: spacing.lg },
  title: { fontFamily: font.bold, fontSize: 26, color: colors.secondary },
  subtitle: { fontFamily: font.regular, fontSize: 14, color: colors.gray600, marginTop: spacing.xs, marginBottom: spacing.lg },
  roleToggle: { flexDirection: 'row', gap: spacing.sm, marginBottom: spacing.md },
  roleOption: { flex: 1, paddingVertical: 12, borderRadius: radius.md, borderWidth: 1, borderColor: colors.gray300, alignItems: 'center' },
  roleOptionActive: { backgroundColor: colors.primaryLight, borderColor: colors.primary },
  roleText: { fontFamily: font.medium, color: colors.gray600 },
  roleTextActive: { color: colors.primary },
  footer: { flexDirection: 'row', justifyContent: 'center', marginTop: spacing.lg },
  footerText: { fontFamily: font.regular, color: colors.gray600 },
  link: { fontFamily: font.semibold, color: colors.primary },
});
