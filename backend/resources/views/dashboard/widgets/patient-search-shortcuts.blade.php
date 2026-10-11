@php
    $shortcuts = [
        ['label' => 'Doctor', 'icon' => 'user', 'href' => route('doctors.index')],
        ['label' => 'Pharmacy', 'icon' => 'package', 'href' => route('pharmacies.index')],
        ['label' => 'Hospital', 'icon' => 'plus-square', 'href' => route('clinics.index')],
        ['label' => 'Ambulance', 'icon' => 'truck', 'href' => route('emergency')],
        ['label' => 'Shuttle', 'icon' => 'navigation', 'href' => route('shuttle')],
        ['label' => 'Lab', 'icon' => 'droplet', 'href' => route('labs.index')],
    ];
@endphp
<div class="paw-card">
  <h3 class="paw-title" style="margin-bottom:12px">{{ $w->title }}</h3>
  <form class="paw-search" method="GET" action="{{ route('doctors.index') }}">
    @include('dashboard.widgets._icon', ['name' => 'search', 'size' => 18])
    <input type="text" name="search" data-pa-search placeholder="{{ $s['placeholder'] ?? 'Search doctor, speciality or clinic' }}" aria-label="Search">
  </form>
  <div class="paw-shortcuts">
    @foreach ($shortcuts as $sc)
      <a class="paw-shortcut" href="{{ $sc['href'] }}">
        <span class="paw-ico-circle">@include('dashboard.widgets._icon', ['name' => $sc['icon'], 'size' => 22])</span>
        <span>{{ $sc['label'] }}</span>
      </a>
    @endforeach
  </div>
</div>
