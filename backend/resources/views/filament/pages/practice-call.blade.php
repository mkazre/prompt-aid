@php
  $pcCfg = $this->pcConfig();
@endphp
<x-filament-panels::page>
  <div wire:ignore>
    @include('practice-calls.shell', ['pcCfg' => $pcCfg, 'pcRole' => 'practice'])
  </div>
</x-filament-panels::page>
