@php
  $noTriage = true;
  $lvl = $scenario['level'];
  $lvlTitle = [
      'red' => 'Red — get emergency care immediately',
      'orange' => 'Orange — very urgent',
      'yellow' => 'Yellow — urgent, be seen today',
      'green' => 'Green — routine, can be booked normally',
  ][$lvl] ?? 'Your next steps';
  $lvlEmergency = $scenario['emergency'] ?? null;
@endphp
<x-layout title="Next steps · Prompt Aid">
<div class="pa-pagehead"><div class="inner">
  <div class="pa-crumb"><a href="{{ url('/') }}">Home</a> <span style="color:#CFC8B8">/</span> <a href="{{ route('emergency') }}">Emergency triage</a> <span style="color:#CFC8B8">/</span> Next steps</div>
  <div class="pa-row" style="margin-bottom:12px"><span class="pa-blip"></span><span class="pa-eyebrow">PromptAid Emergency Scale · {{ $scenario['level_label'] }}</span></div>
  <h1>{{ $lvlTitle }}</h1>
  @if ($triageRef)<p class="pa-muted" style="font-size:15px;margin:8px 0 0">Reference <strong>{{ $triageRef }}</strong> — show this at reception.</p>@endif
</div></div>
<div class="pa-container" style="padding-top:28px;padding-bottom:80px;max-width:760px">
  @include('triage._steps', ['scenario' => $scenario, 'autoDial' => true])
  <div class="pa-note-stop" style="margin-top:24px">This is a pre-triage aid, not a diagnosis. If it gets worse, call
    @if (! empty($lvlEmergency['tel']))<a href="{{ $lvlEmergency['tel'] }}" style="color:inherit;font-weight:900">{{ $lvlEmergency['phone'] }}</a>@else emergency services @endif
    or 112 from a mobile.</div>
</div>
</x-layout>
