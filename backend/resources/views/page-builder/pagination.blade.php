@if ($paginator->hasPages())
    <nav style="display:flex;justify-content:center;gap:6px;margin-top:32px;flex-wrap:wrap">
        @if ($paginator->onFirstPage())
            <span class="pa-btn-ghost" style="opacity:.4">‹ Prev</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" class="pa-btn-ghost">‹ Prev</a>
        @endif

        @foreach ($paginator->getUrlRange(max(1, $paginator->currentPage() - 2), min($paginator->lastPage(), $paginator->currentPage() + 2)) as $page => $url)
            @if ($page == $paginator->currentPage())
                <span class="pa-tab is-on">{{ $page }}</span>
            @else
                <a href="{{ $url }}" class="pa-tab">{{ $page }}</a>
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" class="pa-btn-ghost">Next ›</a>
        @else
            <span class="pa-btn-ghost" style="opacity:.4">Next ›</span>
        @endif
    </nav>
@endif
