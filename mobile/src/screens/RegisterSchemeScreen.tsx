import React, { useCallback, useState } from 'react';
import { KeyboardAvoidingView, Platform, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { api } from '../api/client';
import { MedicalScheme } from '../api/types';
import { Input, PrimaryButton } from '../components/UI';
import { colors, font, radius, spacing } from '../theme';

/**
 * Step 3 — medical scheme membership. Skippable: some patients pay cash.
 */
export default function RegisterSchemeScreen({ navigation, route }: any) {
  const { account, medical } = route.params;
  const [schemes, setSchemes] = useState<MedicalScheme[]>([]);
  const [schemeId, setSchemeId] = useState<number | null>(null);
  const [memberNumber, setMemberNumber] = useState('');
  const [dependantCode, setDependantCode] = useState('');
  const [mainMemberName, setMainMemberName] = useState('');

  useFocusEffect(
    useCallback(() => {
      api.get<{ data: MedicalScheme[] } | MedicalScheme[]>('/medical-schemes').then(({ data }) => {
        setSchemes(Array.isArray(data) ? data : data.data);
      }).catch(() => setSchemes([]));
    }, [])
  );

  function next() {
    const scheme = schemeId
      ? {
          medical_scheme_id: schemeId,
          member_number: memberNumber || undefined,
          dependant_code: dependantCode || undefined,
          main_member_name: mainMemberName || undefined,
        }
      : undefined;
    navigation.navigate('RegisterDocument', { account, medical, scheme });
  }

  function skip() {
    navigation.navigate('RegisterDocument', { account, medical, scheme: undefined });
  }

  return (
    <KeyboardAvoidingView style={{ flex: 1, backgroundColor: colors.gray50 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <ScrollView contentContainerStyle={styles.container}>
        <Text style={styles.title}>Medical scheme</Text>
        <Text style={styles.subtitle}>Optional — paying cash? Skip this and add it later from your profile.</Text>

        <View style={styles.schemeList}>
          {schemes.map((s) => {
            const on = s.id === schemeId;
            return (
              <Pressable key={s.id} onPress={() => setSchemeId(on ? null : s.id)} style={[styles.schemeOption, on && styles.schemeOptionOn]}>
                <Text style={[styles.schemeText, on && styles.schemeTextOn]}>{s.name}</Text>
              </Pressable>
            );
          })}
          {!schemes.length && <Text style={styles.empty}>Loading schemes…</Text>}
        </View>

        {schemeId ? (
          <>
            <Input label="Member number" value={memberNumber} onChangeText={setMemberNumber} />
            <Input label="Dependant code" value={dependantCode} onChangeText={setDependantCode} />
            <Input label="Main member name" value={mainMemberName} onChangeText={setMainMemberName} />
          </>
        ) : null}

        <PrimaryButton title="Continue" onPress={next} />
        <Text style={styles.skip} onPress={skip}>Skip this step</Text>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  container: { flexGrow: 1, padding: spacing.lg },
  title: { fontFamily: font.bold, fontSize: 24, color: colors.secondary },
  subtitle: { fontFamily: font.regular, fontSize: 14, color: colors.gray600, marginTop: spacing.xs, marginBottom: spacing.lg },
  schemeList: { gap: spacing.sm, marginBottom: spacing.md },
  schemeOption: { paddingVertical: 12, paddingHorizontal: 14, borderRadius: radius.md, borderWidth: 1, borderColor: colors.gray300 },
  schemeOptionOn: { backgroundColor: colors.primaryLight, borderColor: colors.primary },
  schemeText: { fontFamily: font.regular, color: colors.gray700 },
  schemeTextOn: { fontFamily: font.semibold, color: colors.primary },
  empty: { fontFamily: font.regular, color: colors.gray500, fontSize: 13 },
  skip: { fontFamily: font.semibold, color: colors.gray600, textAlign: 'center', marginTop: spacing.md },
});
