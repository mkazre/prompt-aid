<x-layout :title="($page->seo_title ?: $page->title).' — Prompt Aid'">
    @if ($page->seo_description)
        @push('meta')
            <meta name="description" content="{{ $page->seo_description }}">
        @endpush
    @endif

    {!! $html !!}
</x-layout>
