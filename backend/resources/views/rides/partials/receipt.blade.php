{{--
  Trip receipt. Optional slots for later phases (pass any of them via
  $receiptSlots): 'payment_method' (string), 'organisation' (array{name, reference?}),
  'tip' (float). Nothing renders for a slot that isn't provided.
--}}
@php
  $rb = is_array($ride->fare_breakdown) ? $ride->fare_breakdown : [];
  $rf = $rb['final'] ?? null;
  $receiptSlots = $receiptSlots ?? [];
  $fareCharge = $ride->charges->firstWhere('type', \App\Models\RideCharge::TYPE_FARE);
  $paymentMethod = $receiptSlots['payment_method'] ?? ($fareCharge ? ucfirst((string) $fareCharge->method) : null);
  $tip = isset($receiptSlots['tip']) ? (float) $receiptSlots['tip'] : null;
  $org = $receiptSlots['organisation'] ?? null;
  $grand = (float) ($ride->fare_final ?? $ride->quoted_fare ?? $ride->fare_estimate) + ($tip ?? 0);
@endphp
<div class="pa-sh-card" id="pa-sh-receipt">
  <div class="pa-spread" style="margin-bottom:10px">
    <div class="pa-label" style="margin:0">Receipt</div>
    <span class="pa-badge is-go">{{ $ride->ride_ref }}</span>
  </div>
  <div class="pa-sh-sub" style="margin-bottom:10px">{{ $ride->completed_at?->timezone(\App\Support\ShuttleSettings::str('shuttle_pricing_timezone') ?: 'Africa/Johannesburg')->format('D d M Y, H:i') }}</div>

  @if (isset($rb['base_fare']))
    <div class="pa-sh-row"><span class="k">Base fare</span><span>R {{ number_format($rb['base_fare'], 2) }}</span></div>
    <div class="pa-sh-row"><span class="k">Distance ({{ number_format($rb['distance_km'] ?? 0, 1) }} km)</span><span>R {{ number_format($rb['distance_charge'] ?? 0, 2) }}</span></div>
    @if (($rb['time_charge'] ?? 0) > 0)
      <div class="pa-sh-row"><span class="k">Time ({{ $rb['duration_min'] ?? 0 }} min)</span><span>R {{ number_format($rb['time_charge'], 2) }}</span></div>
    @endif
    @if (($rb['dynamic_charge'] ?? 0) > 0)
      <div class="pa-sh-row"><span class="k">Dynamic pricing (×{{ number_format($rb['multiplier'] ?? 1, 2) }})</span><span>R {{ number_format($rb['dynamic_charge'], 2) }}</span></div>
    @endif
    @if (! empty($rb['minimum_applied']))
      <div class="pa-sh-row"><span class="k">Minimum fare applied</span><span>R {{ number_format($rb['minimum_fare'] ?? 0, 2) }}</span></div>
    @endif
    @if (($rb['booking_fee'] ?? 0) > 0)
      <div class="pa-sh-row"><span class="k">Booking fee</span><span>R {{ number_format($rb['booking_fee'], 2) }}</span></div>
    @endif
  @endif

  @if ($rf)
    <div class="pa-sh-row" style="border-top:1px solid var(--pa-line-soft);margin-top:4px;padding-top:8px"><span class="k">Upfront price</span><span>R {{ number_format($rf['upfront_fare'] ?? 0, 2) }}</span></div>
    @if (! empty($rf['route_changed']))
      <div class="pa-sh-row"><span class="k">Route changed ({{ number_format($rf['actual_km'] ?? 0, 1) }} km vs {{ number_format($rf['planned_km'] ?? 0, 1) }} km planned)</span><span>R {{ number_format(($rf['adjusted_trip_fare'] ?? 0) - ($rf['upfront_fare'] ?? 0), 2) }}</span></div>
    @endif
    @if (($rf['waiting_charge'] ?? 0) > 0)
      <div class="pa-sh-row"><span class="k">Waiting ({{ $rf['waiting_minutes_charged'] }} min)</span><span>R {{ number_format($rf['waiting_charge'], 2) }}</span></div>
    @endif
  @endif

  @if ($tip !== null && $tip > 0)
    <div class="pa-sh-row"><span class="k">Tip</span><span>R {{ number_format($tip, 2) }}</span></div>
  @endif

  <div class="pa-sh-row total"><span>Total</span><span>R {{ number_format($grand, 2) }}</span></div>

  @if ($paymentMethod || $fareCharge)
    <div class="pa-sh-row"><span class="k">Payment</span><span>{{ $paymentMethod ?? '—' }}@if ($fareCharge) · {{ ucfirst($fareCharge->status) }}@endif</span></div>
  @endif
  @if (is_array($org) && ! empty($org['name']))
    <div class="pa-sh-row"><span class="k">Billed to</span><span>{{ $org['name'] }}@if (! empty($org['reference'])) · {{ $org['reference'] }}@endif</span></div>
  @endif
  @if ($fareCharge?->gateway_ref)
    <div class="pa-sh-sub">Payment reference {{ $fareCharge->gateway_ref }}</div>
  @endif
  @if ($rf)
    <div class="pa-sh-sub" style="margin-top:8px">{{ $rf['rule'] ?? '' }}</div>
  @endif
</div>
