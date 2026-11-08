{{-- Super admin command centre (App\Filament\Dashboard\Widgets\CommandCentreWidget). Data: DashboardStatsService::admin(). --}}
@php
    $DS = \App\Services\Dashboard\DashboardStatsService::class;
    $lv = $d['live'] ?? [];
    $vol = $d['volume'] ?? [];
    $perf = $d['performance'] ?? [];
    $cx = $d['cancellations'] ?? [];
    $rev = $d['revenue'] ?? [];
    $alertRows = $d['alerts'] ?? [];
    $canLiveRequests = \App\Filament\Pages\LiveRequests::canAccess();
    $canLiveDispatch = \App\Filament\Pages\LiveDispatch::canAccess();
    $volLabels = ['shuttle' => 'Shuttle requests', 'doctor_now' => 'Doctor-now requests', 'appointments' => 'Appointments booked', 'orders' => 'Pharmacy orders', 'lab_requests' => 'Lab requests'];
    $revLabels = ['consults' => 'Consults & visits', 'pharmacy' => 'Pharmacy', 'shuttle' => 'Shuttle', 'subscriptions' => 'Subscriptions'];
    $revDelta = ($rev['yesterday'] ?? 0) > 0 ? (int) round((($rev['today'] ?? 0) - $rev['yesterday']) / $rev['yesterday'] * 100) : null;
    $num = fn ($v) => number_format((int) $v);
