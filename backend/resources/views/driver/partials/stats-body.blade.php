{{-- Body of the driver stats block. $dash = DashboardStatsService::driver(). Also returned by GET /dashboard/live. --}}
@php
    $dsSvc = \App\Services\Dashboard\DashboardStatsService::class;
    $dsEarn = $dash['earnings'] ?? [];
    $dsNext = $dash['next_pickup'] ?? null;
    $dsRate = $dash['acceptance_rate'] ?? null;
    $dsOffers = $dash['offers_30d'] ?? ['accepted' => 0, 'declined' => 0, 'missed' => 0];
@endphp
<div class="pa-dash-section-head"><h2 class="pa-dash-h">My stats</h2><span class="pa-dash-note">{{ ($dsEarn['source'] ?? '') === 'ledger' ? 'Earnings after commission' : 'Earnings = completed trip fares' }}</span></div>
@if ($dsNext)
    <div class="pa-dash-next">
        <div><div class="k">Next scheduled pickup · {{ $dsNext['ride_ref'] }}</div><div class="v">{{ $dsNext['at'] }}</div><div class="pa-dash-note" style="color:#fff;opacity:.8">{{ $dsNext['pickup'] }}</div></div>
        <div class="cd" data-pa-countdown="{{ $dsNext['at_iso'] }}">{{ $dsNext['starts_in_seconds'] > 0 ? 'in '.$dsSvc::duration($dsNext['starts_in_seconds']) : 'now' }}</div>
    </div>
@endif
<div class="pa-dash-grid">
    @include('dashboards.partials.tile', ['label' => "Today's trips", 'value' => number_format($dash['trips_today'] ?? 0), 'tone' => 'blue', 'spark' => $dash['series'] ?? [], 'sub' => ($dash['trips_week'] ?? 0).' in 7 days'])
    @include('dashboards.partials.tile', ['label' => 'Earned today', 'value' => $dsSvc::money($dsEarn['today'] ?? 0), 'tone' => 'green'])
    @include('dashboards.partials.tile', ['label' => 'Earned this week', 'value' => $dsSvc::money($dsEarn['week'] ?? 0), 'tone' => 'green', 'sub' => 'Last 7 days'])
    @include('dashboards.partials.tile', ['label' => 'On trips today', 'value' => $dsSvc::duration($dash['on_trip_seconds_today'] ?? 0), 'tone' => 'blue', 'sub' => 'Accepted → drop-off'])
    @include('dashboards.partials.tile', ['label' => 'Acceptance', 'value' => $dsRate !== null ? $dsRate : '—', 'unit' => $dsRate !== null ? '%' : null, 'tone' => 'green', 'sub' => $dsOffers['accepted'].' accepted · '.$dsOffers['missed'].' missed (30 d)'])
    @include('dashboards.partials.tile', ['label' => 'Rating', 'value' => number_format($dash['rating']['avg'] ?? 0, 1), 'unit' => '★', 'tone' => 'amber', 'sub' => ($dash['rating']['count'] ?? 0).' ratings'])
</div>
