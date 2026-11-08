{{-- Lab / allied partner board (PartnerBoardWidget). Data: DashboardStatsService::partner() — the partner's own profile only. --}}
@php
    $DS = \App\Services\Dashboard\DashboardStatsService::class;
    $pl = $d['pipeline'] ?? [];
    $done = $d['completed'] ?? ['today' => 0, 'week' => 0, 'month' => 0];
    $earn = $d['earnings'] ?? null;
@endphp
<x-filament-widgets::widget>
<div class="pa-dash" wire:poll.15s>
    <div class="pa-dash-head">
        <div>
            <h1 class="pa-dash-title">{{ $d['company'] ?? 'Partner' }} board</h1>
            <p class="pa-dash-subtitle">New requests near you, active jobs and turnaround. <span class="pa-dash-live"><i></i> Live · {{ $updatedAt }}</span></p>
        </div>
        <div class="pa-dash-links"><a class="pa-dash-btn pa-dash-btn--red" href="{{ url('/partner/lab-requests') }}">Lab requests</a></div>
    </div>

    @if ($dashError)
        <div class="pa-dash-alert is-warning"><span class="ico">!</span><span class="t">{{ $dashError }}</span></div>
    @endif

    @if (empty($d))
        <div class="pa-dash-card"><div class="pa-dash-empty">Your partner profile is not set up yet.</div></div>
    @else
    <div class="pa-dash-grid pa-dash-grid--wide">
        @include('dashboards.partials.tile', ['label' => 'New requests nearby', 'value' => number_format($d['unassigned_nearby'] ?? 0), 'tone' => 'red', 'hero' => true, 'hot' => ($d['unassigned_nearby'] ?? 0) > 0,
            'sub' => ($d['has_location'] ?? false) ? 'Unassigned within '.rtrim(rtrim(number_format($d['radius_km'] ?? 0, 1), '0'), '.').' km' : 'Unassigned (set your location for distance)', 'url' => url('/partner/lab-requests')])
        @include('dashboards.partials.tile', ['label' => 'Alerts for you now', 'value' => number_format($d['offers_now'] ?? 0), 'tone' => 'amber', 'hero' => true, 'hot' => ($d['offers_now'] ?? 0) > 0, 'sub' => 'Accept from the alert pop-up'])
        @include('dashboards.partials.tile', ['label' => 'Active jobs', 'value' => number_format($d['active'] ?? 0), 'tone' => 'blue', 'hero' => true, 'sub' => 'Accepted, collected or processing'])
        @include('dashboards.partials.tile', ['label' => 'Completed today', 'value' => number_format($done['today'] ?? 0), 'tone' => 'green', 'hero' => true, 'spark' => $d['series'] ?? [], 'sub' => ($done['week'] ?? 0).' this week · '.($done['month'] ?? 0).' this month'])
    </div>

    <div class="pa-dash-cols">
        <section class="pa-dash-card" aria-label="Unassigned requests">
            <h2 class="pa-dash-h">Unassigned requests</h2>
            @if (empty($d['unassigned_list']))
                <div class="pa-dash-ok">✓ No unassigned requests near you.</div>
            @else
                <ul class="pa-dash-list">
                    @foreach ($d['unassigned_list'] as $ur)
                        <li>
                            <span class="main"><span class="t">{{ $ur['ref'] }} @if ($ur['priority'] && $ur['priority'] !== 'routine')<span class="pa-dash-pill is-red">{{ $ur['priority'] }}</span>@endif</span><span class="s">{{ $ur['area'] ?? 'No collection address' }}</span></span>
                            <span style="text-align:right"><span class="n" style="font-size:18px">{{ $ur['distance_km'] !== null ? $ur['distance_km'].' km' : '—' }}</span><br><span class="pa-dash-note">waiting {{ $DS::duration($ur['waiting_minutes'] * 60) }}</span></span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
        <section class="pa-dash-card" aria-label="Active pipeline">
            <h2 class="pa-dash-h">Active pipeline</h2>
            <div class="pa-dash-flow" style="grid-auto-flow:row;grid-template-columns:1fr">
                <div><div class="k">Accepted</div><div class="v">{{ $pl['accepted'] ?? 0 }}</div></div>
                <div><div class="k">Sample collected</div><div class="v">{{ $pl['sample_collected'] ?? 0 }}</div></div>
                <div><div class="k">Processing</div><div class="v">{{ $pl['processing'] ?? 0 }}</div></div>
            </div>
        </section>
    </div>

    <section class="pa-dash-section" aria-label="Performance">
        <h2 class="pa-dash-h">Performance</h2>
        <div class="pa-dash-grid">
            @include('dashboards.partials.tile', ['label' => 'Avg turnaround', 'value' => $DS::duration($d['avg_turnaround_seconds'] ?? null), 'tone' => 'blue', 'sub' => 'Accepted → completed, 30 days'])
            @include('dashboards.partials.tile', ['label' => 'Acceptance rate', 'value' => ($d['acceptance_rate'] ?? null) !== null ? $d['acceptance_rate'] : '—', 'unit' => ($d['acceptance_rate'] ?? null) !== null ? '%' : null, 'tone' => 'green', 'sub' => 'Alerts accepted, 30 days'])
            @include('dashboards.partials.tile', ['label' => 'Rating', 'value' => number_format($d['rating']['avg'] ?? 0, 1), 'unit' => '★', 'tone' => 'amber', 'sub' => ($d['rating']['count'] ?? 0).' reviews'])
            @if ($earn)
                @include('dashboards.partials.tile', ['label' => 'Earnings this month', 'value' => $DS::money($earn['month'] ?? 0), 'tone' => 'green', 'sub' => 'Today '.$DS::money($earn['today'] ?? 0)])
            @else
                @include('dashboards.partials.tile', ['label' => 'Earnings', 'value' => '—', 'tone' => 'gray', 'sub' => 'Shown once payouts are enabled'])
            @endif
        </div>
    </section>
    @endif
</div>
</x-filament-widgets::widget>
