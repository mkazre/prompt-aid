@php
  $hubData = $this->hubData();
  $videoJsUrl = asset('assets/js/pa-video-call.js').'?v=2';
  $hubJsUrl = asset('assets/js/pa-consult-hub.js').'?v=1';
@endphp
<x-filament-panels::page>
  <div wire:ignore>
    @if (! empty($hubData['hub']))
      @include('consult-hub.hub', ['hub' => $hubData['hub'], 'hubConfig' => $hubData['hubConfig'], 'callConfig' => $hubData['callConfig'], 'role' => 'doctor'])
      <script src="{{ $videoJsUrl }}"></script>
      <script src="{{ $hubJsUrl }}"></script>
    @endif
  </div>
</x-filament-panels::page>
