<x-layout title="Pharmacy &amp; tests · Prompt Aid">
<div class="pa-pagehead"><div class="inner">
  <div class="pa-crumb"><a href="{{ url('/') }}">Home</a> <span style="color:#CFC8B8">/</span> Pharmacy &amp; tests</div>
  <div class="pa-spread" style="align-items:flex-end">
    <div><h1>Pharmacy &amp; tests</h1><p class="pa-muted" style="font-size:15px;margin:6px 0 24px">Medicine, devices, lab panels and treatment blocks from our partner pharmacies.</p></div>
  </div>
  <div class="pa-tabs">
    <a class="pa-tab {{ request('c') ? '' : 'is-on' }}" href="{{ route('shop.index') }}">Everything</a>
    <a class="pa-tab {{ request('c') === 'goods' ? 'is-on' : '' }}" href="{{ route('shop.index', ['c' => 'goods']) }}">Medicine &amp; devices</a>
    <a class="pa-tab {{ request('c') === 'lab_package' ? 'is-on' : '' }}" href="{{ route('shop.index', ['c' => 'lab_package']) }}">Lab panels</a>
    <a class="pa-tab {{ request('c') === 'service_block' ? 'is-on' : '' }}" href="{{ route('shop.index', ['c' => 'service_block']) }}">Treatment blocks</a>
    <a class="pa-tab {{ request('c') === 'consult_bundle' ? 'is-on' : '' }}" href="{{ route('shop.index', ['c' => 'consult_bundle']) }}">Consult bundles</a>
    <a class="pa-tab" href="{{ route('pharmacies.index') }}">Browse by pharmacy</a>
  </div>
</div></div>
<div class="pa-container" style="padding-top:32px;padding-bottom:80px">
  <div class="pa-spread" style="margin-bottom:16px">
    <div style="font-size:14px;color:var(--pa-muted)"><strong style="color:var(--pa-ink)">{{ $products->total() }}</strong> items</div>
    <form method="GET"><input class="pa-field" name="search" value="{{ request('search') }}" placeholder="Search products…" style="width:220px;background:#fff" /></form>
  </div>
  <div class="pa-grid" style="grid-template-columns:repeat(auto-fill,minmax(230px,1fr))">
    @forelse ($products as $product)
      <div style="padding:20px;display:flex;flex-direction:column">
        <a href="{{ route('pharmacies.show', $product->pharmacy) }}" class="pa-slot" style="height:130px;margin-bottom:14px;position:relative;font-size:30px;font-weight:900;letter-spacing:0;color:#CFC8B8">{{ mb_strtoupper(mb_substr($product->name, 0, 2)) }}
          @if ($product->requires_prescription)<span class="pa-badge is-ink" style="position:absolute;top:0;left:0;background:var(--pa-signal)">Script</span>@endif
        </a>
        <div class="pa-eyebrow" style="margin-bottom:6px">{{ $product->category ?? ucfirst(str_replace('_', ' ', $product->kind)) }}</div>
        <a href="{{ route('pharmacies.show', $product->pharmacy) }}" style="font-size:14px;font-weight:700;line-height:1.35;margin-bottom:6px;color:var(--pa-ink)">{{ $product->name }}</a>
        <div style="font-size:12px;color:var(--pa-muted);margin-bottom:14px">{{ $product->pharmacy->name }}</div>
        <div class="pa-spread" style="margin-top:auto"><span class="pa-num" style="font-size:20px">R {{ number_format($product->price, 2) }}</span><a class="pa-btn-quiet pa-btn-sm" href="{{ route('pharmacies.show', $product->pharmacy) }}">Add</a></div>
      </div>
    @empty
      <p class="pa-muted">No products found.</p>
    @endforelse
  </div>
  <div style="margin-top:32px">{{ $products->links() }}</div>
</div>
</x-layout>
