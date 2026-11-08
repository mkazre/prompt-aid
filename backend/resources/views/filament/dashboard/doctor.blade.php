{{-- Doctor desk (DoctorDeskWidget). Data: DashboardStatsService::doctor() — the signed-in doctor only. --}}
@php
    $DS = \App\Services\Dashboard\DashboardStatsService::class;
    $av = $d['availability'] ?? 'offline';
    $avOn = $av === 'available';
    $avBusy = $av === 'busy';
    $avClass = $avBusy ? 'is-busy' : ($avOn ? 'is-on' : 'is-off');
    $avBig = $avBusy ? 'Busy — in a consult' : ($avOn ? 'Available now' : 'Offline');
    $avSmall = $avBusy ? 'New see-a-doctor-now requests pause until you finish.' : ($avOn ? 'Patients who need a doctor now can be matched to you. Keep a staff page open for alerts.' : 'You will not receive see-a-doctor-now requests. Booked appointments still show below.');
    $rqT = $d['requests']['today'] ?? [];
    $rqW = $d['requests']['week'] ?? [];
    $earn = $d['earnings'] ?? [];
    $nextUp = $d['next'] ?? null;
    $nextIn = $nextUp ? (int) $nextUp['starts_in_seconds'] : null;
    $nextLabel = $nextUp ? ($nextIn > 0 ? 'in '.$DS::duration($nextIn) : ($nextIn > -60 ? 'now' : 'started '.$DS::duration(-$nextIn).' ago')) : null;
    $liveConsultsUrl = \App\Filament\Pages\LiveConsults::canAccess() ? \App\Filament\Pages\LiveConsults::getUrl(panel: 'admin') : null;
