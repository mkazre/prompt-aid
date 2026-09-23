<div class="pa-grid" style="grid-template-columns:repeat({{ max(1, count($props['items'] ?? [])) }},minmax(0,1fr))">
    @foreach (($props['items'] ?? []) as $stat)
        <div class="pa-stat">
            <div class="k">{{ $stat['label'] ?? '' }}</div>
            <div class="v pa-num">{{ $stat['value'] ?? '' }}</div>
            @if (! empty($stat['description']))
                <div class="d flat">{{ $stat['description'] }}</div>
            @endif
        </div>
    @endforeach
</div>
