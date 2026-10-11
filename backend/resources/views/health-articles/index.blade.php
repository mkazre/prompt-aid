<x-layout title="Health articles · Prompt Aid">
<div class="pa-pagehead"><div class="inner">
  <div class="pa-crumb"><a href="{{ url('/') }}">Home</a> <span style="color:#CFC8B8">/</span> Health articles</div>
  <h1>Health articles</h1>
  <p class="pa-muted" style="font-size:15px;margin:6px 0 24px">Practical, plain-language guidance from the Prompt Aid team.</p>
</div></div>
<div class="pa-container" style="padding-top:32px;padding-bottom:80px">
  <div class="paw-articles-grid">
    @forelse ($articles as $article)
      @include('dashboard.widgets._article-card', ['article' => $article->toSummaryArray()])
    @empty
      <p class="pa-muted">No articles published yet.</p>
    @endforelse
  </div>
  <div style="margin-top:24px">{{ $articles->links() }}</div>
</div>
</x-layout>
