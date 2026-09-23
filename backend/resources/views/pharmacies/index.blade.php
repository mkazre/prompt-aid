<x-layout title="Pharmacies · Prompt Aid">
<div class="pa-pagehead"><div class="inner">
  <div class="pa-crumb"><a href="{{ url('/') }}">Home</a> <span style="color:#CFC8B8">/</span> Pharmacies</div>
  <h1>Pharmacies</h1><p class="pa-muted" style="font-size:15px;margin:6px 0 24px">{{ number_format($pharmacies->total()) }} licensed pharmacy partners.</p>
  <div class="pa-tabs" style="gap:2px">
    <a class="pa-tab" href="{{ route('doctors.index') }}" style="border-bottom:0">All providers</a>
    <a class="pa-tab" href="{{ route('doctors.index') }}" style="border-bottom:0">Doctors</a>
    <a class="pa-tab" href="{{ route('clinics.index') }}" style="border-bottom:0">Clinics</a>
    <a class="pa-tab is-on" href="{{ route('pharmacies.index') }}" style="border-bottom:0">Pharmacies</a>
    <a class="pa-tab" href="{{ route('labs.index') }}" style="border-bottom:0">Labs &amp; imaging</a>
    <a class="pa-tab" href="{{ route('specialists.index') }}" style="border-bottom:0">Physio &amp; specialists</a>
  </div>
</div></div>
<div class="pa-container" style="padding-top:32px;padding-bottom:80px">
  <div class="pa-spread" style="margin-bottom:16px">
    <div style="font-size:14px;color:var(--pa-muted)"><strong style="color:var(--pa-ink)">{{ $pharmacies->total() }}</strong> results</div>
    <a class="pa-btn-ghost" href="{{ route('shop.index') }}">Browse all products</a>
  </div>
  <div class="pa-grid" style="grid-template-columns:1fr">
    @forelse ($pharmacies as $pharmacy)
      <div style="background:var(--pa-surface);padding:22px 24px;display:grid;grid-template-columns:64px minmax(0,1fr) auto;gap:20px;align-items:start">
        <div class="pa-avatar" style="width:64px;height:64px;font-size:21px">{{ mb_strtoupper(mb_substr($pharmacy->name, 0, 2)) }}</div>
        <div style="min-width:0">
          <div class="pa-row" style="flex-wrap:wrap;margin-bottom:5px">
            <a href="{{ route('pharmacies.show', $pharmacy) }}" style="font-size:18px;font-weight:900;letter-spacing:-.012em;color:var(--pa-ink)">{{ $pharmacy->name }}</a>
            <span class="pa-badge">Pharmacy</span>
          </div>
          <div style="font-size:14px;color:var(--pa-ink-soft);margin-bottom:4px">{{ $pharmacy->products_count }} products</div>
          <div style="font-size:13px;color:var(--pa-muted);margin-bottom:12px">{{ $pharmacy->address }}@if($pharmacy->city), {{ $pharmacy->city }}@endif</div>
        </div>
        <div style="text-align:right;min-width:158px">
          @if ($pharmacy->rating_count)<div class="pa-num" style="font-size:23px">{{ number_format($pharmacy->rating_avg, 1) }}</div>@endif
          <div style="font-size:12px;color:var(--pa-muted);margin-bottom:12px">Delivery R {{ number_format($pharmacy->delivery_fee, 0) }}</div>
          <a class="pa-btn pa-btn-block" href="{{ route('pharmacies.show', $pharmacy) }}">Order now</a>
        </div>
      </div>
    @empty
      <p class="pa-muted">No pharmacies found.</p>
    @endforelse
  </div>
  <div style="margin-top:32px">{{ $pharmacies->links() }}</div>
</div>
</x-layout>
