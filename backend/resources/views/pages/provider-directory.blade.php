<x-layout :title="$meta['heading'].' · Prompt Aid'">
<div class="pa-pagehead"><div class="inner">
  <div class="pa-crumb"><a href="{{ url('/') }}">Home</a> <span style="color:#CFC8B8">/</span> <a href="{{ route('doctors.index') }}">Find care</a> <span style="color:#CFC8B8">/</span> {{ $meta['label'] }}</div>
  <h1>{{ $meta['heading'] }}</h1>
  @if ($meta['description'])<p class="pa-muted" style="font-size:15px;margin:6px 0 24px">{{ $meta['description'] }}</p>@else<div style="height:24px"></div>@endif
  <x-care-tabs :active="$tab" />
</div></div>
<div class="pa-container" style="padding-top:32px;padding-bottom:80px">
  <form method="GET" style="margin-bottom:16px;display:flex;gap:8px;flex-wrap:wrap"><input class="pa-field" name="search" value="{{ request('search') }}" placeholder="{{ $placeholder }}" style="max-width:360px" /><button class="pa-btn" type="submit">Search</button></form>
  <div class="pa-spread" style="margin-bottom:16px">
    <div style="font-size:14px;color:var(--pa-muted)"><strong style="color:var(--pa-ink)">{{ $providers->total() }}</strong> results</div>
  </div>
  <div class="pa-grid" style="grid-template-columns:1fr">
    @forelse ($providers as $provider)
      <div class="pa-listing">
        <div class="pa-avatar" style="width:64px;height:64px;font-size:21px">{{ mb_strtoupper(mb_substr($provider->company_name, 0, 2)) }}</div>
        <div style="min-width:0">
          <div class="pa-row" style="flex-wrap:wrap;margin-bottom:5px">
            <a href="{{ route($showRoute, $provider) }}" style="font-size:18px;font-weight:900;letter-spacing:-.012em;color:var(--pa-ink)">{{ $provider->company_name }}</a>
            <span class="pa-badge">{{ ucfirst(str_replace('_', ' ', $provider->category)) }}</span>
          </div>
          <div style="font-size:14px;color:var(--pa-ink-soft);margin-bottom:4px">{{ $provider->service_type }}</div>
          @if ($provider->accepts_walk_ins)<div style="display:flex;gap:7px;flex-wrap:wrap"><span class="pa-chip">Walk-in</span></div>@endif
        </div>
        <div class="pa-listing-side">
          @if ($provider->rating_count)<div class="pa-num" style="font-size:23px">{{ number_format($provider->rating_avg, 1) }} <span style="font-size:13px;color:var(--pa-muted)">/ {{ $provider->rating_count }}</span></div>@endif
          <a class="pa-btn pa-btn-block" href="{{ route($showRoute, $provider) }}">{{ $cta }}</a>
          <a class="pa-btn-ghost pa-btn-block pa-btn-sm" style="margin-top:6px" href="{{ route('shuttle') }}?to={{ urlencode($provider->company_name) }}"><span class="pa-tick-beacon" style="width:6px;height:6px"></span>Add shuttle</a>
        </div>
      </div>
    @empty
      <p class="pa-muted">Nothing listed here yet.</p>
    @endforelse
  </div>
  <div style="margin-top:32px">{{ $providers->links() }}</div>
</div>
</x-layout>
