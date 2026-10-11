import React, { useCallback, useState } from 'react';
import { Alert, Pressable, RefreshControl, ScrollView, Switch, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { Ionicons } from '@expo/vector-icons';
import { api, apiErrorMessage } from '../../api/client';
import { ServiceItem } from '../../api/types';
import { Chip, EmptyNote, Fab, Field, ListRow, PrimaryButton, SectionTitle, Sheet, SoftCard } from '../../components/settings-ui';
import { pa, paFonts } from '../../theme';

interface ClinicLite { id: number; name: string }

const DURATIONS = [10, 15, 20, 30, 45, 60, 90];

/** The doctor's own services (per clinic) and base consultation fee. */
export default function DoctorServicesScreen() {
  const insets = useSafeAreaInsets();
  const [services, setServices] = useState<ServiceItem[]>([]);
  const [clinics, setClinics] = useState<ClinicLite[]>([]);
  const [fee, setFee] = useState('');
  const [savedFee, setSavedFee] = useState('');
  const [loading, setLoading] = useState(true);
  const [savingFee, setSavingFee] = useState(false);
  const [editing, setEditing] = useState<Partial<ServiceItem> | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const [svc, overview] = await Promise.all([api.get('/doctor/services'), api.get('/doctor/settings')]);
      setServices(svc.data.data);
      setClinics(overview.data.clinics);
      const f = String(Math.round(overview.data.consultation_fee ?? 0));
      setFee(f);
      setSavedFee(f);
    } catch (e) {
      Alert.alert('Could not load services', apiErrorMessage(e));
    } finally {
      setLoading(false);
    }
  }, []);

  useFocusEffect(useCallback(() => { load(); }, [load]));

  async function saveFee() {
    setSavingFee(true);
    try {
      const { data } = await api.put('/doctor/fee', { consultation_fee: Number(fee) });
      const f = String(Math.round(data.consultation_fee));
      setFee(f);
      setSavedFee(f);
    } catch (e) {
      Alert.alert('Could not save fee', apiErrorMessage(e));
    } finally {
      setSavingFee(false);
    }
  }

  async function toggleActive(sv: ServiceItem, value: boolean) {
    setServices((list) => list.map((x) => (x.id === sv.id ? { ...x, is_active: value } : x)));
    try {
      await api.put(`/doctor/services/${sv.id}`, { is_active: value });
    } catch (e) {
      setServices((list) => list.map((x) => (x.id === sv.id ? { ...x, is_active: !value } : x)));
      Alert.alert('Could not update service', apiErrorMessage(e));
    }
  }

  const clinicName = (id: number) => clinics.find((c) => c.id === id)?.name ?? 'Clinic';

  return (
    <View style={{ flex: 1, backgroundColor: pa.paper }}>
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: insets.bottom + 100 }} refreshControl={<RefreshControl refreshing={loading} onRefresh={load} />}>
        <SectionTitle>Consultation fee</SectionTitle>
        <SoftCard style={{ marginBottom: 22 }}>
          <Field label="Standard consultation (R)" value={fee} onChangeText={(t) => setFee(t.replace(/[^0-9.]/g, ''))} keyboardType="decimal-pad" />
          <PrimaryButton title="Save fee" onPress={saveFee} loading={savingFee} disabled={fee === savedFee || fee === ''} />
        </SoftCard>

        <SectionTitle>Services you offer</SectionTitle>
        {clinics.length === 0 && !loading ? (
          <SoftCard><EmptyNote icon="business-outline" text="You are not linked to a clinic yet. Ask the clinic admin to add you before creating services." /></SoftCard>
        ) : services.length === 0 && !loading ? (
          <SoftCard><EmptyNote icon="medkit-outline" text="No services yet. Tap + to add one." /></SoftCard>
        ) : (
          <SoftCard style={{ paddingVertical: 4 }}>
            {services.map((sv, i) => (
              <ListRow
                key={sv.id}
                icon="medkit-outline"
                title={sv.name}
                subtitle={`${clinicName(sv.clinic_id)} · ${sv.duration_minutes} min · R ${Math.round(sv.price)}${sv.is_active ? '' : ' · Hidden'}`}
                onPress={() => setEditing(sv)}
                last={i === services.length - 1}
                right={<Switch value={sv.is_active} onValueChange={(v) => toggleActive(sv, v)} trackColor={{ true: pa.go, false: pa.line }} />}
              />
            ))}
          </SoftCard>
        )}
      </ScrollView>

      {clinics.length ? <Fab label="Add service" onPress={() => setEditing({ clinic_id: clinics[0].id, duration_minutes: 30, is_active: true })} bottom={insets.bottom + 24} /> : null}

      {editing ? (
        <ServiceSheet
          initial={editing}
          clinics={clinics}
          onClose={() => setEditing(null)}
          onSaved={() => { setEditing(null); load(); }}
        />
      ) : null}
    </View>
  );
}

