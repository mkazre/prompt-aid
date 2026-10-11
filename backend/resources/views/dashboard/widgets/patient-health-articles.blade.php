@php $articles = $d['items'] ?? []; @endphp
@if (count($articles))
<div>
  <div class="paw-head paw-head-bare">
    <h3 class="paw-section-title" style="margin:0">{{ $w->title }}</h3>
    <a class="paw-link" href="{{ route('health-articles.index') }}">See all</a>
  </div>
  <div class="paw-articles-grid">
    @foreach ($articles as $article)
      @include('dashboard.widgets._article-card', ['article' => $article])
    @endforeach
  </div>
</div>
@endif
