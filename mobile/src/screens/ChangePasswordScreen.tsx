import React, { useState } from 'react';
import { Alert, KeyboardAvoidingView, Platform, ScrollView, Text } from 'react-native';
import axios from 'axios';
import { api, apiErrorMessage } from '../api/client';
import { Field, PrimaryButton, SoftCard } from '../components/settings-ui';
import { pa, paFonts } from '../theme';

/**
 * Change password for any role — POST /profile/password. The API checks
 * the current password and signs out every other device.
 */
export default function ChangePasswordScreen({ navigation }: any) {
  const [current, setCurrent] = useState('');
  const [next, setNext] = useState('');
  const [confirm, setConfirm] = useState('');
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});

  async function save() {
    const local: Record<string, string> = {};
    if (!current) local.current_password = 'Enter your current password.';
    if (next.length < 8) local.password = 'Use at least 8 characters.';
    if (next !== confirm) local.password_confirmation = 'Passwords do not match.';
    setErrors(local);
    if (Object.keys(local).length) return;

    setSaving(true);
    try {
      const { data } = await api.post('/profile/password', {
        current_password: current,
        password: next,
        password_confirmation: confirm,
      });
      Alert.alert('Password changed', data.message ?? 'Your password has been updated.', [{ text: 'OK', onPress: () => navigation.goBack() }]);
    } catch (e) {
      if (axios.isAxiosError(e) && e.response?.status === 422 && e.response.data?.errors) {
        const fieldErrors: Record<string, string> = {};
        Object.entries(e.response.data.errors as Record<string, string[]>).forEach(([k, v]) => (fieldErrors[k] = v[0]));
        setErrors(fieldErrors);
      } else {
        Alert.alert('Could not change password', apiErrorMessage(e));
      }
    } finally {
      setSaving(false);
    }
  }

  return (
    <KeyboardAvoidingView style={{ flex: 1, backgroundColor: pa.paper }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <ScrollView contentContainerStyle={{ padding: 16 }} keyboardShouldPersistTaps="handled">
        <SoftCard>
          <Field label="Current password" value={current} onChangeText={setCurrent} secureTextEntry autoCapitalize="none" error={errors.current_password} textContentType="password" />
          <Field label="New password" value={next} onChangeText={setNext} secureTextEntry autoCapitalize="none" error={errors.password} textContentType="newPassword" />
          <Field label="Confirm new password" value={confirm} onChangeText={setConfirm} secureTextEntry autoCapitalize="none" error={errors.password_confirmation} textContentType="newPassword" />
          <Text style={{ fontFamily: paFonts.regular, fontSize: 12, color: pa.muted, marginBottom: 14 }}>
            At least 8 characters. Other devices signed in to your account will be logged out.
          </Text>
          <PrimaryButton title="Update password" onPress={save} loading={saving} />
        </SoftCard>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}
