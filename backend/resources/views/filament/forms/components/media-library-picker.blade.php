@php
    // $schemaComponent is auto-injected by Filament's ViewComponent::renderView() —
    // it's this View component itself, so its resolved state path already
    // accounts for any repeater/builder nesting (e.g. content.3.data.src).
    $statePath = $schemaComponent->getStatePath();
    $files = \App\Filament\Support\MediaLibraryPicker::files();
@endphp
<div x-data="{ open: false }">
    <button type="button" x-on:click="open = true" class="pa-mlp-btn">
        Choose from library
    </button>

    <div
        x-show="open"
        x-cloak
        x-on:keydown.escape.window="open = false"
        x-on:click.self="open = false"
        class="pa-mlp-overlay"
        style="display: none;"
    >
        <div class="pa-mlp-modal">
            <div class="pa-mlp-modal-head">
                <h2>Choose from media library</h2>
                <button type="button" x-on:click="open = false" class="pa-mlp-modal-close" aria-label="Close">&times;</button>
            </div>

            @if (empty($files))
                <p style="font-size: 13px; color: var(--pa-muted)">No images in the media library yet. Upload one below instead.</p>
            @else
                <div class="pa-mlp-grid">
                    @foreach ($files as $file)
                        <button
                            type="button"
                            x-on:click="$wire.set(@js($statePath), @js($file['path'])); open = false;"
                            class="pa-mlp-item"
                            title="{{ $file['name'] }}"
                        >
                            <img src="{{ $file['url'] }}" alt="{{ $file['name'] }}" loading="lazy">
                            <span class="pa-mlp-item-name">{{ $file['name'] }}</span>
                        </button>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
