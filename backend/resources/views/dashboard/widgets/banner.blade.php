@php
    $bannerAccent = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) ($s['accent'] ?? '')) ? $s['accent'] : '#1F4E7A';
    $bannerUrl = $s['web_url'] ?? null;
    $bannerImage = $s['image_url'] ?? null;
    $bannerBody = $s['body'] ?? $w->subtitle;
@endphp
<div class="paw-banner" style="--paw-accent: {{ $bannerAccent }}">
  <div class="paw-banner-copy">
    <h3>{{ $w->title }}</h3>
    @if ($bannerBody)<p>{{ $bannerBody }}</p>@endif
    @if ($bannerUrl)
      <a class="paw-banner-btn" href="{{ $bannerUrl }}">{{ $s['cta_label'] ?? 'Learn more' }}</a>
    @endif
  </div>
  @if ($bannerImage)
    <img class="paw-banner-img" src="{{ $bannerImage }}" alt="" loading="lazy">
  @else
    <span class="paw-banner-art">@include('dashboard.widgets._icon', ['name' => 'shield', 'size' => 56])</span>
  @endif
</div>
