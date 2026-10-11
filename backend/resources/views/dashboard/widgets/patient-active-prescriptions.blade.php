@php
    $rxItems = $d['items'] ?? [];
    $rxPending = $d['pending_uploads'] ?? 0;
@endphp
<div class="paw-card">
  @include('dashboard.widgets._head', ['w' => $w, 'link' => route('account.prescriptions')])
  @forelse ($rxItems as $rx)
    <div class="paw-row">
      <span class="paw-ico-circle" style="background:#D0211C1A;color:#D0211C">@include('dashboard.widgets._icon', ['name' => 'package', 'size' => 18])</span>
      <span style="flex:1;min-width:0">
        <strong class="paw-ellipsis">{{ $rx['medicine_name'] }}</strong>
        <span class="paw-muted paw-ellipsis">{{ collect([$rx['dosage'], $rx['frequency'], $rx['duration_days'] ? $rx['duration_days'].' days' : null])->filter()->implode(' · ') }}</span>
      </span>
    </div>
  @empty
    <p class="paw-empty">No prescriptions in the last {{ $s['days'] ?? 30 }} days.</p>
  @endforelse
  @if ($rxPending > 0)
    <div class="paw-note">{{ $rxPending }} uploaded script{{ $rxPending === 1 ? '' : 's' }} waiting for pharmacist review.</div>
  @endif
</div>
