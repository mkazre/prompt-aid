<x-filament-panels::page>
    <form wire:submit="send">
        {{ $this->form }}

        <div style="margin-top:24px">
            <x-filament::button type="submit" icon="heroicon-o-megaphone" wire:loading.attr="disabled">
                Send notification
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
