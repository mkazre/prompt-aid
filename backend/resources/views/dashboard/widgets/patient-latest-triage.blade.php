@php
    $triage = $d['triage'] ?? null;
    $triageNames = ['red' => 'Emergency', 'orange' => 'Very urgent', 'yellow' => 'Urgent', 'green' => 'Routine'];
@endphp
@if ($triage)
<a class="paw-card paw-triage paw-triage-{{ $triage['level'] }}" href="{{ route('emergency') }}">
  <span class="paw-triage-dot"></span>
  <span style="flex:1;min-width:0">
    <span class="paw-eyebrow">{{ $w->title }}</span>
    <strong>{{ $triageNames[$triage['level']] ?? ucfirst($triage['level']) }}</strong>
    <span class="paw-muted">Ref {{ $triage['reference'] }} · {{ \Illuminate\Support\Carbon::parse($triage['created_at'])->diffForHumans() }}</span>
  </span>
  @include('dashboard.widgets._icon', ['name' => 'chevron-right'])
</a>
@endif
