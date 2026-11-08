{{-- Patient /dashboard: bold "My active requests" hero, refreshed every 15 s from /dashboard/live.
     Included with one line from dashboard/index.blade.php. Finished/terminal items never appear here. --}}
@php
    try {
        $paDashHero = app(\App\Services\Dashboard\DashboardStatsService::class)->forUser(auth()->user())['data'] ?? [];
    } catch (\Throwable $e) {
        report($e);
        $paDashHero = null;
    }
@endphp
@if ($paDashHero !== null)
<link rel="stylesheet" href="{{ asset('assets/css/dashboards.css') }}?v=1">
<section class="pa-dash pa-dash--site" aria-label="My active requests" data-pa-dash-live="{{ route('dashboard.live') }}" data-pa-dash-every="15">
    @include('dashboard.partials.active-hero-body', ['dash' => $paDashHero])
</section>
<script src="{{ asset('assets/js/dashboards.js') }}?v=1" defer></script>
@endif
