{{-- Clinic admin live board (ClinicBoardWidget). Data: DashboardStatsService::clinic() — scoped to clinics this admin runs. --}}
@php
    $DS = \App\Services\Dashboard\DashboardStatsService::class;
    $byS = $d['by_status'] ?? [];
    $rv = $d['revenue'] ?? ['today' => 0, 'yesterday' => 0, 'month' => 0];
    $rvDelta = ($rv['yesterday'] ?? 0) > 0 ? (int) round(($rv['today'] - $rv['yesterday']) / $rv['yesterday'] * 100) : null;
    $statusOrder = ['pending' => 'Pending', 'confirmed' => 'Confirmed', 'checked_in' => 'Checked in', 'completed' => 'Completed', 'no_show' => 'No-show', 'cancelled' => 'Cancelled', 'matching' => 'Matching'];
    $util = $d['utilisation'] ?? [];
@endphp
<x-filament-widgets::widget>
<div class="pa-dash" wire:poll.15s>
    <div class="pa-dash-head">
        <div>
            <h1 class="pa-dash-title">Clinic live board</h1>
            <p class="pa-dash-subtitle">{{ ($d['clinics'] ?? 0) === 1 ? 'Your clinic' : ($d['clinics'] ?? 0).' clinics' }} today. <span class="pa-dash-live"><i></i> Live · {{ $updatedAt }}</span></p>
        </div>
        <div class="pa-dash-links"><a class="pa-dash-btn" href="{{ url('/staff/appointments') }}">Appointments</a></div>
    </div>

    @if ($dashError)
        <div class="pa-dash-alert is-warning"><span class="ico">!</span><span class="t">{{ $dashError }}</span></div>
    @endif

    @if (($d['clinics'] ?? 0) === 0)
        <div class="pa-dash-card"><div class="pa-dash-empty">No clinic is linked to your account yet. Ask a super admin to set you as a clinic's administrator.</div></div>
    @else
    <section class="pa-dash-section" aria-label="Right now">
        <h2 class="pa-dash-h">Right now</h2>
        <div class="pa-dash-grid pa-dash-grid--wide">
            @include('dashboards.partials.tile', ['label' => 'Appointments today', 'value' => number_format($d['appointments_today'] ?? 0), 'tone' => 'blue', 'hero' => true, 'spark' => $d['series'] ?? [], 'sub' => ($d['upcoming'] ?? 0).' still to come'])
            @include('dashboards.partials.tile', ['label' => 'Doctors online', 'value' => ($d['doctors_online'] ?? 0), 'unit' => '/ '.($d['doctors_total'] ?? 0), 'tone' => 'green', 'hero' => true, 'sub' => 'Available for doctor-now'])
            @include('dashboards.partials.tile', ['label' => 'Consults in progress', 'value' => number_format($d['consults_live'] ?? 0), 'tone' => 'amber', 'hero' => true, 'sub' => 'Video / voice / chat connected'])
            @include('dashboards.partials.tile', ['label' => 'Waiting room', 'value' => number_format($d['waiting'] ?? 0), 'tone' => 'blue', 'hero' => true, 'sub' => 'Checked in, not yet seen'])
            @include('dashboards.partials.tile', ['label' => 'Running late', 'value' => number_format($d['late'] ?? 0), 'tone' => ($d['late'] ?? 0) ? 'red' : 'gray', 'hot' => ($d['late'] ?? 0) > 0, 'hero' => true, 'sub' => 'Start time passed 10+ min ago'])
            @include('dashboards.partials.tile', ['label' => 'No-shows today', 'value' => number_format($d['no_shows'] ?? 0), 'tone' => ($d['no_shows'] ?? 0) ? 'red' : 'gray', 'hero' => true, 'sub' => ($d['pending_confirmation'] ?? 0).' bookings awaiting confirmation'])
        </div>
    </section>

    <div class="pa-dash-cols">
        <section class="pa-dash-card" aria-label="Doctor utilisation">
            <h2 class="pa-dash-h">Doctor utilisation today</h2>
            @if (empty($util))
                <div class="pa-dash-empty">No doctors on your roster yet.</div>
            @else
                <div class="pa-dash-table-wrap">
                    <table class="pa-dash-table">
                        <thead><tr><th>Doctor</th><th>Status</th><th class="num">Booked</th><th class="num">Done</th><th>Utilisation (booked ÷ slots)</th></tr></thead>
                        <tbody>
                        @foreach ($util as $ur)
                            <tr>
                                <td><strong>{{ $ur['doctor'] }}</strong><br><span class="pa-dash-note">{{ $ur['specialization'] }}</span></td>
                                <td><span class="pa-dash-pill {{ $ur['online'] ? 'is-green' : '' }}">{{ $ur['online'] ? '● Online' : '○ Offline' }}</span></td>
                                <td class="num">{{ $ur['booked'] }}</td>
                                <td class="num">{{ $ur['completed'] }}</td>
                                <td>
                                    @if ($ur['utilisation'] !== null)
                                        <div style="display:flex;align-items:center;gap:8px"><div class="pa-dash-bar {{ $ur['utilisation'] >= 85 ? 'is-red' : ($ur['utilisation'] >= 50 ? 'is-green' : 'is-amber') }}" style="flex:1"><i style="width:{{ $ur['utilisation'] }}%"></i></div><strong>{{ $ur['utilisation'] }}%</strong></div>
                                        <span class="pa-dash-note">{{ $ur['slots'] }} slots</span>
                                    @else
                                        <span class="pa-dash-note">No weekly hours set for today</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <div class="pa-dash-section">
            <section class="pa-dash-card" aria-label="Appointments by status">
                <h2 class="pa-dash-h">Today by status</h2>
                <ul class="pa-dash-list">
                    @foreach ($statusOrder as $sk => $sl)
                        @if (($byS[$sk] ?? 0) > 0 || in_array($sk, ['pending', 'confirmed', 'completed'], true))
                            <li><span class="main"><span class="t">{{ $sl }}</span></span><span class="n">{{ $byS[$sk] ?? 0 }}</span></li>
                        @endif
                    @endforeach
                </ul>
            </section>
            <div class="pa-dash-grid" style="grid-template-columns:repeat(auto-fill,minmax(150px,1fr))">
                @include('dashboards.partials.tile', ['label' => 'Revenue today', 'value' => $DS::money($rv['today']), 'delta' => $rvDelta, 'tone' => 'green'])
                @include('dashboards.partials.tile', ['label' => 'This month', 'value' => $DS::money($rv['month']), 'tone' => 'green'])
                @if (! empty($d['earnings']))
                    @include('dashboards.partials.tile', ['label' => 'Clinic earnings (month)', 'value' => $DS::money($d['earnings']['month'] ?? 0), 'tone' => 'blue', 'sub' => 'Net of commission · today '.$DS::money($d['earnings']['today'] ?? 0)])
                @endif
            </div>
        </div>
    </div>
    @endif
</div>
</x-filament-widgets::widget>
