@php
    $palette = [
        'danger' => ['bg' => '#FDECEC', 'border' => '#E8A3A3', 'fg' => '#8A1414', 'btn' => '#C8102E'],
        'warning' => ['bg' => '#FFF6E0', 'border' => '#EBCB7A', 'fg' => '#6B4A00', 'btn' => '#B07900'],
        'info' => ['bg' => '#EAF3FB', 'border' => '#A9C9E6', 'fg' => '#123B5E', 'btn' => '#1D5E99'],
    ];
    $c = $palette[$b['tone']] ?? $palette['info'];
@endphp
<div role="status" style="margin:0 0 16px;padding:12px 16px;border-radius:12px;border:1px solid {{ $c['border'] }};background:{{ $c['bg'] }};color:{{ $c['fg'] }};display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between">
    <div style="min-width:220px;flex:1">
        <div style="font-weight:700;font-size:14px">{{ $b['title'] }}</div>
        <div style="font-size:13px;margin-top:2px;opacity:.9">{{ $b['body'] }}</div>
    </div>
    @if (! empty($b['action']) && ! empty($b['url']))
        <a href="{{ $b['url'] }}" style="display:inline-block;padding:8px 14px;border-radius:8px;background:{{ $c['btn'] }};color:#fff;font-weight:700;font-size:13px;text-decoration:none;white-space:nowrap">{{ $b['action'] }}</a>
    @endif
</div>
