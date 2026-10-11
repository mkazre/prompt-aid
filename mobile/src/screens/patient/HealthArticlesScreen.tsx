import React from 'react';
import { ScrollView } from 'react-native';
import { pa } from '../../theme';
import { ArticleList } from '../../widgets/HealthArticleReader';

/**
 * Stack-screen version of the health article list. The home widget opens
 * articles in a modal (so it needs no route), but this can be registered in
 * a patient stack as `HealthArticles` → navigates to `HealthArticleDetail`.
 */
export default function HealthArticlesScreen({ navigation }: any) {
  return (
    <ScrollView style={{ flex: 1, backgroundColor: pa.paper }} contentContainerStyle={{ padding: 16, paddingBottom: 40 }}>
      <ArticleList onOpen={(slug) => navigation.navigate('HealthArticleDetail', { slug })} />
    </ScrollView>
  );
}
