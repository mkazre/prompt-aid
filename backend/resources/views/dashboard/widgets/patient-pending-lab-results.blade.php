@php
    $labItems = $d['items'] ?? [];
    $labReady = $d['ready_count'] ?? 0;
@endphp
<div class="paw-card">
  @include('dashboard.widgets._head', ['w' => $w, 'link' => route('account.results')])
  @forelse ($labItems as $lab)
    <a class="paw-row" href="{{ route('account.results') }}">
      <span class="paw-ico-circle" style="background:#1F4E7A1A;color:#1F4E7A">@include('dashboard.widgets._icon', ['name' => 'droplet', 'size' => 18])</span>
      <span style="flex:1;min-width:0">
        <strong class="paw-ellipsis">{{ count($lab['tests']) ? implode(', ', $lab['tests']) : $lab['request_ref'] }}</strong>
        <span class="paw-muted paw-ellipsis">{{ $lab['doctor_name'] ? 'Dr '.preg_replace('/^Dr\.?\s*/i', '', $lab['doctor_name']) : $lab['request_ref'] }}</span>
      </span>
      @if ($lab['results_ready'] > 0)
        <span class="pa-badge is-go">Ready</span>
      @else
        <span class="pa-badge is-wait">{{ str($lab['status'])->headline() }}</span>
      @endif
    </a>
  @empty
    <p class="paw-empty">No lab requests yet.</p>
  @endforelse
  @if ($labReady > 0)
    <div class="paw-note is-go">{{ $labReady }} result{{ $labReady === 1 ? '' : 's' }} ready to view.</div>
  @endif
</div>
