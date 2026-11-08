{{-- One big-number tile. Params: label, value, sub?, unit?, tone? (red|green|blue|amber|gray), spark? (7 numbers), sparkLabel?, url?, hero?, hot?, delta? (int % vs yesterday) --}}
@php
    $tTone = $tone ?? null;
    $tSpark = isset($spark) && is_array($spark) && count($spark) > 1 ? \App\Services\Dashboard\DashboardStatsService::sparkPoints($spark) : '';
    $tSparkTitle = $tSpark ? (($sparkLabel ?? 'Last 7 days').': '.implode(', ', array_map(fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.'), $spark))) : '';
    $tDelta = $delta ?? null;
    $tTag = ! empty($url) ? 'a' : 'div';
    $tClass = 'pa-dash-tile'.($tTone ? ' is-'.$tTone : '').(! empty($hero) ? ' pa-dash-tile--hero' : '').(! empty($hot) ? ' pa-dash-tile--hot' : '');
@endphp
<{{ $tTag }} class="{{ $tClass }}" @if (! empty($url)) href="{{ $url }}" @endif>
    <span class="pa-dash-tile__label">{{ $label }}</span>
    <span class="pa-dash-tile__value{{ mb_strlen((string) $value) > 5 ? ' is-long' : '' }}">{{ $value }}@if (! empty($unit))<small>{{ $unit }}</small>@endif</span>
    @if (! empty($sub) || $tDelta !== null)
        <span class="pa-dash-tile__sub">
            @if ($tDelta !== null)
                <span class="pa-dash-delta {{ $tDelta > 0 ? 'up' : ($tDelta < 0 ? 'down' : '') }}">{{ $tDelta > 0 ? '▲' : ($tDelta < 0 ? '▼' : '■') }} {{ abs($tDelta) }}%</span>
            @endif
            {{ $sub ?? '' }}
        </span>
    @endif
    @if ($tSpark)
        <svg class="pa-dash-spark" viewBox="0 0 100 28" preserveAspectRatio="none" role="img" aria-label="{{ $tSparkTitle }}"><title>{{ $tSparkTitle }}</title><polyline points="{{ $tSpark }}" /></svg>
    @endif
</{{ $tTag }}>
