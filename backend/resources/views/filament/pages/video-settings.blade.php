@php
  $lines = $this->testLines;
  $ok = $this->testOk;
  $boxStyle = $ok === null ? '' : ($ok ? 'border-color:#1F7A4C;background:#effaf3' : 'border-color:#F2C200;background:#fffbea');
@endphp
<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div style="margin-top:24px;display:flex;gap:12px;flex-wrap:wrap">
            <x-filament::button type="submit" icon="heroicon-o-check" wire:loading.attr="disabled">
                Save settings
            </x-filament::button>
            <x-filament::button type="button" color="gray" icon="heroicon-o-signal" wire:click="testConfiguration" wire:loading.attr="disabled">
                Save &amp; test configuration
            </x-filament::button>
        </div>
    </form>

    @if (! empty($lines))
        <div style="margin-top:8px;padding:14px 16px;border:1px solid #e5e5ea;border-radius:12px;font-size:14px;line-height:1.6;{{ $boxStyle }}">
            @foreach ($lines as $line)
                <div>{{ $line }}</div>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
