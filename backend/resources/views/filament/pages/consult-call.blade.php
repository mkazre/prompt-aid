@php
  $cfg = $this->callData();
  $summaryRows = $this->summaryData();
  $cssUrl = asset('assets/css/pa-video-call.css').'?v=2';
  $jsUrl = asset('assets/js/pa-video-call.js').'?v=2';
  $errorText = $this->error;
@endphp
<x-filament-panels::page>
  <link rel="stylesheet" href="{{ $cssUrl }}">

  @if ($errorText)
    <div style="padding:28px;border-radius:16px;background:#fff;border:1px solid #e5e5ea;text-align:center">
      <p style="margin:0 0 16px;font-size:15px;color:#3f3f46">{{ $errorText }}</p>
      <x-filament::button tag="a" :href="url()->previous()" color="gray" icon="heroicon-o-arrow-left">Go back</x-filament::button>
    </div>
  @elseif (! empty($cfg))
    <div wire:ignore>
      @include('video.partials.stage', [
        'config' => $cfg,
        'extraClass' => 'pa-call--staff',
        'messageUrl' => $this->messageUrl,
        'summary' => $summaryRows,
        'summaryTitle' => 'Patient summary',
      ])
    </div>
    <script src="{{ $jsUrl }}"></script>
  @endif
</x-filament-panels::page>
