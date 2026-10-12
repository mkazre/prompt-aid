import React, { useCallback, useState } from 'react';
import { Alert, Image, Linking, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { Feather } from '@expo/vector-icons';
import { useFocusEffect } from '@react-navigation/native';
import { api, apiErrorMessage } from '../../../api/client';
import { MedicalId } from '../../../api/health';
import { Field, PrimaryButton, Sheet } from '../../../components/settings-ui';
import { ActionChip, hs, OptionPills } from '../../../components/HealthUI';
import { useAuth } from '../../../context/AuthContext';
import { pa, paFonts } from '../../../theme';

const BLOOD = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

/** Medical ID card: blood group, allergies, conditions, meds, contacts, medical aid. */
export default function MedicalIdScreen({ navigation }: any) {
  const { refreshUser } = useAuth() as any;
  const [card, setCard] = useState<MedicalId | null>(null);
  const [loading, setLoading] = useState(false);
  const [open, setOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const [form, setForm] = useState({ blood_group: '', allergies: '', chronic_conditions: '', emergency_contact_name: '', emergency_contact_phone: '' });

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const { data } = await api.get('/medical-id');
      setCard(data.data);
    } catch (e) {
      Alert.alert('Could not load', apiErrorMessage(e));
    } finally {
      setLoading(false);
    }
  }, []);

  useFocusEffect(useCallback(() => { load(); }, [load]));

  function edit() {
    if (!card) return;
    setForm({
      blood_group: card.blood_group ?? '',
      allergies: card.allergies ?? '',
      chronic_conditions: card.chronic_conditions ?? '',
      emergency_contact_name: card.emergency_contact.name ?? '',
      emergency_contact_phone: card.emergency_contact.phone ?? '',
    });
    setOpen(true);
  }

  async function save() {
    setSaving(true);
    try {
      const { data } = await api.put('/medical-id', form);
      setCard(data.data);
      setOpen(false);
      if (typeof refreshUser === 'function') refreshUser();
    } catch (e) {
      Alert.alert('Could not save', apiErrorMessage(e));
    } finally {
      setSaving(false);
    }
  }

  const set = (k: keyof typeof form) => (v: string) => setForm((f) => ({ ...f, [k]: v }));

  return (
    <View style={hs.screen}>
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }} refreshControl={<RefreshControl refreshing={loading} onRefresh={load} />}>
        {card ? (
          <View style={styles.card}>
            <View style={styles.head}>
              <Feather name="plus-square" size={18} color="#fff" />
              <Text style={styles.headText}>MEDICAL ID</Text>
            </View>
            <View style={{ padding: 16 }}>
              <View style={hs.row}>
                {card.photo_url ? (
                  <Image source={{ uri: card.photo_url }} style={styles.photo} />
                ) : (
                  <View style={[styles.photo, { alignItems: 'center', justifyContent: 'center', backgroundColor: pa.paper }]}><Text style={{ fontFamily: paFonts.black, fontSize: 24, color: pa.ink }}>{card.initials}</Text></View>
                )}
                <View style={{ flex: 1 }}>
                  <Text style={{ fontFamily: paFonts.black, fontSize: 20, color: pa.ink }}>{card.name}</Text>
                  <Text style={hs.sub}>{[card.dob ? `${card.dob} (${card.age} yrs)` : null, card.gender].filter(Boolean).join(' · ')}</Text>
                </View>
                <View style={styles.blood}><Text style={styles.bloodText}>{card.blood_group || '—'}</Text></View>
              </View>

              <Item icon="alert-triangle" color={pa.sats.red} label="Allergies" value={card.allergies || 'None recorded'} danger={!!card.allergies} />
              <Item icon="activity" color="#6A4C93" label="Chronic conditions" value={card.chronic_conditions || 'None recorded'} />
              <Item icon="package" color="#1F7A4C" label="Current medications" value={card.medications.length ? card.medications.map((m) => `${m.name} — ${m.dose} (${m.times.join(', ')})`).join('\n') : 'None recorded'} />
              <Item icon="phone" color="#1F4E7A" label="Emergency contact" value={card.emergency_contact.name ? `${card.emergency_contact.name}${card.emergency_contact.phone ? `\n${card.emergency_contact.phone}` : ''}` : 'Not set'} onPress={card.emergency_contact.phone ? () => Linking.openURL(`tel:${card.emergency_contact.phone}`) : undefined} />
              <Item icon="credit-card" color="#B4520F" label="Medical aid" value={card.medical_aid ? `${card.medical_aid.scheme ?? ''}\nNo. ${card.medical_aid.member_number}${card.medical_aid.dependant_code ? ` · dep ${card.medical_aid.dependant_code}` : ''}` : 'None / private'} />
              {card.emergency_numbers.length ? (
                <Item icon="life-buoy" color={pa.signal} label="Emergency numbers" value={card.emergency_numbers.slice(0, 3).map((n) => `${n.phone}  ${n.label}`).join('\n')} />
              ) : null}
            </View>
            <Text style={[hs.note, { padding: 14, borderTopWidth: 1, borderTopColor: pa.line }]}>{card.note}</Text>
          </View>
        ) : null}

        <View style={hs.actions}>
          <ActionChip icon="edit-2" label="Edit details" tone="ink" onPress={edit} />
          <ActionChip icon="package" label="Medications" onPress={() => navigation.navigate('Medications')} />
          <ActionChip icon="credit-card" label="Medical aid" onPress={() => navigation.navigate('ProfileHome')} />
        </View>
      </ScrollView>

      <Sheet visible={open} onClose={() => setOpen(false)} title="Edit medical details" footer={<PrimaryButton title="Save" onPress={save} loading={saving} />}>
        <Text style={hs.label}>Blood group</Text>
        <OptionPills options={BLOOD.map((b) => ({ value: b, label: b }))} value={form.blood_group} onChange={set('blood_group')} />
        <Field label="Allergies" value={form.allergies} onChangeText={set('allergies')} placeholder="Comma separated" multiline />
        <Field label="Chronic conditions" value={form.chronic_conditions} onChangeText={set('chronic_conditions')} placeholder="Comma separated" multiline />
        <Field label="Emergency contact name" value={form.emergency_contact_name} onChangeText={set('emergency_contact_name')} />
        <Field label="Emergency contact phone" value={form.emergency_contact_phone} onChangeText={set('emergency_contact_phone')} keyboardType="phone-pad" />
      </Sheet>
    </View>
  );
}

