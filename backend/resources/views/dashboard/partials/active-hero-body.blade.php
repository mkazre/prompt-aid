{{-- Body of the patient "My active requests" hero. $dash = DashboardStatsService::patient(). Also returned by GET /dashboard/live. --}}
@php
    $phItems = $dash['active'] ?? [];
    $phNext = $dash['next_appointment'] ?? null;
    $phStatus = $phNext ? str_replace('_', ' ', $phNext['status']) : null;
    $phDuration = fn ($s) => \App\Services\Dashboard\DashboardStatsService::duration($s);
@endphp
<div class="pa-dash-head">
    <div>
        <h2 class="pa-dash-title">My active requests</h2>
        <p class="pa-dash-subtitle">{{ count($phItems) ? 'Live status of everything in progress.' : 'Nothing in progress right now.' }} <span class="pa-dash-live"><i></i> Live</span></p>
    </div>
    <div class="pa-dash-links">
        @if (($dash['unread_messages'] ?? 0) > 0)<span class="pa-dash-pill is-red">{{ $dash['unread_messages'] }} unread {{ $dash['unread_messages'] === 1 ? 'message' : 'messages' }}</span>@endif
        @if (($dash['labs_in_progress'] ?? 0) > 0)<span class="pa-dash-pill is-blue">{{ $dash['labs_in_progress'] }} lab {{ $dash['labs_in_progress'] === 1 ? 'request' : 'requests' }} in progress</span>@endif
    </div>
</div>

@if (count($phItems) || $phNext)
    <div class="pa-dash-active">
        @foreach ($phItems as $pi)
            <article class="pa-dash-req is-{{ $pi['tone'] }}">
                <span class="ty">{{ $pi['title'] }}</span>
                <span class="st">{{ $pi['status'] }}</span>
                @if ($pi['eta_minutes'] !== null)
                    <span class="eta">ETA {{ $pi['eta_minutes'] }} min</span>
                @elseif (! empty($pi['starts_at']))
                    <span class="eta" data-pa-countdown="{{ $pi['starts_at'] }}"></span>
                @endif
                @if ($pi['detail'])<span class="dt">{{ $pi['detail'] }}</span>@endif
                @if ($pi['url'])<a class="pa-dash-btn {{ $pi['tone'] === 'warning' ? 'pa-dash-btn--red' : ($pi['type'] === 'consult' ? 'pa-dash-btn--green' : '') }}" href="{{ $pi['url'] }}">{{ $pi['action'] }}</a>@endif
            </article>
        @endforeach
        @if ($phNext)
            <article class="pa-dash-req">
                <span class="ty">Next appointment</span>
                <span class="st" data-pa-countdown="{{ $phNext['starts_at'] }}">{{ $phNext['starts_in_seconds'] !== null ? ($phNext['starts_in_seconds'] > 0 ? 'in '.$phDuration($phNext['starts_in_seconds']) : 'now') : $phNext['starts_label'] }}</span>
                <span class="eta">{{ $phNext['starts_label'] }} · {{ $phNext['mode'] }}</span>
                <span class="dt">{{ trim(($phNext['doctor'] ?? '').' · '.($phNext['clinic'] ?? ''), ' ·') }} · <span class="pa-dash-pill {{ $phNext['status'] === 'pending' ? 'is-amber' : 'is-green' }}">{{ $phStatus }}</span></span>
            </article>
        @endif
    </div>
@else
    <div class="pa-dash-calm">You have no rides, consults, orders or appointments in progress. Book care or request a shuttle below whenever you need it.</div>
@endif
