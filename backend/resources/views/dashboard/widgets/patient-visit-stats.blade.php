@php
    $statTints = ['#1F4E7A', '#D0211C', '#1F7A4C', '#E4701E'];
@endphp
<div>
  <h3 class="paw-section-title">{{ $w->title }}</h3>
  <div class="paw-stats">
    @foreach (($d['items'] ?? []) as $i => $item)
      <div class="paw-card paw-stat">
        <span class="paw-ico-circle" style="background: {{ $statTints[$i % 4] }}1A; color: {{ $statTints[$i % 4] }}">@include('dashboard.widgets._icon', ['name' => $item['icon'], 'size' => 20])</span>
        <div>
          <div class="paw-stat-value">{{ $item['value'] }}</div>
          <div class="paw-muted">{{ $item['label'] }}</div>
        </div>
      </div>
    @endforeach
  </div>
</div>