@endphp
<x-filament-widgets::widget>
<div class="pa-dash" wire:poll.15s>
    <div class="pa-dash-head">
        <div>
            <h1 class="pa-dash-title">Good {{ (int) now()->setTimezone($DS::TZ)->format('G') < 12 ? 'morning' : ((int) now()->setTimezone($DS::TZ)->format('G') < 17 ? 'afternoon' : 'evening') }}, {{ $dashUser->name }}</h1>
            <p class="pa-dash-subtitle">Your requests, patients and consults today. <span class="pa-dash-live"><i></i> Live · {{ $updatedAt }}</span></p>
        </div>
        <div class="pa-dash-links">
            @if ($liveConsultsUrl)<a class="pa-dash-btn pa-dash-btn--red" href="{{ $liveConsultsUrl }}">Live consults</a>@endif
            <a class="pa-dash-btn pa-dash-btn--ghost" href="{{ url('/staff/appointments') }}">My appointments</a>
        </div>
    </div>

    @if ($dashError)
        <div class="pa-dash-alert is-warning"><span class="ico">!</span><span class="t">{{ $dashError }}</span></div>
    @endif

    <section class="pa-dash-status {{ $avClass }}" aria-label="Your availability">
        <div>
            <div class="big">{{ $avOn || $avBusy ? '●' : '○' }} {{ $avBig }}</div>
            <div class="small">{{ $avSmall }}</div>
            @if ($availabilityMessage)
                <div class="pa-dash-msg" role="status">{{ $availabilityError ? '⚠ ' : '✓ ' }}{{ $availabilityMessage }}</div>
            @endif
        </div>
        @if ($avOn || $avBusy)
            <button type="button" class="pa-dash-btn" wire:click="setAvailability(false)" wire:loading.attr="disabled" wire:target="setAvailability">Go offline</button>
        @else
            <button type="button" class="pa-dash-btn" wire:click="setAvailability(true)" wire:loading.attr="disabled" wire:target="setAvailability">Go available now</button>
        @endif
    </section>

    <div class="pa-dash-cols">
        <div class="pa-dash-section">
            @if ($nextUp)
                <section class="pa-dash-next" aria-label="Next patient">
                    <div>
                        <div class="k">Next patient · {{ $nextUp['time'] }} · {{ $nextUp['mode'] }}</div>
                        <div class="v">{{ $nextUp['patient'] }}</div>
                    </div>
                    <div class="cd">{{ $nextLabel }}</div>
                    @if ($nextUp['consult_url'])
                        <a class="pa-dash-btn" href="{{ $nextUp['consult_url'] }}">{{ $nextUp['live'] ? 'Open consult' : 'Join consult' }}</a>
                    @endif
                </section>
            @endif

            <section class="pa-dash-card" aria-label="Today's schedule">
                <div class="pa-dash-section-head" style="margin-bottom:8px"><h2 class="pa-dash-h">Today's schedule</h2><span class="pa-dash-note">{{ count($d['schedule'] ?? []) }} booked</span></div>
                @if (empty($d['schedule']))
                    <div class="pa-dash-empty">No appointments today.</div>
                @else
                    <ul class="pa-dash-timeline">
                        @foreach ($d['schedule'] as $sr)
                            @php
                                $srPast = in_array($sr['status'], ['completed', 'no_show'], true);
                                $srNow = $sr['live'] || ($nextUp && $nextUp['id'] === $sr['id']);
                                $srTone = match ($sr['status']) { 'completed' => 'is-green', 'no_show' => 'is-red', 'in consult', 'checked_in' => 'is-amber', 'pending' => 'is-blue', default => '' };
                            @endphp
                            <li class="{{ $srPast ? 'is-past' : '' }} {{ $srNow ? 'is-now' : '' }}">
                                <span class="tm">{{ $sr['time'] ?? '—' }}</span>
                                <span class="main" style="min-width:0"><strong>{{ $sr['patient'] }}</strong><br><span class="pa-dash-note">{{ ucfirst($sr['mode']) }}</span> <span class="pa-dash-pill {{ $srTone }}">{{ str_replace('_', ' ', $sr['status']) }}</span></span>
                                <span>
                                    @if ($sr['consult_url'])
                                        <a class="pa-dash-btn pa-dash-btn--sm {{ $sr['live'] ? 'pa-dash-btn--green' : '' }}" href="{{ $sr['consult_url'] }}">{{ $sr['live'] ? 'Open consult' : 'Join' }}</a>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>

        <div class="pa-dash-section">
            <section class="pa-dash-card {{ ! empty($d['incoming']) ? 'pa-dash-tile--hot' : '' }}" aria-label="Incoming requests">
                <div class="pa-dash-section-head" style="margin-bottom:8px"><h2 class="pa-dash-h">Incoming now</h2><span class="pa-dash-pill {{ ! empty($d['incoming']) ? 'is-red' : '' }}">{{ count($d['incoming'] ?? []) }} waiting</span></div>
                @if (empty($d['incoming']))
                    <div class="pa-dash-empty">No requests waiting for you{{ $avOn ? '' : ' — go available to receive doctor-now requests' }}.</div>
                @else
                    <ul class="pa-dash-list">
                        @foreach ($d['incoming'] as $ir)
                            <li><span class="main"><span class="t">{{ $ir['service'] }}</span><span class="s">{{ $ir['detail'] }}</span></span><span class="pa-dash-pill is-amber">{{ $DS::duration($ir['expires_in']) }} left</span></li>
                        @endforeach
                    </ul>
                    @if ($liveConsultsUrl)<a class="pa-dash-btn pa-dash-btn--red" style="margin-top:10px" href="{{ $liveConsultsUrl }}">Respond now</a>@endif
                @endif
                @if (($d['pending_confirmation'] ?? 0) > 0)
                    <a class="pa-dash-alert is-info" style="margin-top:10px" href="{{ url('/staff/appointments') }}"><span class="ico">i</span><span class="t">Bookings to confirm</span><span class="n">{{ $d['pending_confirmation'] }}</span></a>
                @endif
            </section>
            <div class="pa-dash-grid" style="grid-template-columns:repeat(2,minmax(0,1fr))">
                @include('dashboards.partials.tile', ['label' => 'Rating', 'value' => number_format($d['rating']['avg'] ?? 0, 1), 'unit' => '★', 'tone' => 'amber', 'sub' => ($d['rating']['count'] ?? 0).' reviews'])
                @include('dashboards.partials.tile', ['label' => 'Unread messages', 'value' => number_format($d['unread_messages'] ?? 0), 'tone' => ($d['unread_messages'] ?? 0) ? 'red' : 'gray', 'url' => url('/staff/conversations')])
            </div>
        </div>
    </div>

    <section class="pa-dash-section" aria-label="Request statistics">
        <div class="pa-dash-section-head"><h2 class="pa-dash-h">Your requests</h2><span class="pa-dash-note">Doctor-now offers + booking / doctor-later alerts</span></div>
        <div class="pa-dash-grid">
            @include('dashboards.partials.tile', ['label' => 'Received today', 'value' => number_format($rqT['received'] ?? 0), 'tone' => 'blue', 'sub' => ($rqW['received'] ?? 0).' in 7 days'])
            @include('dashboards.partials.tile', ['label' => 'Accepted', 'value' => number_format($rqT['accepted'] ?? 0), 'tone' => 'green', 'sub' => ($rqW['accepted'] ?? 0).' in 7 days'])
            @include('dashboards.partials.tile', ['label' => 'Declined', 'value' => number_format($rqT['declined'] ?? 0), 'tone' => 'gray', 'sub' => ($rqW['declined'] ?? 0).' in 7 days'])
            @include('dashboards.partials.tile', ['label' => 'Missed', 'value' => number_format($rqT['missed'] ?? 0), 'tone' => ($rqT['missed'] ?? 0) ? 'red' : 'gray', 'sub' => ($rqW['missed'] ?? 0).' in 7 days (expired)'])
            @include('dashboards.partials.tile', ['label' => 'Acceptance rate', 'value' => ($rqW['acceptance_rate'] ?? null) !== null ? $rqW['acceptance_rate'] : '—', 'unit' => ($rqW['acceptance_rate'] ?? null) !== null ? '%' : null, 'tone' => 'green', 'sub' => 'Last 7 days'])
            @include('dashboards.partials.tile', ['label' => 'Avg response', 'value' => $DS::duration($rqW['avg_response_seconds'] ?? null), 'tone' => 'blue', 'sub' => 'Alert → accept/decline, 7 days'])
        </div>
    </section>

    <section class="pa-dash-section" aria-label="Consults and earnings">
        <h2 class="pa-dash-h">Consults &amp; earnings</h2>
        <div class="pa-dash-grid pa-dash-grid--wide">
            @include('dashboards.partials.tile', ['label' => 'Consults completed today', 'value' => number_format($d['consults_completed']['today'] ?? 0), 'tone' => 'green', 'hero' => true, 'spark' => $d['series'] ?? [], 'sub' => ($d['consults_completed']['month'] ?? 0).' this month'])
            @include('dashboards.partials.tile', ['label' => ($earn['source'] ?? '') === 'ledger' ? 'Earnings today' : 'Paid today', 'value' => $DS::money($earn['today'] ?? 0), 'tone' => 'green', 'hero' => true, 'sub' => ($earn['source'] ?? '') === 'ledger' ? 'Net of commission' : 'Invoices paid for your consults'])
            @include('dashboards.partials.tile', ['label' => ($earn['source'] ?? '') === 'ledger' ? 'Earnings this month' : 'Paid this month', 'value' => $DS::money($earn['month'] ?? 0), 'tone' => 'green', 'hero' => true, 'sub' => now()->setTimezone($DS::TZ)->format('F Y')])
        </div>
    </section>
</div>
</x-filament-widgets::widget>
