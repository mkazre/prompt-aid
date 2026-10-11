@php
    $bannerAccent = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) ($s['accent'] ?? '')) ? $s['accent'] : '#1F4E7A';
    $bannerUrl = $s['web_url'] ?? null;
    $bannerImage = $s['image_url'] ?? null;
    $bannerBody = $s['body'] ?? $w->subtitle;
    $bannerStyle = $s['style'] ?? 'solid';
    $bannerBg = match ($bannerStyle) {
        'gradient' => "background: linear-gradient(135deg, {$bannerAccent} 0%, {$bannerAccent}B3 60%, #101012 140%)",
        'image' => $bannerImage ? "background: linear-gradient(90deg, rgba(16,16,18,.78), rgba(16,16,18,.25)), url('".e($bannerImage)."') center/cover" : '',
        default => '',
    };
@endphp
<div class="paw-banner" style="--paw-accent: {{ $bannerAccent }}; {{ $bannerBg }}">
  <div class="paw-banner-copy">
    <h3>{{ $w->title }}</h3>
    @if ($bannerBody)<p>{{ $bannerBody }}</p>@endif
    @if ($bannerUrl)
      <a class="paw-banner-btn" href="{{ $bannerUrl }}">{{ $s['cta_label'] ?? 'Learn more' }}</a>
    @endif
  </div>
  @if ($bannerImage && $bannerStyle !== 'image')
    <img class="paw-banner-img" src="{{ $bannerImage }}" alt="" loading="lazy">
  @elseif ($bannerStyle !== 'image')
    <span class="paw-banner-art">@include('dashboard.widgets._icon', ['name' => $s['icon'] ?? 'shield', 'image' => $s['icon_image_url'] ?? null, 'size' => 56])</span>
  @endif
</div>
