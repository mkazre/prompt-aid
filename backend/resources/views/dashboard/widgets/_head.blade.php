<div class="paw-head">
  <div>
    <h3 class="paw-title">{{ $w->title }}</h3>
    @if ($w->subtitle)<div class="paw-sub">{{ $w->subtitle }}</div>@endif
  </div>
  @if (! empty($link))
    <a class="paw-link" href="{{ $link }}">{{ $linkLabel ?? 'See all' }}</a>
  @endif
</div>
