<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div style="margin-top:24px;display:flex;gap:12px;flex-wrap:wrap">
            <x-filament::button type="submit">
                Save shuttle settings
            </x-filament::button>
            <x-filament::button type="button" color="gray" wire:click="generateTickKey">
                Generate new /tick pinger key
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
