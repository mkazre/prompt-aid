<div class="pa-grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr))">
    @foreach (($props['items'] ?? []) as $item)
        <div class="pa-card-pad">
            <div style="font-size:28px;margin-bottom:12px">{{ $item['icon'] ?? '' }}</div>
            <h4>{{ $item['title'] ?? '' }}</h4>
            @if (! empty($item['description']))
                <p class="pa-muted" style="margin-top:6px">{{ $item['description'] }}</p>
            @endif
        </div>
    @endforeach
</div>
