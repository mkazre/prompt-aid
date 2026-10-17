<x-layout title="Trip recordings · Prompt Aid">
@php
  $recCount = count($summary['recordings'] ?? []);
  $retentionDays = $summary['settings']['retention_days'] ?? 3;
@endphp
<div class="pa-account">
<x-account-nav active="rides" />
<div>
  <div class="pa-spread" style="margin-bottom:22px">
    <div>
      <h1 style="font-size:32px">Trip recordings</h1>
      <p class="pa-muted" style="margin:6px 0 0">{{ $ride->ride_ref }} · {{ $ride->requested_at?->format('d M Y H:i') }} · {{ $ride->pickup_address }} → {{ $ride->dropoff_address }}</p>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <a class="pa-btn-ghost" href="{{ route('account.rides') }}">All trips</a>
      @if ($ride->isActive())<a class="pa-btn" href="{{ route('rides.track', $ride) }}">Track trip</a>@endif
    </div>
  </div>

  <div class="pa-note" style="margin-bottom:16px;font-size:14px">
    Audio recorded by you or your driver on this trip as a safety measure. Both of you can play every recording of the trip; Prompt Aid safety staff can too, and every play is logged.
    Recordings are encrypted and deleted automatically {{ $retentionDays }} day(s) after they end, unless kept for an SOS, complaint or investigation.
    To report a problem, open a <a href="{{ url('/account/support') }}">support ticket</a> (choose "Shuttle") — the recording is then kept while it is looked at.
  </div>

  @if ($recCount === 0)
    <p class="pa-muted" style="padding:24px;text-align:center;font-size:14px">There are no recordings for this trip{{ $ride->isActive() ? ' yet' : '' }}.</p>
  @endif
  @include('safety.trip-recorder', ['ride' => $ride, 'role' => $summary['my_role'] ?? 'patient', 'showList' => true])
</div>
</div>
</x-layout>
