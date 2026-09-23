<x-filament-panels::page>
    <x-filament::section>
        <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-4">
            @forelse ($this->getFiles() as $file)
                <div class="border rounded p-2">
                    <img src="{{ $file['url'] }}" class="w-full h-24 object-cover rounded mb-2" alt="{{ basename($file['path']) }}">
                    <p class="text-xs truncate" title="{{ $file['path'] }}">{{ basename($file['path']) }}</p>
                    <p class="text-xs text-gray-400">{{ number_format($file['size'] / 1024, 1) }} KB</p>
                    <button
                        type="button"
                        wire:click="deleteFile('{{ $file['path'] }}')"
                        wire:confirm="Delete this file? Anything still referencing it will show a broken image."
                        class="text-xs text-danger-600 mt-1"
                    >Delete</button>
                </div>
            @empty
                <p class="text-sm text-gray-500 col-span-full">No uploaded images yet — page-builder Image blocks and the Theme Settings logo/favicon will show up here once uploaded.</p>
            @endforelse
        </div>
    </x-filament::section>
</x-filament-panels::page>
