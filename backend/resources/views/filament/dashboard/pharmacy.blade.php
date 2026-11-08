{{-- Pharmacy vendor board (PharmacyBoardWidget). Data: DashboardStatsService::pharmacy() — the vendor's own pharmacies only. --}}
@php
    $DS = \App\Services\Dashboard\DashboardStatsService::class;
    $pl = $d['pipeline'] ?? [];
    $flow = [
        'pending_payment' => 'Awaiting payment',
        'awaiting_prescription_review' => 'Script review',
        'confirmed' => 'To accept',
        'preparing' => 'Preparing',
        'out_for_delivery' => 'Out for delivery',
        'delivered_today' => 'Delivered today',
    ];
    $rv = $d['revenue'] ?? ['today' => 0, 'month' => 0];
    $ordDelta = ($d['orders_yesterday'] ?? 0) > 0 ? (int) round((($d['orders_today'] ?? 0) - $d['orders_yesterday']) / $d['orders_yesterday'] * 100) : null;
@endphp
<x-filament-widgets::widget>
<div class="pa-dash" wire:poll.15s>
    <div class="pa-dash-head">
        <div>
            <h1 class="pa-dash-title">Pharmacy board</h1>
            <p class="pa-dash-subtitle">Orders, scripts and stock for {{ ($d['pharmacies'] ?? 0) === 1 ? 'your pharmacy' : ($d['pharmacies'] ?? 0).' pharmacies' }}. <span class="pa-dash-live"><i></i> Live · {{ $updatedAt }}</span></p>
        </div>
        <div class="pa-dash-links"><a class="pa-dash-btn pa-dash-btn--red" href="{{ url('/vendor/orders') }}">Open orders</a><a class="pa-dash-btn pa-dash-btn--ghost" href="{{ url('/vendor/products') }}">Products</a></div>
    </div>

    @if ($dashError)
        <div class="pa-dash-alert is-warning"><span class="ico">!</span><span class="t">{{ $dashError }}</span></div>
    @endif

    @if (($d['pharmacies'] ?? 0) === 0)
        <div class="pa-dash-card"><div class="pa-dash-empty">No pharmacy is linked to your account yet. Create your pharmacy profile to start receiving orders.</div></div>
    @else
    <div class="pa-dash-grid pa-dash-grid--wide">
        @include('dashboards.partials.tile', ['label' => 'New orders to accept', 'value' => number_format($d['awaiting_acceptance'] ?? 0), 'tone' => 'red', 'hero' => true, 'hot' => ($d['awaiting_acceptance'] ?? 0) > 0, 'sub' => 'Paid — waiting for you', 'url' => url('/vendor/orders')])
        @include('dashboards.partials.tile', ['label' => 'Scripts to review', 'value' => number_format($d['scripts_to_review'] ?? 0), 'tone' => 'amber', 'hero' => true, 'hot' => ($d['scripts_to_review'] ?? 0) > 0, 'sub' => 'Prescriptions uploaded with orders'])
        @include('dashboards.partials.tile', ['label' => 'Orders today', 'value' => number_format($d['orders_today'] ?? 0), 'delta' => $ordDelta, 'tone' => 'blue', 'hero' => true, 'spark' => $d['series'] ?? [], 'sub' => 'Yesterday '.($d['orders_yesterday'] ?? 0)])
        @include('dashboards.partials.tile', ['label' => 'Revenue today', 'value' => $DS::money($rv['today'] ?? 0), 'tone' => 'green', 'hero' => true, 'sub' => 'Month '.$DS::money($rv['month'] ?? 0).(($rv['source'] ?? '') === 'ledger' ? ' · net' : ' · paid orders')])
    </div>

    <section class="pa-dash-card" aria-label="Order pipeline">
        <h2 class="pa-dash-h">Order pipeline</h2>
        <div class="pa-dash-flow">
            @foreach ($flow as $fk => $fl)
                <div><div class="k">{{ $fl }}</div><div class="v">{{ $pl[$fk] ?? 0 }}</div></div>
            @endforeach
        </div>
    </section>

    <div class="pa-dash-cols">
        <section class="pa-dash-card" aria-label="Orders waiting">
            <h2 class="pa-dash-h">Waiting longest</h2>
            @if (empty($d['awaiting']))
                <div class="pa-dash-ok">✓ No paid orders waiting for acceptance.</div>
            @else
                <ul class="pa-dash-list">
                    @foreach ($d['awaiting'] as $ao)
                        <li>
                            <span class="main"><span class="t">{{ $ao['order_no'] }} · {{ $ao['patient'] ?? 'Patient' }}</span><span class="s">{{ $DS::money($ao['total']) }}</span></span>
                            <span style="display:flex;gap:8px;align-items:center"><span class="pa-dash-pill {{ $ao['waiting_minutes'] >= 15 ? 'is-red' : 'is-amber' }}">{{ $DS::duration($ao['waiting_minutes'] * 60) }}</span><a class="pa-dash-btn pa-dash-btn--sm" href="{{ url('/vendor/orders/'.$ao['id'].'/edit') }}">Open</a></span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
        <section class="pa-dash-card" aria-label="Low stock">
            <div class="pa-dash-section-head" style="margin-bottom:8px"><h2 class="pa-dash-h">Low stock</h2><span class="pa-dash-pill {{ ($d['low_stock_count'] ?? 0) ? 'is-red' : 'is-green' }}">{{ $d['low_stock_count'] ?? 0 }} items ≤ {{ $DS::LOW_STOCK }}</span></div>
            @if (empty($d['low_stock']))
                <div class="pa-dash-ok">✓ Stock levels look healthy.</div>
            @else
                <ul class="pa-dash-list">
                    @foreach ($d['low_stock'] as $ls)
                        <li><span class="main"><span class="t">{{ $ls['name'] }}</span></span><span class="n" style="{{ $ls['stock'] <= 0 ? 'color:#a3150f' : '' }}">{{ $ls['stock'] }}</span></li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    <section class="pa-dash-section" aria-label="Performance">
        <div class="pa-dash-section-head"><h2 class="pa-dash-h">Performance (last 30 days)</h2></div>
        <div class="pa-dash-grid">
            @include('dashboards.partials.tile', ['label' => 'Acceptance rate', 'value' => ($d['acceptance_rate'] ?? null) !== null ? $d['acceptance_rate'] : '—', 'unit' => ($d['acceptance_rate'] ?? null) !== null ? '%' : null, 'tone' => 'green', 'sub' => 'Order & script alerts accepted'])
            @include('dashboards.partials.tile', ['label' => 'Avg time to accept', 'value' => $DS::duration($d['avg_accept_seconds'] ?? null), 'tone' => 'blue', 'sub' => 'Paid → accepted'])
            @include('dashboards.partials.tile', ['label' => 'Avg fulfilment', 'value' => $DS::duration($d['avg_fulfilment_seconds'] ?? null), 'tone' => 'blue', 'sub' => 'Order placed → delivered'])
            @include('dashboards.partials.tile', ['label' => 'Revenue this month', 'value' => $DS::money($rv['month'] ?? 0), 'tone' => 'green'])
        </div>
    </section>
    @endif
</div>
</x-filament-widgets::widget>
