@php
  $c = $this->call();
  $tz = 'Africa/Johannesburg';
  $t = fn ($v) => $v ? $v->copy()->setTimezone($tz)->format('D j M Y H:i:s') : '—';
  $meta = (array) $c->meta;
  $rows = [
    'Type' => ($c->source === \App\Models\CallLog::SOURCE_PRACTICE ? 'Practice call (orange)' : 'Consult').' · '.$c->kind,
    'Status' => ucfirst($c->status),
    'Outcome' => \App\Models\CallLog::outcomeLabel($c->outcome),
    'Patient' => $c->patientUser?->name ?? '—',
    'Practice' => $c->clinic?->name ?? '—',
    'Doctor' => $c->doctor?->user ? 'Dr. '.$c->doctor->user->name : '—',
    'Handled by' => $c->practiceUser?->name ?? '—',
    'Started by' => trim(($c->started_by_role ?? '—')),
    'Ringing' => $t($c->ringing_at).($c->ring_attempts > 1 ? ' ('.$c->ring_attempts.' rings)' : ''),
    'Practice answered' => $t($c->practice_accepted_at),
    'Patient answered' => $t($c->patient_accepted_at),
    'Connected' => $t($c->connected_at),
    'Ended' => $t($c->ended_at).($c->ended_by_role ? ' · by '.$c->ended_by_role.($c->endedBy ? ' ('.$c->endedBy->name.')' : '') : ''),
    'Declined by' => $c->declined_by ?? '—',
    'Duration' => sprintf('%d:%02d', intdiv((int) $c->duration_seconds, 60), (int) $c->duration_seconds % 60),
    'Reason' => $c->end_reason ?? '—',
    'Recording notice shown' => $meta['notice_shown_at'] ?? '—',
  ];
  $booking = $meta['booking'] ?? null;
  $shuttle = $meta['shuttle'] ?? null;
  $media = $this->media;
@endphp
<x-filament-panels::page>
  <div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:16px">
    <div style="background:#fff;border:1px solid #E7E3DA;border-radius:12px;padding:16px">
      <h3 style="font-weight:800;margin:0 0 8px">Call</h3>
      <table style="width:100%;font-size:13px">
        @foreach ($rows as $k => $v)
          <tr><td style="color:#78716C;padding:3px 8px 3px 0;vertical-align:top;width:40%">{{ $k }}</td><td style="padding:3px 0">{{ $v }}</td></tr>
        @endforeach
      </table>
      @if ($booking)
        <h3 style="font-weight:800;margin:14px 0 6px">Booking captured on the call</h3>
        <p style="font-size:13px;margin:0">{{ $booking['mode'] === 'video' ? 'Video consult' : 'In-person consult' }} · {{ $booking['date'] }} {{ $booking['start_time'] }}–{{ $booking['end_time'] }} · appointment #{{ $booking['appointment_id'] }}</p>
        @if (! empty($booking['notes']))<p style="font-size:13px;color:#57534E;margin:4px 0 0">Notes: {{ $booking['notes'] }}</p>@endif
      @endif
      @if ($shuttle)
        <h3 style="font-weight:800;margin:14px 0 6px">Shuttle</h3>
        <p style="font-size:13px;margin:0">{{ ucfirst($shuttle['status'] ?? '') }}@if (! empty($shuttle['vehicle_type'])) · {{ str_replace('_', ' ', $shuttle['vehicle_type']) }} · R{{ number_format((float) ($shuttle['fare'] ?? 0), 2) }} · ride #{{ $shuttle['ride_id'] ?? '—' }}@endif</p>
      @endif
    </div>
    <div style="background:#fff;border:1px solid #E7E3DA;border-radius:12px;padding:16px">
      <h3 style="font-weight:800;margin:0 0 8px">Timeline (evidence)</h3>
      <ol style="font-size:13px;margin:0;padding-left:18px">
        @forelse ((array) $c->participants as $ev)
          <li>{{ isset($ev['at']) ? \Illuminate\Support\Carbon::parse($ev['at'])->setTimezone($tz)->format('H:i:s') : '' }} — {{ ucfirst($ev['role'] ?? '') }} {{ $ev['name'] ?? '' }}: <b>{{ $ev['event'] ?? '' }}</b>@if (! empty($ev['channel'])) ({{ $ev['channel'] }})@endif</li>
        @empty
          <li>No events.</li>
        @endforelse
      </ol>
      <h3 style="font-weight:800;margin:14px 0 6px">Recordings</h3>
      @forelse ($c->recordings as $r)
        <div style="border:1px solid #E7E3DA;border-radius:10px;padding:10px;margin-bottom:8px;font-size:13px">
          <b>#{{ $r->id }}</b> · {{ $r->source === 'jaas' ? 'JaaS server' : 'Practice browser' }} · {{ $r->status }} · {{ $r->disk ?? '—' }} · {{ number_format($r->size_bytes / 1048576, 2) }} MB
          @if ($r->legal_hold) · <span style="color:#B91C1C;font-weight:700">LEGAL HOLD</span>@endif
          <div style="color:#78716C;word-break:break-all">sha256 {{ $r->sha256 ?? '—' }} · keep until {{ $r->retention_until?->setTimezone($tz)->format('Y-m-d') ?? '—' }}</div>
          @if (! empty($media[$r->id]['play']))
            <audio controls preload="none" style="width:100%;margin-top:6px" src="{{ $media[$r->id]['play'] }}"></audio>
            <a href="{{ $media[$r->id]['download'] }}" style="color:#1F4E7A;font-weight:700">Download</a>
          @elseif ($r->error)
            <div style="color:#B91C1C">{{ $r->error }}</div>
          @endif
          @if ($r->accessLogs->isNotEmpty())
            <details style="margin-top:6px"><summary>Access log ({{ $r->accessLogs->count() }})</summary>
              <ul style="margin:4px 0 0 16px">@foreach ($r->accessLogs->take(50) as $a)<li>{{ $a->created_at?->setTimezone($tz)->format('Y-m-d H:i:s') }} · {{ $a->user?->name ?? 'system' }} · {{ $a->action }} · {{ $a->ip }}</li>@endforeach</ul>
            </details>
          @endif
        </div>
      @empty
        <p style="font-size:13px;color:#78716C">No recording for this call ({{ $c->recording_status }}).</p>
      @endforelse
    </div>
  </div>
</x-filament-panels::page>
