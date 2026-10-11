@php $ride = $d['ride'] ?? null; @endphp
@if ($ride)
<div class="paw-card">
  @include('dashboard.widgets._head', ['w' => $w])
  <div class="paw-row">
    <span class="paw-ico-circle" style="background:#1F4E7A1A;color:#1F4E7A">@include('dashboard.widgets._icon', ['name' => 'navigation'])</span>
    <div style="flex:1;min-width:0">
      <strong>{{ str($ride['status'])->headline() }}</strong>
      <div class="paw-muted paw-ellipsis">{{ $ride['pickup_address'] }} → {{ $ride['dropoff_address'] }}</div>
      <div class="paw-muted">
        @if ($ride['scheduled_for']) {{ \Illuminate\Support\Carbon::parse($ride['scheduled_for'])->format('D d M, H:i') }} @endif
        @if ($ride['driver_name']) · {{ $ride['driver_name'] }} @endif
        @if ($ride['eta_minutes']) · ETA {{ $ride['eta_minutes'] }} min @endif
      </div>
    </div>
  </div>
  <a class="paw-btn" href="{{ route('rides.track', $ride['id']) }}">Track live</a>
</div>
@endif
