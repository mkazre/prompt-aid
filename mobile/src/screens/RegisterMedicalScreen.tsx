import React, { useState } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet, Text } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { Input, PrimaryButton } from '../components/UI';
import { colors, font, spacing } from '../theme';

/**
 * Step 2 of the patient signup wizard — everything here is optional, so
 * "Continue" and "Skip" both just forward whatever was typed.
 */
export default function RegisterMedicalScreen({ navigation, route }: any) {
  const insets = useSafeAreaInsets();
  const { account } = route.params;
  const [allergies, setAllergies] = useState('');
  const [chronicConditions, setChronicConditions] = useState('');
  const [emergencyContactName, setEmergencyContactName] = useState('');
  const [emergencyContactPhone, setEmergencyContactPhone] = useState('');

  function next() {
    const medical = {
      allergies: allergies || undefined,
      chronic_conditions: chronicConditions || undefined,
      emergency_contact_name: emergencyContactName || undefined,
      emergency_contact_phone: emergencyContactPhone || undefined,
    };
    navigation.navigate('RegisterScheme', { account, medical });
  }

  return (
    <KeyboardAvoidingView style={{ flex: 1, backgroundColor: colors.gray50 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <ScrollView contentContainerStyle={[styles.container, { paddingTop: insets.top + spacing.lg }]}>
        <Text style={styles.title}>Medical details</Text>
        <Text style={styles.subtitle}>Optional, but helps a doctor treat you faster in an emergency.</Text>

        <Input label="Allergies" value={allergies} onChangeText={setAllergies} placeholder="e.g. Penicillin" />
        <Input label="Chronic conditions" value={chronicConditions} onChangeText={setChronicConditions} placeholder="e.g. Asthma, diabetes" />
        <Input label="Emergency contact name" value={emergencyContactName} onChangeText={setEmergencyContactName} />
        <Input label="Emergency contact mobile" keyboardType="phone-pad" value={emergencyContactPhone} onChangeText={setEmergencyContactPhone} />

        <PrimaryButton title="Continue" onPress={next} />
        <Text style={styles.skip} onPress={next}>Skip this step</Text>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  container: { flexGrow: 1, padding: spacing.lg },
  title: { fontFamily: font.bold, fontSize: 24, color: colors.secondary },
  subtitle: { fontFamily: font.regular, fontSize: 14, color: colors.gray600, marginTop: spacing.xs, marginBottom: spacing.lg },
  skip: { fontFamily: font.semibold, color: colors.gray600, textAlign: 'center', marginTop: spacing.md },
});
