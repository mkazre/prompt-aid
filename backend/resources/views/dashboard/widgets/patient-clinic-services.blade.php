@php $services = $d['items'] ?? []; @endphp
@if (count($services))
<div>
  <div class="paw-head paw-head-bare">
    <h3 class="paw-section-title" style="margin:0">{{ $w->title }}</h3>
    <a class="paw-link" href="{{ route('clinics.index') }}">See all</a>
  </div>
  <div class="paw-services">
    @foreach ($services as $svc)
      <a class="paw-service" href="{{ route('doctors.index', ['specialization' => $svc['name']]) }}">
        <span class="paw-ico-circle paw-ico-lg">@include('dashboard.widgets._icon', ['name' => \App\Support\Widgets\WidgetCatalog::iconFor($svc['name']), 'size' => 24])</span>
        <span class="paw-service-name">{{ $svc['name'] }}</span>
      </a>
    @endforeach
  </div>
</div>
@endif