@endphp
<x-filament-widgets::widget>
<div class="pa-dash" wire:poll.15s>
    <div class="pa-dash-head">
        <div>
            <h1 class="pa-dash-title">Command centre</h1>
            <p class="pa-dash-subtitle">Every live request across Prompt Aid, right now. <span class="pa-dash-live"><i></i> Live · {{ $updatedAt }}</span></p>
        </div>
        <div class="pa-dash-links">
            @if ($canLiveRequests)<a class="pa-dash-btn pa-dash-btn--red" href="{{ \App\Filament\Pages\LiveRequests::getUrl(panel: 'admin') }}">Live requests</a>@endif
            @if ($canLiveDispatch)<a class="pa-dash-btn" href="{{ \App\Filament\Pages\LiveDispatch::getUrl(panel: 'admin') }}">Live dispatch</a>@endif
        </div>
    </div>

    @if ($dashError)
        <div class="pa-dash-alert is-warning"><span class="ico">!</span><span class="t">{{ $dashError }}</span></div>
    @endif

    <section class="pa-dash-section" aria-label="Live now">
        <div class="pa-dash-section-head"><h2 class="pa-dash-h">Live now</h2><span class="pa-dash-note">Refreshes every 15 seconds</span></div>
        <div class="pa-dash-grid pa-dash-grid--wide">
            @include('dashboards.partials.tile', ['label' => 'Open shuttle requests', 'value' => $num($lv['shuttle_open'] ?? 0), 'tone' => 'red', 'hero' => true, 'hot' => ($lv['shuttle_open'] ?? 0) > 0,
                'sub' => ($lv['shuttle_awaiting_payment'] ?? 0).' awaiting payment · '.($lv['shuttle_no_drivers'] ?? 0).' no driver', 'url' => $canLiveRequests ? \App\Filament\Pages\LiveRequests::getUrl(panel: 'admin') : null])
            @include('dashboards.partials.tile', ['label' => 'Rides in progress', 'value' => $num($lv['rides_in_progress'] ?? 0), 'tone' => 'blue', 'hero' => true, 'sub' => 'Driver assigned → drop-off', 'url' => $canLiveDispatch ? \App\Filament\Pages\LiveDispatch::getUrl(panel: 'admin') : null])
            @include('dashboards.partials.tile', ['label' => 'Drivers online', 'value' => $num(($lv['drivers_online'] ?? 0) + ($lv['drivers_busy'] ?? 0)), 'tone' => 'green', 'hero' => true, 'sub' => ($lv['drivers_online'] ?? 0).' free · '.($lv['drivers_busy'] ?? 0).' on a trip'])
            @include('dashboards.partials.tile', ['label' => 'Doctors available now', 'value' => $num($lv['doctors_now'] ?? 0), 'tone' => 'green', 'hero' => true, 'sub' => ($lv['doctors_later'] ?? 0).' later today · '.($lv['doctors_tomorrow'] ?? 0).' tomorrow'])
            @include('dashboards.partials.tile', ['label' => 'Doctor-now searching', 'value' => $num($lv['doctor_now_searching'] ?? 0), 'tone' => 'red', 'hero' => true, 'hot' => ($lv['doctor_now_searching'] ?? 0) > 0, 'sub' => 'Patients waiting for a match', 'url' => $canLiveRequests ? \App\Filament\Pages\LiveRequests::getUrl(panel: 'admin') : null])
            @include('dashboards.partials.tile', ['label' => 'Consults in progress', 'value' => $num($lv['consults_live'] ?? 0), 'tone' => 'amber', 'hero' => true, 'sub' => ($lv['orange_consults_live'] ?? 0).' orange-triage consults connected'])
            @include('dashboards.partials.tile', ['label' => 'Pharmacy orders awaiting', 'value' => $num($lv['orders_awaiting'] ?? 0), 'tone' => 'blue', 'hero' => true, 'hot' => ($lv['orders_awaiting'] ?? 0) > 0, 'sub' => ($lv['scripts_awaiting'] ?? 0).' scripts to review', 'url' => url('/staff/orders')])
            @include('dashboards.partials.tile', ['label' => 'Lab requests unassigned', 'value' => $num($lv['labs_unassigned'] ?? 0), 'tone' => 'blue', 'hero' => true, 'hot' => ($lv['labs_unassigned'] ?? 0) > 0, 'sub' => 'No partner has accepted yet', 'url' => url('/staff/lab-requests')])
            @include('dashboards.partials.tile', ['label' => 'Payments awaiting', 'value' => $num($lv['payments_awaiting'] ?? 0), 'tone' => 'amber', 'hero' => true, 'sub' => ($lv['payments_held'] ?? 0).' card holds authorised', 'url' => url('/staff/payment-intents')])
        </div>
    </section>

    <section class="pa-dash-section" aria-label="Today versus yesterday">
        <div class="pa-dash-section-head"><h2 class="pa-dash-h">Requests today vs yesterday</h2><span class="pa-dash-note">Sparkline = last 7 days</span></div>
        <div class="pa-dash-grid">
            @foreach ($volLabels as $vk => $vl)
                @php $vrow = $vol[$vk] ?? ['today' => 0, 'yesterday' => 0, 'delta_pct' => null, 'series' => []]; @endphp
                @include('dashboards.partials.tile', ['label' => $vl, 'value' => $num($vrow['today']), 'delta' => $vrow['delta_pct'], 'sub' => 'Yesterday '.$num($vrow['yesterday']), 'spark' => $vrow['series'], 'tone' => 'gray'])
            @endforeach
        </div>
    </section>

    <div class="pa-dash-cols">
        <section class="pa-dash-card" aria-label="Service performance">
            <h2 class="pa-dash-h">Fulfilment &amp; speed by service</h2>
            @if (empty($perf))
                <div class="pa-dash-empty">No requests in the last 7 days.</div>
            @else
                <div class="pa-dash-table-wrap">
                    <table class="pa-dash-table">
                        <thead><tr><th>Service</th><th class="num">Today</th><th class="num">Fulfilled today</th><th>7-day fulfilment</th><th class="num">Median accept</th><th class="num">Failed (7d)</th></tr></thead>
                        <tbody>
                        @foreach ($perf as $prow)
                            @php $wr = $prow['week']['rate']; @endphp
                            <tr>
                                <td><strong>{{ $prow['label'] }}</strong></td>
                                <td class="num">{{ $num($prow['today']['requests']) }}</td>
                                <td class="num">{{ $prow['today']['rate'] !== null ? $prow['today']['rate'].'%' : '—' }}</td>
                                <td>
                                    @if ($wr !== null)
                                        <div style="display:flex;align-items:center;gap:8px"><div class="pa-dash-bar {{ $wr >= 80 ? 'is-green' : ($wr >= 50 ? 'is-amber' : 'is-red') }}" style="flex:1"><i style="width:{{ $wr }}%"></i></div><strong>{{ $wr }}%</strong></div>
                                    @else
                                        <span class="pa-dash-note">No decided requests</span>
                                    @endif
                                </td>
                                <td class="num">{{ $DS::duration($prow['median_accept_seconds']) }}</td>
                                <td class="num">{{ $num($prow['week']['failed']) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="pa-dash-note" style="margin:10px 0 0">Fulfilment = accepted ÷ (accepted + expired/cancelled before acceptance). Median accept = request → provider accepted.</p>
            @endif
        </section>

        <section class="pa-dash-card" aria-label="Alerts">
            <h2 class="pa-dash-h">Needs attention</h2>
            @forelse ($alertRows as $al)
                <a class="pa-dash-alert is-{{ $al['level'] }}" @if ($al['url']) href="{{ $al['url'] }}" @endif>
                    <span class="ico" aria-hidden="true">{{ $al['level'] === 'danger' ? '!' : ($al['level'] === 'warning' ? '▲' : 'i') }}</span>
                    <span class="t">{{ $al['title'] }}@if ($al['hint'])<span>{{ $al['hint'] }}</span>@endif</span>
                    <span class="n">{{ $num($al['count']) }}</span>
                </a>
            @empty
                <div class="pa-dash-ok">✓ Nothing needs attention right now.</div>
            @endforelse
        </section>
    </div>

    <section class="pa-dash-section" aria-label="Revenue">
        <div class="pa-dash-section-head"><h2 class="pa-dash-h">Revenue</h2><span class="pa-dash-note">Collected payments · invoices, paid orders, shuttle charges, subscriptions</span></div>
        <div class="pa-dash-grid pa-dash-grid--wide">
            @include('dashboards.partials.tile', ['label' => 'Collected today', 'value' => $DS::money($rev['today'] ?? 0), 'delta' => $revDelta, 'sub' => 'Yesterday '.$DS::money($rev['yesterday'] ?? 0), 'spark' => $rev['series'] ?? [], 'tone' => 'green', 'hero' => true])
            @include('dashboards.partials.tile', ['label' => 'This month', 'value' => $DS::money($rev['month'] ?? 0), 'tone' => 'green', 'hero' => true, 'sub' => now()->setTimezone($DS::TZ)->format('F Y')])
            @if (! empty($rev['ledger']))
                @include('dashboards.partials.tile', ['label' => 'Platform earnings (month)', 'value' => $DS::money($rev['ledger']['month'] ?? 0), 'tone' => 'blue', 'hero' => true, 'sub' => 'Today '.$DS::money($rev['ledger']['today'] ?? 0).' · finance ledger'])
            @endif
        </div>
        <div class="pa-dash-grid">
            @foreach ($revLabels as $rk => $rl)
                @include('dashboards.partials.tile', ['label' => $rl, 'value' => $DS::money($rev['by_service'][$rk]['month'] ?? 0), 'sub' => 'Today '.$DS::money($rev['by_service'][$rk]['today'] ?? 0), 'tone' => 'gray'])
            @endforeach
        </div>
    </section>

    <section class="pa-dash-section" aria-label="Cancellations and expiries">
        <div class="pa-dash-section-head"><h2 class="pa-dash-h">Cancellations &amp; expiries today</h2><span class="pa-dash-note">{{ $num($cx['total'] ?? 0) }} in total</span></div>
        <div class="pa-dash-grid">
            @include('dashboards.partials.tile', ['label' => 'Rides cancelled', 'value' => $num($cx['rides_cancelled'] ?? 0), 'tone' => 'gray', 'sub' => 'By patient, driver or admin'])
            @include('dashboards.partials.tile', ['label' => 'Rides expired', 'value' => $num($cx['rides_expired'] ?? 0), 'tone' => ($cx['rides_expired'] ?? 0) ? 'red' : 'gray', 'sub' => 'Closed by the system'])
            @include('dashboards.partials.tile', ['label' => 'Doctor-now expired', 'value' => $num($cx['doctor_now_expired'] ?? 0), 'tone' => ($cx['doctor_now_expired'] ?? 0) ? 'red' : 'gray', 'sub' => ($cx['doctor_now_cancelled'] ?? 0).' cancelled by patient'])
            @include('dashboards.partials.tile', ['label' => 'Dispatch jobs expired', 'value' => $num($cx['jobs_expired'] ?? 0), 'tone' => ($cx['jobs_expired'] ?? 0) ? 'red' : 'gray', 'sub' => 'Orders, scripts, labs, bookings'])
            @include('dashboards.partials.tile', ['label' => 'Orders cancelled', 'value' => $num($cx['orders_cancelled'] ?? 0), 'tone' => 'gray'])
            @include('dashboards.partials.tile', ['label' => 'Appointments cancelled', 'value' => $num($cx['appointments_cancelled'] ?? 0), 'tone' => 'gray'])
        </div>
    </section>
</div>
</x-filament-widgets::widget>
