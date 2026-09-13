import React from 'react';
import { Image, SafeAreaView, StyleSheet, Text, View } from 'react-native';
import { PrimaryButton } from '../components/UI';
import { colors, font, spacing } from '../theme';

export default function OnboardingScreen({ navigation }: any) {
  return (
    <SafeAreaView style={styles.container}>
      <View style={styles.hero}>
        <Image source={require('../../assets/splash-icon.png')} style={styles.logo} resizeMode="contain" />
        <Text style={styles.title}>Prompt Aid</Text>
        <Text style={styles.subtitle}>
          Book trusted doctors, manage your care, and get a free door-to-door shuttle to every appointment.
        </Text>
      </View>

      <View style={styles.features}>
        {[
          ['🩺', 'Find & book a doctor in seconds'],
          ['🚐', 'Free patient shuttle, tracked live'],
          ['📋', 'Prescriptions & invoices, always on hand'],
        ].map(([icon, text]) => (
          <View key={text} style={styles.featureRow}>
            <Text style={styles.featureIcon}>{icon}</Text>
            <Text style={styles.featureText}>{text}</Text>
          </View>
        ))}
      </View>

      <View style={styles.actions}>
        <PrimaryButton title="Create free account" onPress={() => navigation.navigate('Register')} />
        <View style={{ height: spacing.sm }} />
        <PrimaryButton title="I already have an account" variant="outline" onPress={() => navigation.navigate('Login')} />
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: colors.gray50, justifyContent: 'space-between', padding: spacing.lg },
  hero: { alignItems: 'center', marginTop: spacing.xxl },
  logo: { width: 120, height: 120 },
  title: { fontFamily: font.bold, fontSize: 28, color: colors.secondary, marginTop: spacing.md },
  subtitle: { fontFamily: font.regular, fontSize: 15, color: colors.gray600, textAlign: 'center', marginTop: spacing.sm, paddingHorizontal: spacing.md },
  features: { gap: spacing.md },
  featureRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.md },
  featureIcon: { fontSize: 24 },
  featureText: { fontFamily: font.medium, fontSize: 15, color: colors.gray800, flex: 1 },
  actions: { marginBottom: spacing.md },
});
