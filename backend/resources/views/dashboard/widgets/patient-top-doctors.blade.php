@php $doctors = $d['items'] ?? []; @endphp
@if (count($doctors))
<div>
  <div class="paw-head paw-head-bare">
    <div>
      <h3 class="paw-section-title" style="margin:0">{{ $w->title }}</h3>
      @if ($w->subtitle)<div class="paw-sub">{{ $w->subtitle }}</div>@endif
    </div>
    <a class="paw-link" href="{{ route('doctors.index') }}">See all</a>
  </div>
  <div class="paw-carousel">
    @foreach ($doctors as $doc)
      <div class="paw-card paw-doctor">
        @if ($doc['avatar'])
          <img class="paw-doctor-photo" src="{{ $doc['avatar'] }}" alt="{{ $doc['name'] }}" loading="lazy">
        @else
          <span class="paw-doctor-photo paw-doctor-initials">{{ \App\Support\Widgets\WidgetCatalog::initials($doc['name']) }}</span>
        @endif
        <strong class="paw-ellipsis">{{ $doc['name'] }}</strong>
        <span class="paw-muted paw-ellipsis">{{ $doc['specialization'] }}</span>
        <span class="paw-doctor-meta">
          <span class="paw-rating">@include('dashboard.widgets._icon', ['name' => 'star', 'size' => 13]) {{ number_format($doc['rating_avg'], 1) }}</span>
          @if ($doc['distance_km'] !== null)
            <span>@include('dashboard.widgets._icon', ['name' => 'map-pin', 'size' => 13]) {{ $doc['distance_km'] }} km</span>
          @elseif ($doc['clinic_name'])
            <span class="paw-ellipsis">{{ $doc['clinic_name'] }}</span>
          @endif
        </span>
        <a class="paw-btn paw-btn-sm" href="{{ route('doctors.show', $doc['id']) }}">{{ $s['cta_label'] ?? 'Book now' }}</a>
      </div>
    @endforeach
  </div>
</div>
@endif
