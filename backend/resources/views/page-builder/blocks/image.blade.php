@php
    $src = $props['src'] ?? null;
    $url = $src ? \Illuminate\Support\Facades\Storage::disk('public')->url($src) : null;
@endphp
@if ($url)
    @if (! empty($props['link']))
        <a href="{{ $props['link'] }}"><img src="{{ $url }}" alt="{{ $props['alt'] ?? '' }}"></a>
    @else
        <img src="{{ $url }}" alt="{{ $props['alt'] ?? '' }}">
    @endif
@endif
