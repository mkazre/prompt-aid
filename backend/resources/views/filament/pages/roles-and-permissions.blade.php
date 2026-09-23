<x-filament-panels::page>
    <x-filament::section>
        <p class="text-sm text-gray-500 mb-4">
            Access here is role-based (not a per-permission editor) — each role maps to a Filament panel and, within
            it, resources decide for themselves who can see them (<code>canViewAny()</code>). This page documents
            what actually controls access, rather than presenting an editable matrix that wouldn't really gate
            anything.
        </p>
        <div class="space-y-4">
            @foreach ($this->getRoles() as $role)
                <div class="border-t pt-4">
                    <div class="flex items-center justify-between">
                        <h3 class="font-semibold">{{ $role['role'] }}</h3>
                        <x-filament::badge color="gray">{{ $role['panel'] }}</x-filament::badge>
                    </div>
                    <p class="text-sm text-gray-500 mt-1">{{ $role['description'] }}</p>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-panels::page>
