import React, { useCallback, useState } from 'react';
import { Alert, FlatList, Image, RefreshControl, Text, View } from 'react-native';
import { Feather } from '@expo/vector-icons';
import { useFocusEffect } from '@react-navigation/native';
import { api, apiErrorMessage } from '../../../api/client';
import { FavouriteDoctor } from '../../../api/health';
import { PaAvatar, PaEmptyState } from '../../../components/pa';
import { ActionChip, hs } from '../../../components/HealthUI';
import { pa } from '../../../theme';

/** "My doctors": doctors saved with the heart on DoctorDetail, with quick Book. */
export default function MyDoctorsScreen({ navigation }: any) {
  const [doctors, setDoctors] = useState<FavouriteDoctor[]>([]);
  const [loading, setLoading] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const { data } = await api.get('/favourites/doctors');
      setDoctors(data.data);
    } catch (e) {
      Alert.alert('Could not load', apiErrorMessage(e));
    } finally {
      setLoading(false);
    }
  }, []);

  useFocusEffect(useCallback(() => { load(); }, [load]));

  async function remove(d: FavouriteDoctor) {
    try {
      await api.delete(`/favourites/doctors/${d.id}`);
      setDoctors((list) => list.filter((x) => x.id !== d.id));
    } catch (e) {
      Alert.alert('Could not remove', apiErrorMessage(e));
    }
  }

  return (
    <FlatList
      style={hs.screen}
      contentContainerStyle={{ padding: 16, paddingBottom: 40 }}
      data={doctors}
      keyExtractor={(d) => String(d.id)}
      refreshControl={<RefreshControl refreshing={loading} onRefresh={load} />}
      ListHeaderComponent={<Text style={[hs.note, { marginBottom: 12 }]}>Tap the heart on any doctor’s page to save them here.</Text>}
      ListEmptyComponent={!loading ? (
        <View>
          <PaEmptyState message="No saved doctors yet." />
          <ActionChip icon="search" label="Find a doctor" tone="ink" style={{ alignSelf: 'center' }} onPress={() => navigation.navigate('Care', { screen: 'FindCare' })} />
        </View>
      ) : null}
      renderItem={({ item: d }) => (
        <View style={hs.card}>
          <View style={hs.row}>
            {d.avatar_url ? <Image source={{ uri: d.avatar_url }} style={{ width: 48, height: 48, borderRadius: 24 }} /> : <PaAvatar name={d.name} size={48} />}
            <View style={{ flex: 1 }}>
              <Text style={hs.title} numberOfLines={1}>{d.name}</Text>
              <Text style={hs.sub} numberOfLines={1}>{d.specialization ?? 'Doctor'}{d.clinic ? ` · ${d.clinic}` : ''}</Text>
              <View style={[hs.row, { gap: 4, marginTop: 4 }]}>
                <Feather name="star" size={12} color={pa.beacon} />
                <Text style={hs.sub}>{Number(d.rating_avg).toFixed(1)} ({d.rating_count}) · R {Math.round(d.consultation_fee)}</Text>
              </View>
            </View>
            <Feather name="heart" size={20} color={pa.signal} />
          </View>
          <View style={hs.actions}>
            <ActionChip icon="calendar" label="Book" tone="ink" onPress={() => navigation.navigate('Care', { screen: 'DoctorDetail', params: { doctorId: d.id } })} />
            <ActionChip icon="x" label="Remove" tone="danger" onPress={() => remove(d)} />
          </View>
        </View>
      )}
    />
  );
}
