import React from 'react';
import { ActivityIndicator, Modal, Pressable, StyleSheet, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { pa, paFonts } from '../theme';

export interface SuccessModalDetail {
  label: string;
  value: string;
}

/**
 * Reusable confirmation sheet — "Payment Success", "Appointment confirmed",
 * "Ride requested". A check in a soft circle, title, body, optional detail
 * rows, a primary button and an optional quiet secondary button.
 *
 * Only show it after the API call it describes has actually succeeded.
 */
export default function SuccessModal({
  visible,
  title,
  message,
  details,
  primaryLabel,
  onPrimary,
  primaryLoading,
  secondaryLabel,
  onSecondary,
  onClose,
  icon = 'checkmark',
}: {
  visible: boolean;
  title: string;
  message?: string;
  details?: SuccessModalDetail[];
  primaryLabel: string;
  onPrimary: () => void;
  primaryLoading?: boolean;
  secondaryLabel?: string;
  onSecondary?: () => void;
  onClose?: () => void;
  icon?: React.ComponentProps<typeof Ionicons>['name'];
}) {
  return (
    <Modal visible={visible} transparent animationType="fade" onRequestClose={onClose ?? onSecondary ?? onPrimary} statusBarTranslucent>
      <View style={styles.backdrop}>
        <View style={styles.card}>
          {onClose ? (
            <Pressable onPress={onClose} hitSlop={12} style={styles.close} accessibilityLabel="Close">
              <Ionicons name="close" size={22} color="#6B6B70" />
            </Pressable>
          ) : null}

          <View style={styles.halo}>
            <View style={styles.circle}>
              <Ionicons name={icon} size={34} color="#fff" />
            </View>
          </View>

          <Text style={styles.title}>{title}</Text>
          {message ? <Text style={styles.message}>{message}</Text> : null}

          {details && details.length > 0 ? (
            <View style={styles.details}>
              {details.map((d) => (
                <View key={d.label} style={styles.detailRow}>
                  <Text style={styles.detailLabel}>{d.label}</Text>
                  <Text style={styles.detailValue} numberOfLines={2}>
                    {d.value}
                  </Text>
                </View>
              ))}
            </View>
          ) : null}

          <Pressable
            onPress={onPrimary}
            disabled={primaryLoading}
            style={({ pressed }) => [styles.primary, { opacity: pressed || primaryLoading ? 0.85 : 1 }]}
            accessibilityRole="button"
          >
            {primaryLoading ? <ActivityIndicator color="#fff" /> : <Text style={styles.primaryText}>{primaryLabel}</Text>}
          </Pressable>

          {secondaryLabel && onSecondary ? (
            <Pressable onPress={onSecondary} style={styles.secondary} accessibilityRole="button">
              <Text style={styles.secondaryText}>{secondaryLabel}</Text>
            </Pressable>
          ) : null}
        </View>
      </View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  backdrop: { flex: 1, backgroundColor: 'rgba(16,16,18,0.45)', alignItems: 'center', justifyContent: 'center', padding: 24 },
  card: {
    width: '100%',
    maxWidth: 380,
    backgroundColor: '#fff',
    borderRadius: 28,
    paddingHorizontal: 24,
    paddingTop: 32,
    paddingBottom: 20,
    alignItems: 'center',
    shadowColor: '#000',
    shadowOpacity: 0.18,
    shadowRadius: 24,
    shadowOffset: { width: 0, height: 12 },
    elevation: 12,
  },
  close: { position: 'absolute', top: 16, right: 16 },
  halo: { width: 104, height: 104, borderRadius: 52, backgroundColor: pa.goWash, alignItems: 'center', justifyContent: 'center', marginBottom: 20 },
  circle: { width: 68, height: 68, borderRadius: 34, backgroundColor: pa.go, alignItems: 'center', justifyContent: 'center' },
  title: { fontFamily: paFonts.black, fontSize: 22, color: pa.ink, textAlign: 'center', marginBottom: 8 },
  message: { fontFamily: paFonts.regular, fontSize: 14, lineHeight: 21, color: '#6B6B70', textAlign: 'center', marginBottom: 16 },
  details: { alignSelf: 'stretch', backgroundColor: '#F6F6F8', borderRadius: 16, paddingVertical: 6, paddingHorizontal: 14, marginBottom: 18 },
  detailRow: { flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 8, gap: 12 },
  detailLabel: { fontFamily: paFonts.regular, fontSize: 13, color: '#6B6B70' },
  detailValue: { fontFamily: paFonts.bold, fontSize: 13, color: pa.ink, flexShrink: 1, textAlign: 'right' },
  primary: { alignSelf: 'stretch', height: 52, borderRadius: 26, backgroundColor: pa.ink, alignItems: 'center', justifyContent: 'center', marginTop: 4 },
  primaryText: { fontFamily: paFonts.bold, fontSize: 15, color: '#fff' },
  secondary: { alignSelf: 'stretch', height: 44, alignItems: 'center', justifyContent: 'center', marginTop: 6 },
  secondaryText: { fontFamily: paFonts.bold, fontSize: 14, color: '#6B6B70' },
});
