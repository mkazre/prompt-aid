<x-layout :title="($page->seo_title ?: $page->title).' — Prompt Aid'">
    @if ($page->seo_description)
        @push('meta')
            <meta name="description" content="{{ $page->seo_description }}">
        @endpush
    @endif

    {!! $html !!}

    @if ($page->slug === 'about')
        {{-- Targets of the home page "Learn more" links (#benefit-N); text is
             edited at /staff → Site → Site content → Benefits. --}}
        @include('partials.about-benefits')
    @endif

    @if (in_array($page->slug, ['legal-popia', 'privacy'], true))
        {{-- POPIA s55: Information Officer contact, managed in Staff → Privacy settings. --}}
        <div class="pa-container pa-privacy-io-block">
            <h3>Information Officer</h3>
            @include('privacy.partials.information-officer', ['officer' => \App\Support\PrivacySettings::informationOfficer()])
        </div>
    @endif
</x-layout>
