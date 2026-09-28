<x-layout title="Shuttle trips · Prompt Aid">
<div class="pa-account">
<x-account-nav active="rides" />
<div>
  <div class="pa-spread" style="margin-bottom:22px">
    <div><h1 style="font-size:32px">Shuttle trips</h1><p class="pa-muted" style="margin:6px 0 0">One-off trips and return legs.</p></div>
    <div style="display:flex;gap:10px;flex-wrap:wrap"><a class="pa-btn" href="{{ route('shuttle') }}">Request a trip</a></div>
  </div>

  @php
    $completedRides = $rides->where('status', \App\Models\Ride::STATUS_COMPLETED);
    $activeRidesCount = $rides->filter->isActive()->count();
  @endphp
  <div class="pa-grid" style="grid-template-columns:repeat(auto-fit,minmax(190px,1fr));margin-bottom:24px">
    <div class="pa-stat"><div class="k">Trips this year</div><div class="v">{{ $rides->count() }}</div><div class="d flat">R{{ number_format($completedRides->sum('fare_final'), 0) }} spent</div></div>
    <div class="pa-stat"><div class="k">Active now</div><div class="v">{{ $activeRidesCount }}</div><div class="d {{ $activeRidesCount ? 'wait' : 'go' }}">{{ $activeRidesCount ? 'In progress' : 'None active' }}</div></div>
    <div class="pa-stat"><div class="k">Completed</div><div class="v">{{ $completedRides->count() }}</div><div class="d flat">All time</div></div>
  </div>

  <div class="pa-tablewrap"><table class="pa-table">
    <thead><tr><th>Trip</th><th>Route</th><th>Vehicle</th><th>Status</th><th>Fare</th><th></th></tr></thead>
    <tbody>
      @forelse ($rides as $ride)
        <tr>
          <td><div class="t-main">{{ $ride->ride_ref }}</div><div class="t-sub">{{ $ride->requested_at?->format('d M Y H:i') }}</div></td>
          <td>{{ $ride->pickup_address }} → {{ $ride->dropoff_address }}</td>
          <td>{{ str($ride->vehicle_type)->headline() }}</td>
          <td><span class="pa-badge {{ $ride->status === 'completed' ? 'is-go' : ($ride->status === 'cancelled' ? 'is-stop' : 'is-wait') }}">{{ str($ride->status)->headline() }}</span></td>
          <td>{{ $ride->fare_final ? 'R'.number_format($ride->fare_final, 2) : ($ride->fare_estimate ? 'est. R'.number_format($ride->fare_estimate, 2) : '—') }}</td>
          <td class="t-right">
            @if ($ride->isActive())
              <a class="pa-btn-ghost pa-btn-sm" href="{{ route('rides.track', $ride) }}">Track</a>
            @endif
          </td>
        </tr>
      @empty
        <tr><td colspan="6"><p class="pa-muted" style="padding:24px;text-align:center;font-size:14px">No shuttle trips yet. <a href="{{ route('shuttle') }}">Request one</a>.</p></td></tr>
      @endforelse
    </tbody>
  </table></div>
</div>
</div>
</x-layout>
