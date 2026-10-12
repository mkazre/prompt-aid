<x-layout title="Vitals · Prompt Aid">
@push('meta')<link rel="stylesheet" href="{{ asset('assets/css/pa-health.css') }}" />@endpush
@php
  $units = ['systolic' => 'mmHg', 'diastolic' => 'mmHg', 'pulse' => 'bpm', 'glucose' => 'mmol/L', 'weight' => 'kg', 'temperature' => '°C', 'spo2' => '%'];
  $labels = ['systolic' => 'Systolic BP', 'diastolic' => 'Diastolic BP', 'pulse' => 'Pulse', 'glucose' => 'Glucose', 'weight' => 'Weight', 'temperature' => 'Temperature', 'spo2' => 'Oxygen (SpO₂)'];
  $flagOf = fn ($metric, $v) => \App\Support\HealthSettings::vitalFlag($metric, $v, $ranges);
  // Inline SVG sparkline: normal band shaded, last point coloured by its flag.
  $spark = function (string $metric, array $values) use ($ranges, $flagOf) {
      $r = $ranges[$metric] ?? null;
      $lo = min($values); $hi = max($values);
      if ($r) { $lo = min($lo, $r['normal_min']); $hi = max($hi, $r['normal_max']); }
      $pad = ($hi - $lo) * 0.1 ?: 1; $lo -= $pad; $hi += $pad;
      $n = count($values);
      $x = fn ($i) => $n > 1 ? round($i * 200 / ($n - 1), 1) : 100;
      $y = fn ($v) => round(40 - (($v - $lo) / ($hi - $lo)) * 40, 1);
      $pts = collect($values)->map(fn ($v, $i) => $x($i).','.$y($v))->implode(' ');
      $band = $r ? '<rect x="0" y="'.$y($r['normal_max']).'" width="200" height="'.max(0, $y($r['normal_min']) - $y($r['normal_max'])).'" fill="#E7F3EC"/>' : '';
      $last = end($values);
      $col = ['red' => '#C8102E', 'orange' => '#E4701E'][$flagOf($metric, $last)] ?? '#1F4E7A';

      return '<svg viewBox="0 0 200 40" preserveAspectRatio="none" aria-hidden="true">'.$band
          .'<polyline points="'.$pts.'" fill="none" stroke="#101012" stroke-width="1.5" vector-effect="non-scaling-stroke"/>'
          .'<circle cx="'.$x($n - 1).'" cy="'.$y($last).'" r="3" fill="'.$col.'"/></svg>';
  };
  $chip = function ($metric, $value, $text) use ($flagOf) {
      if ($value === null) return '<span class="pa-muted">—</span>';
      $f = $flagOf($metric, $value);
      return '<span class="pa-hl-chip '.($f ? 'is-'.$f : '').'">'.e($text).'</span>';
  };
