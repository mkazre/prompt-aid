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
</x-layout>
