<x-layout :title="($topic?->title ?? \App\Support\SiteContent::get('home.topic_heading')).' · Prompt Aid'">
<div class="pa-pagehead"><div class="inner">
  <div class="pa-crumb"><a href="{{ url('/') }}">Home</a> <span style="color:#CFC8B8">/</span> {{ \App\Support\SiteContent::get('home.topic_heading') }}</div>
  <div class="pa-row" style="margin-bottom:12px"><span class="pa-tick"></span><span class="pa-eyebrow">{{ \App\Support\SiteContent::get('home.topic_heading') }}@if($topic) · {{ $topic->month->format('F Y') }}@endif</span></div>
  <h1 style="max-width:900px">{{ $topic?->title ?? 'Coming soon' }}</h1>
  @if ($topic?->summary)<p class="pa-muted" style="font-size:17px;margin:10px 0 24px;max-width:760px">{{ $topic->summary }}</p>@endif
</div></div>

<div class="pa-container pa-topic-grid" style="padding-top:32px;padding-bottom:80px">
  <article>
    @if ($topic)
      @if ($topic->image)<img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($topic->image) }}" alt="" style="width:100%;max-height:420px;object-fit:cover;margin-bottom:28px;border:1px solid var(--pa-line)">@endif
      <div class="pa-card pa-card-pad pa-prose">{{-- Admin-authored rich text (Health topics resource is staff-only). --}}{!! $topic->body !!}</div>
      @if ($topic->attachment)
        <a class="pa-btn-ghost" style="margin-top:18px" href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($topic->attachment) }}" target="_blank" rel="noopener">Download the full article</a>
      @endif
    @else
      <div class="pa-card pa-card-pad"><p class="pa-muted" style="margin:0">This month's topic is being prepared. Check back soon.</p></div>
    @endif

    @if ($testimonials->isNotEmpty())
      <h2 style="margin:40px 0 16px">Patient experiences</h2>
      <div class="pa-grid" style="grid-template-columns:repeat(auto-fit,minmax(260px,1fr))">
        @foreach ($testimonials as $t)
          <div style="padding:22px"><p style="font-size:14px;color:var(--pa-ink-soft);margin:0 0 10px">“{{ $t->body }}”</p><div style="font-size:13px;font-weight:700">{{ $t->name }}</div></div>
        @endforeach
      </div>
    @endif
  </article>
  <aside>
    <div class="pa-pop" style="padding:24px;margin-bottom:20px">
      <h3 style="margin-bottom:8px">Share your experience</h3>
      <p class="pa-muted" style="font-size:14px;margin-bottom:16px">Living with this condition, or caring for someone who is? Type your story or upload a document.</p>
      <a class="pa-btn pa-btn-block" href="{{ route('testimonials.create', $topic ? ['topic' => $topic->id] : []) }}">{{ \App\Support\SiteContent::get('home.topic_testimonial_label') }}</a>
    </div>
    @if ($pastTopics->isNotEmpty())
      <div class="pa-card">
        <div class="pa-card-head"><h3>Past topics</h3></div>
        @foreach ($pastTopics as $p)
          <a href="{{ route('topic.view', $p) }}" style="display:block;padding:14px 22px;border-bottom:1px solid var(--pa-line-soft);color:inherit;text-decoration:none"><div style="font-size:12px;color:var(--pa-muted)">{{ $p->month->format('F Y') }}</div><div style="font-weight:700;font-size:14px">{{ $p->title }}</div></a>
        @endforeach
      </div>
    @endif
  </aside>
</div>
</x-layout>