function ServiceSheet({ initial, clinics, onClose, onSaved }: { initial: Partial<ServiceItem>; clinics: ClinicLite[]; onClose: () => void; onSaved: () => void }) {
  const [clinicId, setClinicId] = useState<number>(initial.clinic_id ?? clinics[0]?.id);
  const [name, setName] = useState(initial.name ?? '');
  const [price, setPrice] = useState(initial.price !== undefined ? String(Math.round(initial.price)) : '');
  const [duration, setDuration] = useState<number>(initial.duration_minutes ?? 30);
  const [description, setDescription] = useState(initial.description ?? '');
  const [saving, setSaving] = useState(false);
  const isNew = !initial.id;

  async function save() {
    if (!name.trim() || price === '') {
      Alert.alert('Missing details', 'Enter a name and price.');
      return;
    }
    setSaving(true);
    const body = { clinic_id: clinicId, name: name.trim(), price: Number(price), duration_minutes: duration, description: description.trim() || null };
    try {
      if (isNew) await api.post('/doctor/services', body);
      else await api.put(`/doctor/services/${initial.id}`, body);
      onSaved();
    } catch (e) {
      Alert.alert('Could not save service', apiErrorMessage(e));
    } finally {
      setSaving(false);
    }
  }

  function remove() {
    Alert.alert('Delete service', `Remove "${initial.name}"? Services already used by appointments are hidden instead.`, [
      { text: 'Cancel', style: 'cancel' },
      {
        text: 'Delete',
        style: 'destructive',
        onPress: async () => {
          try {
            await api.delete(`/doctor/services/${initial.id}`);
            onSaved();
          } catch (e) {
            Alert.alert('Could not delete', apiErrorMessage(e));
          }
        },
      },
    ]);
  }

  return (
    <Sheet
      visible
      onClose={onClose}
      title={isNew ? 'Add service' : 'Edit service'}
      footer={
        <View style={{ flexDirection: 'row', gap: 10 }}>
          {!isNew ? <PrimaryButton title="Delete" tone="danger" onPress={remove} style={{ flex: 1 }} /> : null}
          <PrimaryButton title="Save" onPress={save} loading={saving} style={{ flex: 2 }} />
        </View>
      }
    >
      {clinics.length > 1 ? (
        <>
          <Text style={{ fontFamily: paFonts.bold, fontSize: 12, color: pa.muted, marginBottom: 6 }}>Clinic</Text>
          <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginBottom: 14 }}>
            {clinics.map((c) => <Chip key={c.id} label={c.name} active={clinicId === c.id} onPress={() => setClinicId(c.id)} />)}
          </View>
        </>
      ) : null}
      <Field label="Service name" value={name} onChangeText={setName} placeholder="e.g. Follow-up consultation" />
      <Field label="Price (R)" value={price} onChangeText={(t) => setPrice(t.replace(/[^0-9.]/g, ''))} keyboardType="decimal-pad" />
      <Text style={{ fontFamily: paFonts.bold, fontSize: 12, color: pa.muted, marginBottom: 6 }}>Duration</Text>
      <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginBottom: 14 }}>
        {DURATIONS.map((d) => <Chip key={d} label={`${d} min`} active={duration === d} onPress={() => setDuration(d)} />)}
      </View>
      <Field label="Description (optional)" value={description} onChangeText={setDescription} multiline style={{ minHeight: 70, textAlignVertical: 'top' }} />
      {!isNew ? (
        <Pressable onPress={onClose} style={{ flexDirection: 'row', alignItems: 'center', gap: 6 }}>
          <Ionicons name="information-circle-outline" size={14} color={pa.muted} />
          <Text style={{ fontFamily: paFonts.regular, fontSize: 12, color: pa.muted }}>Use the switch in the list to hide a service from patients.</Text>
        </Pressable>
      ) : null}
    </Sheet>
  );
}
