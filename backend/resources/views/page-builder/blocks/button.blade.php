@php
    $classes = match ($props['variant'] ?? 'primary') {
        'ghost' => 'pa-btn-ghost',
        'quiet' => 'pa-btn-quiet',
        'beacon' => 'pa-btn-beacon',
        default => 'pa-btn',
    };
@endphp
<a href="{{ $props['url'] ?? '#' }}" class="{{ $classes }}">{{ $props['text'] ?? '' }}</a>
