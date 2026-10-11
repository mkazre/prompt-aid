import React from 'react';
import { ScrollView } from 'react-native';
import { pa } from '../../theme';
import { ArticleBody } from '../../widgets/HealthArticleReader';

/** Stack-screen version of one health article (route param: `slug`). */
export default function HealthArticleDetailScreen({ route }: any) {
  const slug: string = route?.params?.slug;
  return (
    <ScrollView style={{ flex: 1, backgroundColor: pa.paper }} contentContainerStyle={{ padding: 16, paddingBottom: 40 }}>
      {slug ? <ArticleBody slug={slug} /> : null}
    </ScrollView>
  );
}