@endphp
<div class="pa-account">
<x-account-nav active="vitals" />
<div>
  <div class="pa-spread" style="margin-bottom:22px">
    <div><h1 style="font-size:32px">Vitals</h1><p class="pa-muted" style="margin:6px 0 0">Track your blood pressure, pulse, glucose, weight, temperature and oxygen. Your doctor can see these.</p></div>
    <a class="pa-btn" href="#vital-form">Log a reading</a>
  </div>

  @include('account.health._flash')

  @if ($series)
    <div class="pa-hl-spark">
      @foreach ($series as $metric => $values)
        @php $last = end($values); $f = $flagOf($metric, $last); @endphp
        <div class="tile">
          <div class="lbl">{{ $labels[$metric] }}</div>
          <div class="val">{{ rtrim(rtrim(number_format($last, 1, '.', ''), '0'), '.') }} <small>{{ $units[$metric] }}</small>
            @if ($f)<span class="pa-hl-chip is-{{ $f }}" style="vertical-align:middle">{{ $f === 'red' ? 'Critical' : 'Out of range' }}</span>@endif
          </div>
          {!! $spark($metric, $values) !!}
          @isset($ranges[$metric])<div class="pa-muted" style="font-size:11px;margin-top:4px">Normal {{ $ranges[$metric]['normal_min'] + 0 }}–{{ $ranges[$metric]['normal_max'] + 0 }} {{ $units[$metric] }}</div>@endisset
        </div>
      @endforeach
    </div>
  @endif

  <form method="POST" id="vital-form" action="{{ route('account.health.vitals.store') }}" style="margin-bottom:24px">
    @csrf
    <div class="pa-card">
      <div class="pa-card-head"><h3>Log a reading</h3></div>
      <div class="pa-card-body">
        <div class="pa-formgrid" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr))">
          <div><label class="pa-label">When</label><input class="pa-field" type="datetime-local" name="recorded_at" value="{{ old('recorded_at', now(\App\Support\HealthSettings::TIMEZONE)->format('Y-m-d\TH:i')) }}"></div>
          <div><label class="pa-label">Systolic (mmHg)</label><input class="pa-field" type="number" name="systolic" min="40" max="300" value="{{ old('systolic') }}" placeholder="120"></div>
          <div><label class="pa-label">Diastolic (mmHg)</label><input class="pa-field" type="number" name="diastolic" min="20" max="200" value="{{ old('diastolic') }}" placeholder="80"></div>
          <div><label class="pa-label">Pulse (bpm)</label><input class="pa-field" type="number" name="pulse" min="20" max="250" value="{{ old('pulse') }}" placeholder="72"></div>
          <div><label class="pa-label">Glucose (mmol/L)</label><input class="pa-field" type="number" step="0.1" name="glucose" min="0.5" max="60" value="{{ old('glucose') }}" placeholder="5.4"></div>
          <div><label class="pa-label">Weight (kg)</label><input class="pa-field" type="number" step="0.1" name="weight" min="0.5" max="500" value="{{ old('weight') }}"></div>
          <div><label class="pa-label">Temperature (°C)</label><input class="pa-field" type="number" step="0.1" name="temperature" min="30" max="45" value="{{ old('temperature') }}" placeholder="36.8"></div>
          <div><label class="pa-label">Oxygen SpO₂ (%)</label><input class="pa-field" type="number" name="spo2" min="50" max="100" value="{{ old('spo2') }}" placeholder="98"></div>
          <div style="grid-column:1/-1"><label class="pa-label">Note</label><input class="pa-field" name="note" maxlength="500" value="{{ old('note') }}" placeholder="e.g. After exercise"></div>
        </div>
        <p class="pa-muted" style="font-size:12px;margin:12px 0 0">{{ $note }}</p>
        <div style="margin-top:14px"><button class="pa-btn" type="submit">Save reading</button></div>
      </div>
    </div>
  </form>

  <div class="pa-tablewrap"><table class="pa-table">
    <thead><tr><th>When</th><th>BP</th><th>Pulse</th><th>Glucose</th><th>Weight</th><th>Temp</th><th>SpO₂</th><th></th></tr></thead>
    <tbody>
      @forelse ($readings as $r)
        @php
          $bpFlag = collect([$flagOf('systolic', $r->systolic), $flagOf('diastolic', $r->diastolic)]);
          $bpClass = $bpFlag->contains('red') ? 'is-red' : ($bpFlag->contains('orange') ? 'is-orange' : '');
        @endphp
        <tr>
          <td><div class="t-main">{{ $r->recorded_at->copy()->setTimezone(\App\Support\HealthSettings::TIMEZONE)->format('d M Y H:i') }}</div>@if($r->note)<div class="t-sub">{{ $r->note }}</div>@endif</td>
          <td>@if($r->systolic)<span class="pa-hl-chip {{ $bpClass }}">{{ $r->systolic }}/{{ $r->diastolic }}</span>@else<span class="pa-muted">—</span>@endif</td>
          <td>{!! $chip('pulse', $r->pulse, $r->pulse) !!}</td>
          <td>{!! $chip('glucose', $r->glucose, $r->glucose) !!}</td>
          <td>{{ $r->weight !== null ? $r->weight.' kg' : '—' }}</td>
          <td>{!! $chip('temperature', $r->temperature, $r->temperature !== null ? $r->temperature.'°' : null) !!}</td>
          <td>{!! $chip('spo2', $r->spo2, $r->spo2 !== null ? $r->spo2.'%' : null) !!}</td>
          <td class="t-right">
            <form method="POST" action="{{ route('account.health.vitals.destroy', $r->id) }}" class="pa-hl-inline" onsubmit="return confirm('Delete this reading?');">
              @csrf @method('DELETE')
              <button class="pa-btn-ghost pa-btn-sm" type="submit">Delete</button>
            </form>
          </td>
        </tr>
      @empty
        <tr><td colspan="8"><p class="pa-muted" style="padding:24px;text-align:center;font-size:14px">No readings yet. Log your first one above.</p></td></tr>
      @endforelse
    </tbody>
  </table></div>
</div>
</div>
</x-layout>
