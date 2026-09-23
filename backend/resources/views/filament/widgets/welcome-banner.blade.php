<x-filament-widgets::widget>
    @php
        $blurb = $this->getRoleBlurb();
        $user = $this->getUser();
        $actions = $this->getQuickActions();
    @endphp
    {{--
        Inline styles throughout: Filament ships a pre-purged CSS bundle
        containing only the utility classes its own components reference at
        build time. Arbitrary Tailwind classes written in *our* custom Blade
        files (like this one) are never compiled in and silently no-op —
        so this widget can't rely on Tailwind utilities the way our public
        site's Vite-built CSS can. Inline styles always work regardless.
    --}}
    <div style="position: relative; overflow: hidden; border-radius: 0.75rem; padding: 2rem 1.5rem; background: linear-gradient(135deg, #2f46ba 0%, #3a57e8 55%, #079aa2 100%);">
        <div style="pointer-events: none; position: absolute; top: -2.5rem; right: -2.5rem; height: 14rem; width: 14rem; border-radius: 9999px; background: rgba(255,255,255,0.1);"></div>
        <div style="pointer-events: none; position: absolute; bottom: -4rem; right: 6rem; height: 10rem; width: 10rem; border-radius: 9999px; background: rgba(255,255,255,0.1);"></div>

        <div style="position: relative; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 1.5rem;">
            <div>
                <p style="margin: 0; font-size: 0.875rem; font-weight: 500; color: rgba(255,255,255,0.8);">{{ $this->getGreeting() }},</p>
                <h1 style="margin: 0.25rem 0 0; font-size: 1.75rem; line-height: 1.2; font-weight: 700; color: #fff;">{{ $user?->name }}</h1>
                <p style="margin: 0.5rem 0 0; font-size: 0.875rem; color: rgba(255,255,255,0.8);">{{ $blurb['label'] }} &middot; {{ $blurb['description'] }}</p>

                @if (count($actions))
                    <div style="margin-top: 1.5rem; display: flex; flex-wrap: wrap; align-items: center; gap: 0.75rem;">
                        @foreach ($actions as $action)
                            @php
                                $isPrimary = $action['color'] === 'primary';
                            @endphp
                            <a
                                href="{{ $action['url'] }}"
                                style="display: inline-flex; align-items: center; gap: 0.5rem; border-radius: 0.5rem; padding: 0.625rem 1rem; font-size: 0.875rem; font-weight: 600; text-decoration: none; box-shadow: 0 1px 2px rgba(0,0,0,0.08); white-space: nowrap;
                                    {{ $isPrimary ? 'background: #fff; color: #2f46ba;' : 'background: rgba(255,255,255,0.15); color: #fff;' }}"
                            >
                                <x-filament::icon :icon="$action['icon']" style="width: 1rem; height: 1rem; flex-shrink: 0;" />
                                <span>{{ $action['label'] }}</span>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>

            <div style="display: none; align-items: center; justify-content: center; border-radius: 9999px; background: rgba(255,255,255,0.15); padding: 1.5rem; flex-shrink: 0;" class="fi-welcome-banner-icon">
                <x-filament::icon icon="heroicon-o-heart" style="width: 4rem; height: 4rem; color: #fff;" />
            </div>
        </div>
    </div>

    <style>
        @media (min-width: 640px) {
            .fi-welcome-banner-icon { display: flex !important; }
        }
    </style>
</x-filament-widgets::widget>
