<x-layout :title="$article->title.' · Prompt Aid'">
<div class="pa-pagehead"><div class="inner">
  <div class="pa-crumb"><a href="{{ url('/') }}">Home</a> <span style="color:#CFC8B8">/</span> <a href="{{ route('health-articles.index') }}">Health articles</a></div>
  @if ($article->category)<div class="pa-eyebrow" style="margin-top:10px">{{ $article->category }}</div>@endif
  <h1>{{ $article->title }}</h1>
  <p class="pa-muted" style="font-size:14px;margin:6px 0 24px">{{ $article->read_minutes }} min read @if ($article->published_at) · {{ $article->published_at->format('d M Y') }} @endif</p>
</div></div>
<div class="pa-container" style="padding-top:32px;padding-bottom:80px">
  <article class="paw-article">
    @if ($article->imageUrl())
      <img src="{{ $article->imageUrl() }}" alt="" class="paw-article-img">
    @endif
    @if ($article->excerpt)<p class="paw-article-lead">{{ $article->excerpt }}</p>@endif
    @foreach ($paragraphs as $para)
      <p>{!! nl2br(e($para)) !!}</p>
    @endforeach
    <p class="pa-muted" style="font-size:13px;margin-top:28px">This article is general information, not a diagnosis. If you are worried about your health, book a consult or use emergency triage.</p>
  </article>

  @if ($more->isNotEmpty())
    <h3 class="pa-sectiontitle" style="margin-top:48px">More articles</h3>
    <div class="paw-articles-grid">
      @foreach ($more as $m)
        @include('dashboard.widgets._article-card', ['article' => $m->toSummaryArray()])
      @endforeach
    </div>
  @endif
</div>
</x-layout>
