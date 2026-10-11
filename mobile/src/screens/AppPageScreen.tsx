import React, { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Linking, Pressable, RefreshControl, ScrollView, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { api, apiErrorMessage } from '../api/client';
import { AppPage } from '../api/types';
import { EmptyNote, SoftCard } from '../components/settings-ui';
import { pa, paFonts } from '../theme';

/**
 * Renders a CMS (page-builder) page natively — "Terms & Conditions",
 * "About us", "Privacy". Which page backs each key is chosen by admins in
 * Staff → Settings (app_terms_page_slug / app_about_page_slug).
 */
export default function AppPageScreen({ route, navigation }: any) {
  const key: string = route.params?.key ?? 'terms';
  const [page, setPage] = useState<AppPage | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const { data } = await api.get(`/app-pages/${key}`);
      setPage(data);
      setError(null);
      navigation.setOptions({ title: data.title });
    } catch (e) {
      setError(apiErrorMessage(e, 'This page could not be loaded.'));
    } finally {
      setLoading(false);
    }
  }, [key, navigation]);

  useEffect(() => {
    load();
  }, [load]);

  if (loading && !page) {
    return (
      <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: pa.paper }}>
        <ActivityIndicator color={pa.signal} />
      </View>
    );
  }

  return (
    <ScrollView style={{ flex: 1, backgroundColor: pa.paper }} contentContainerStyle={{ padding: 16, paddingBottom: 40 }} refreshControl={<RefreshControl refreshing={loading} onRefresh={load} />}>
      {error || !page ? (
        <SoftCard><EmptyNote icon="document-outline" text={error ?? 'Nothing to show yet.'} /></SoftCard>
      ) : (
        <SoftCard>
          <Text style={{ fontFamily: paFonts.black, fontSize: 22, color: pa.ink, marginBottom: 12 }}>{page.title}</Text>
          {page.content.length === 0 ? <EmptyNote text="This page has no text content yet." /> : null}
          {page.content.map((c, i) =>
            c.type === 'heading' ? (
              <Text key={i} style={{ fontFamily: paFonts.black, fontSize: 16, color: pa.ink, marginTop: 14, marginBottom: 6 }}>{c.text}</Text>
            ) : c.type === 'bullet' ? (
              <View key={i} style={{ flexDirection: 'row', gap: 8, marginBottom: 8 }}>
                <Text style={{ color: pa.signal, fontFamily: paFonts.black }}>•</Text>
                <Text style={{ flex: 1, fontFamily: paFonts.regular, fontSize: 14, lineHeight: 21, color: pa.inkSoft }}>
                  {c.title ? <Text style={{ fontFamily: paFonts.bold, color: pa.ink }}>{c.title}{c.text ? ' — ' : ''}</Text> : null}
                  {c.text}
                </Text>
              </View>
            ) : (
              <Text key={i} style={{ fontFamily: paFonts.regular, fontSize: 14, lineHeight: 21, color: pa.inkSoft, marginBottom: 10 }}>{c.text}</Text>
            )
          )}
          <Pressable onPress={() => Linking.openURL(page.url)} style={{ flexDirection: 'row', alignItems: 'center', gap: 6, marginTop: 14 }}>
            <Ionicons name="open-outline" size={16} color={pa.info} />
            <Text style={{ fontFamily: paFonts.bold, fontSize: 13, color: pa.info }}>View on the website</Text>
          </Pressable>
        </SoftCard>
      )}
    </ScrollView>
  );
}
