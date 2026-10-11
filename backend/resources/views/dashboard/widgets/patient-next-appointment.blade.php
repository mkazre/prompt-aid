@php
    $appt = $d['appointment'] ?? null;
    $apptWhen = $appt ? \Illuminate\Support\Carbon::parse($appt['date'].' '.$appt['start_time']) : null;
    $apptIsVideo = $appt && (($appt['mode'] ?? null) === 'video' || ($appt['visit_type'] ?? null) === 'telemed');
@endphp
<div class="paw-card paw-dark">
  <div class="paw-eyebrow-dot">{{ $w->title }}</div>
  @if ($appt)
    <div class="paw-appt">
      <span class="paw-avatar">{{ \App\Support\Widgets\WidgetCatalog::initials($appt['doctor']['name'] ?? null) }}</span>
      <div style="min-width:0">
        <strong>{{ $appt['doctor']['name'] ?? 'Your doctor' }}</strong>
        <div class="paw-muted-dark">{{ $appt['doctor']['specialization'] ?? '' }}</div>
      </div>
    </div>
    <div class="paw-appt-when">
      <span>@include('dashboard.widgets._icon', ['name' => 'calendar', 'size' => 15]) {{ $apptWhen->format('D, d M') }}</span>
      <span>@include('dashboard.widgets._icon', ['name' => 'clock', 'size' => 15]) {{ $apptWhen->format('H:i') }}</span>
      <span>@include('dashboard.widgets._icon', ['name' => $apptIsVideo ? 'video' : 'map-pin', 'size' => 15]) {{ $apptIsVideo ? 'Video consult' : ($appt['clinic']['name'] ?? 'Clinic') }}</span>
    </div>
    <a class="paw-btn-beacon" href="{{ route('account.appointments') }}">View details</a>
  @else
    <p class="paw-muted-dark" style="margin:6px 0 14px">Nothing booked yet.</p>
    <a class="paw-btn-beacon" href="{{ route('doctors.index') }}">Book a consult</a>
  @endif
</div>