function Item({ icon, color, label, value, danger, onPress }: { icon: any; color: string; label: string; value: string; danger?: boolean; onPress?: () => void }) {
  return (
    <View style={{ flexDirection: 'row', gap: 12, marginTop: 16 }}>
      <View style={{ width: 32, height: 32, borderRadius: 16, backgroundColor: color + '1F', alignItems: 'center', justifyContent: 'center' }}>
        <Feather name={icon} size={16} color={color} />
      </View>
      <View style={{ flex: 1 }}>
        <Text style={styles.k}>{label}</Text>
        <Text style={[styles.v, danger && { color: pa.sats.red }]} onPress={onPress}>{value}</Text>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  card: { backgroundColor: pa.surface, borderRadius: 16, borderWidth: 2, borderColor: pa.signal, overflow: 'hidden' },
  head: { backgroundColor: pa.signal, flexDirection: 'row', alignItems: 'center', gap: 8, paddingHorizontal: 16, paddingVertical: 10 },
  headText: { fontFamily: paFonts.black, color: '#fff', letterSpacing: 2, fontSize: 13 },
  photo: { width: 64, height: 64, borderRadius: 32 },
  blood: { backgroundColor: pa.signalWash, borderRadius: 10, paddingHorizontal: 12, paddingVertical: 8 },
  bloodText: { fontFamily: paFonts.black, fontSize: 20, color: pa.signalInk },
  k: { fontFamily: paFonts.black, fontSize: 10, letterSpacing: 1.2, textTransform: 'uppercase', color: pa.muted },
  v: { fontFamily: paFonts.bold, fontSize: 14, color: pa.ink, marginTop: 2, lineHeight: 20 },
});
