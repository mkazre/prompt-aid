@php $clinics = $d['items'] ?? []; @endphp
@if (count($clinics))
<div class="paw-card">
  @include('dashboard.widgets._head', ['w' => $w, 'link' => route('clinics.index')])
  @foreach ($clinics as $clinic)
    <a class="paw-row" href="{{ route('clinics.show', $clinic['id']) }}">
      @if ($clinic['logo'])
        <img class="paw-thumb" src="{{ $clinic['logo'] }}" alt="" loading="lazy">
      @else
        <span class="paw-ico-circle" style="background:#1F7A4C1A;color:#1F7A4C">@include('dashboard.widgets._icon', ['name' => 'plus-square'])</span>
      @endif
      <span style="flex:1;min-width:0">
        <strong class="paw-ellipsis">{{ $clinic['name'] }}</strong>
        <span class="paw-muted paw-ellipsis">{{ $clinic['address'] }}{{ $clinic['city'] ? ', '.$clinic['city'] : '' }}</span>
      </span>
      <span class="paw-pill">{{ $clinic['distance_km'] !== null ? $clinic['distance_km'].' km' : $clinic['doctors_count'].' doctors' }}</span>
    </a>
  @endforeach
</div>
@endif
