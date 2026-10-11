@php
    // Tiles are editable in Site → App Widgets (shortcut tiles); see AppWidget::publicSettings().
    $shortcuts = $s['shortcuts'] ?? [];
@endphp
<div class="paw-card">
  <h3 class="paw-title" style="margin-bottom:12px">{{ $w->title }}</h3>
  <form class="paw-search" method="GET" action="{{ route('doctors.index') }}">
    @include('dashboard.widgets._icon', ['name' => 'search', 'size' => 18])
    <input type="text" name="search" data-pa-search placeholder="{{ $s['placeholder'] ?? 'Search doctor name or speciality' }}" aria-label="Search">
  </form>
  <div class="paw-shortcuts">
    @foreach ($shortcuts as $sc)
      <a class="paw-shortcut" href="{{ $sc['web_url'] ?? '#' }}">
        <span class="paw-ico-circle" @if(! empty($sc['color'])) style="color: {{ $sc['color'] }}; background: {{ $sc['color'] }}1A" @endif>@include('dashboard.widgets._icon', ['name' => $sc['icon'] ?? 'grid', 'image' => $sc['icon_image_url'] ?? null, 'size' => 22])</span>
        <span>{{ $sc['label'] }}</span>
      </a>
    @endforeach
  </div>
</div>
