<x-layout :title="$product->name.' · Prompt Aid'">
<div class="pa-container" style="padding-top:32px;padding-bottom:80px">
  <div class="pa-crumb"><a href="{{ url('/') }}">Home</a> <span style="color:#CFC8B8">/</span> <a href="{{ route('shop.index') }}">Pharmacy &amp; tests</a> <span style="color:#CFC8B8">/</span> {{ $product->name }}</div>
  <div style="display:grid;grid-template-columns:minmax(0,1fr) 400px;gap:40px;align-items:start;margin-top:20px">
    <div>
      <div class="pa-slot" style="height:300px;margin-bottom:24px;font-size:30px;font-weight:900;color:#CFC8B8">{{ mb_strtoupper(mb_substr($product->name, 0, 2)) }}</div>
      @if ($product->description)
        <div class="pa-card pa-card-pad" style="margin-bottom:24px">
          <h3 style="margin-bottom:12px">Description</h3>
          <p style="font-size:15px;line-height:1.65;color:var(--pa-ink-soft)">{{ $product->description }}</p>
        </div>
      @endif
      <div class="pa-card">
        <div class="pa-card-head"><h3>Sold by {{ $product->pharmacy->name }}</h3><a class="pa-btn-ghost pa-btn-sm" href="{{ route('pharmacies.show', $product->pharmacy) }}">View the pharmacy</a></div>
        <div class="pa-card-body" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:20px">
          <div><div class="pa-label" style="margin-bottom:5px">Delivery fee</div><div style="font-size:15px;font-weight:700">R {{ number_format($product->pharmacy->delivery_fee, 0) }}</div></div>
          @if ($product->pharmacy->rating_count)<div><div class="pa-label" style="margin-bottom:5px">Rating</div><div style="font-size:15px;font-weight:700">{{ number_format($product->pharmacy->rating_avg, 1) }} / {{ $product->pharmacy->rating_count }}</div></div>@endif
          <div><div class="pa-label" style="margin-bottom:5px">Stock</div><div style="font-size:15px;font-weight:700">{{ $product->stock > 0 ? $product->stock.' available' : 'Out of stock' }}</div></div>
        </div>
      </div>
    </div>
    <div style="position:sticky;top:96px">
      <div class="pa-pop" style="padding:26px">
        <div class="pa-eyebrow" style="margin-bottom:10px">{{ ucfirst(str_replace('_', ' ', $product->kind)) }} · {{ $product->pharmacy->name }}</div>
        <h1 style="font-size:30px;margin-bottom:14px">{{ $product->name }}</h1>
        <div style="display:flex;align-items:baseline;gap:12px;margin-bottom:22px">
          <span class="pa-num" style="font-size:38px">R {{ number_format($product->price, 2) }}</span>
        </div>
        @if ($product->requires_prescription)
          <div style="font-size:13px;color:var(--pa-signal);font-weight:700;margin-bottom:16px">Prescription required</div>
        @endif
        <a class="pa-btn pa-btn-block pa-btn-lg" style="margin-bottom:8px" href="{{ route('pharmacies.show', $product->pharmacy) }}?product={{ $product->id }}">Add to basket</a>
        <a class="pa-btn-ghost pa-btn-block" href="{{ route('shuttle') }}?to={{ urlencode($product->pharmacy->name) }}"><span class="pa-tick-beacon" style="width:7px;height:7px"></span>Add a shuttle to the pharmacy</a>
      </div>
    </div>
  </div>
</div>
</x-layout>
