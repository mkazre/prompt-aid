import React, { useCallback, useState } from 'react';
import { Alert, FlatList, RefreshControl, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { api, apiErrorMessage } from '../../../api/client';
import { FamilyMember } from '../../../api/health';
import { PaEmptyState } from '../../../components/pa';
import { Fab, Field, PrimaryButton, Sheet } from '../../../components/settings-ui';
import { ActionChip, hs, IconCircle, OptionPills, Pill } from '../../../components/HealthUI';

const RELATIONSHIPS: { value: string; label: string }[] = [
  { value: 'spouse', label: 'Spouse / partner' },
  { value: 'child', label: 'Child' },
  { value: 'parent', label: 'Parent' },
  { value: 'sibling', label: 'Sibling' },
  { value: 'grandparent', label: 'Grandparent' },
  { value: 'other', label: 'Other' },
];
const GENDERS = [
  { value: 'male', label: 'Male' },
  { value: 'female', label: 'Female' },
  { value: 'other', label: 'Other' },
];
const COLORS: Record<string, string> = { spouse: '#D0211C', child: '#1F7A4C', parent: '#1F4E7A', sibling: '#6A4C93', grandparent: '#B4520F', other: '#3A3A3E' };

type Form = { name: string; relationship: string; dob: string; gender: string | null; id_number: string; medical_aid_scheme: string; medical_aid_number: string; allergies: string };
const EMPTY: Form = { name: '', relationship: 'child', dob: '', gender: null, id_number: '', medical_aid_scheme: '', medical_aid_number: '', allergies: '' };

/** Family members (dependants): add, edit and remove. */
export default function FamilyMembersScreen() {
  const [members, setMembers] = useState<FamilyMember[]>([]);
  const [loading, setLoading] = useState(false);
  const [editing, setEditing] = useState<FamilyMember | null>(null);
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState<Form>(EMPTY);
  const [saving, setSaving] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const { data } = await api.get('/family-members');
      setMembers(data.data);
    } catch (e) {
      Alert.alert('Could not load', apiErrorMessage(e));
    } finally {
      setLoading(false);
    }
  }, []);

  useFocusEffect(useCallback(() => { load(); }, [load]));

  function openForm(m?: FamilyMember) {
    setEditing(m ?? null);
    setForm(m ? {
      name: m.name, relationship: m.relationship, dob: m.dob ?? '', gender: m.gender, id_number: m.id_number ?? '',
      medical_aid_scheme: m.medical_aid_scheme ?? '', medical_aid_number: m.medical_aid_number ?? '', allergies: m.allergies ?? '',
    } : EMPTY);
    setOpen(true);
  }

  async function save() {
    if (!form.name.trim()) return Alert.alert('Name required', 'Enter the family member’s name.');
    if (form.dob && !/^\d{4}-\d{2}-\d{2}$/.test(form.dob)) return Alert.alert('Date of birth', 'Use the format YYYY-MM-DD.');
    setSaving(true);
    const body = { ...form, name: form.name.trim(), dob: form.dob || null, gender: form.gender || null };
    try {
      if (editing) await api.put(`/family-members/${editing.id}`, body);
      else await api.post('/family-members', body);
      setOpen(false);
      await load();
    } catch (e) {
      Alert.alert('Could not save', apiErrorMessage(e));
    } finally {
      setSaving(false);
    }
  }

  function remove(m: FamilyMember) {
    Alert.alert('Remove family member?', m.name, [
      { text: 'Cancel', style: 'cancel' },
      {
        text: 'Remove', style: 'destructive', onPress: async () => {
          try {
            await api.delete(`/family-members/${m.id}`);
            await load();
          } catch (e) {
            Alert.alert('Could not remove', apiErrorMessage(e));
          }
        },
      },
    ]);
  }

  const set = (k: keyof Form) => (v: string) => setForm((f) => ({ ...f, [k]: v }));

  return (
    <View style={hs.screen}>
      <FlatList
        contentContainerStyle={{ padding: 16, paddingBottom: 110 }}
        data={members}
        keyExtractor={(m) => String(m.id)}
        refreshControl={<RefreshControl refreshing={loading} onRefresh={load} />}
        ListEmptyComponent={!loading ? <PaEmptyState message="No family members yet. Tap + to add one." /> : null}
        renderItem={({ item: m }) => (
          <View style={hs.card}>
            <View style={hs.row}>
              <IconCircle icon="user" color={COLORS[m.relationship] ?? '#3A3A3E'} />
              <View style={{ flex: 1 }}>
                <Text style={hs.title}>{m.name}</Text>
                <Text style={hs.sub}>{m.relationship_label}{m.age !== null ? ` · ${m.age} yrs` : ''}{m.gender ? ` · ${m.gender}` : ''}</Text>
                {m.medical_aid_scheme ? <Text style={hs.sub}>{m.medical_aid_scheme}{m.medical_aid_number ? ` · ${m.medical_aid_number}` : ''}</Text> : null}
              </View>
            </View>
            {m.allergies ? <View style={{ marginTop: 10 }}><Pill label={`Allergies: ${m.allergies}`} fg="#C8102E" bg="#FBEAEA" /></View> : null}
            <View style={hs.actions}>
              <ActionChip icon="edit-2" label="Edit" onPress={() => openForm(m)} />
              <ActionChip icon="trash-2" label="Remove" tone="danger" onPress={() => remove(m)} />
            </View>
          </View>
        )}
      />
      <Fab label="Add family member" onPress={() => openForm()} />
      <Sheet
        visible={open}
        onClose={() => setOpen(false)}
        title={editing ? 'Edit family member' : 'Add family member'}
        footer={<PrimaryButton title={editing ? 'Save changes' : 'Add'} onPress={save} loading={saving} />}
      >
        <Field label="Full name" value={form.name} onChangeText={set('name')} placeholder="e.g. Lerato Mokoena" />
        <Text style={hs.label}>Relationship</Text>
        <OptionPills options={RELATIONSHIPS} value={form.relationship} onChange={set('relationship')} />
        <Field label="Date of birth (YYYY-MM-DD)" value={form.dob} onChangeText={set('dob')} placeholder="2015-06-30" keyboardType="numbers-and-punctuation" />
        <Text style={hs.label}>Gender</Text>
        <OptionPills options={GENDERS} value={form.gender} onChange={set('gender')} />
        <Field label="ID number (optional)" value={form.id_number} onChangeText={set('id_number')} keyboardType="number-pad" />
        <Field label="Medical aid scheme (optional)" value={form.medical_aid_scheme} onChangeText={set('medical_aid_scheme')} />
        <Field label="Medical aid number (optional)" value={form.medical_aid_number} onChangeText={set('medical_aid_number')} />
        <Field label="Allergies" value={form.allergies} onChangeText={set('allergies')} placeholder="e.g. Penicillin, peanuts" />
      </Sheet>
    </View>
  );
}
