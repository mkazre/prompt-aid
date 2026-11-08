{{-- Driver console stats block (included with one line from driver/console.blade.php; refreshed every 15 s
     from /dashboard/live). Independent of the console's offer/swipe JS. --}}
@php
    try {
        $paDrvDash = app(\App\Services\Dashboard\DashboardStatsService::class)->forUser(auth()->user())['data'] ?? [];
    } catch (\Throwable $e) {
        report($e);
        $paDrvDash = null;
    }
@endphp
@if ($paDrvDash !== null)
<link rel="stylesheet" href="{{ asset('assets/css/dashboards.css') }}?v=1">
<section class="pa-dash pa-dash--driver" aria-label="My stats" data-pa-dash-live="{{ route('dashboard.live') }}" data-pa-dash-every="20">
    @include('driver.partials.stats-body', ['dash' => $paDrvDash])
</section>
<script src="{{ asset('assets/js/dashboards.js') }}?v=1" defer></script>
@endif
