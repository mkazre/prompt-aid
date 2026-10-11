<a class="paw-article-card" href="{{ route('health-articles.show', $article['slug']) }}">
  @if (! empty($article['image']))
    <img src="{{ $article['image'] }}" alt="" loading="lazy">
  @else
    <span class="paw-article-ph">@include('dashboard.widgets._icon', ['name' => 'book-open', 'size' => 26])</span>
  @endif
  <span class="paw-article-body">
    @if (! empty($article['category']))<span class="paw-chip">{{ $article['category'] }}</span>@endif
    <strong>{{ $article['title'] }}</strong>
    @if (! empty($article['excerpt']))<span class="paw-muted paw-clamp2">{{ $article['excerpt'] }}</span>@endif
    <span class="paw-meta">@include('dashboard.widgets._icon', ['name' => 'clock', 'size' => 13]) {{ $article['read_minutes'] }} min read</span>
  </span>
</a>
