<x-layout :title="$provider->company_name.' · Prompt Aid'">
<div class="pa-pagehead"><div class="inner">
  <div class="pa-crumb"><a href="{{ url('/') }}">Home</a> <span style="color:#CFC8B8">/</span> <a href="{{ route('specialists.index') }}">Physio &amp; specialists</a> <span style="color:#CFC8B8">/</span> {{ $provider->company_name }}</div>
  <div style="display:grid;grid-template-columns:160px minmax(0,1fr);gap:28px;padding-bottom:28px">
    <div class="pa-avatar" style="width:160px;height:160px;font-size:40px">{{ mb_strtoupper(mb_substr($provider->company_name, 0, 2)) }}</div>
    <div>
      <div class="pa-row" style="flex-wrap:wrap;margin-bottom:12px">
        <span class="pa-badge {{ $provider->isActive() ? 'is-go' : '' }}">{{ $provider->isActive() ? 'Accepting patients' : 'Not accepting patients' }}</span>
        @if ($provider->accepts_walk_ins)<span class="pa-badge is-wait">Walk-in</span>@endif
      </div>
      <h1 style="margin-bottom:8px">{{ $provider->company_name }}</h1>
      <p style="font-size:17px;color:var(--pa-ink-soft);margin-bottom:18px">{{ $provider->service_type }}@if($provider->license_no) · HPCSA {{ $provider->license_no }}@endif</p>
      <div style="display:flex;gap:32px;flex-wrap:wrap;padding-top:18px;border-top:1px solid var(--pa-line)">
        @if ($provider->rating_count)
          <div><div class="pa-label" style="margin-bottom:4px">Rating</div><div class="pa-num" style="font-size:22px">{{ number_format($provider->rating_avg, 1) }} <span style="font-size:14px;color:var(--pa-muted);font-weight:400">/ {{ $provider->rating_count }}</span></div></div>
        @endif
        <div><div class="pa-label" style="margin-bottom:4px">Category</div><div class="pa-num" style="font-size:22px">{{ ucfirst($provider->category) }}</div></div>
      </div>
    </div>
  </div>
</div></div>
<div class="pa-container" style="padding-top:32px;padding-bottom:80px;display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:32px;align-items:start">
  <div>
    <div class="pa-card pa-card-pad" style="margin-bottom:24px">
      <h3 style="margin-bottom:12px">About</h3>
      <p style="font-size:15px;line-height:1.65;color:var(--pa-ink-soft);margin:0">{{ $provider->description ?: 'No description provided yet.' }}</p>
    </div>
    @if ($provider->service_area)
      <div class="pa-card pa-card-pad">
        <h3 style="margin-bottom:12px">Service area</h3>
        <div style="display:flex;gap:7px;flex-wrap:wrap">
          @foreach ($provider->service_area as $area)<span class="pa-chip">{{ $area }}</span>@endforeach
        </div>
      </div>
    @endif
  </div>
  <aside class="pa-card pa-card-pad">
    <h3 style="margin-bottom:12px">Get in touch</h3>
    <p class="pa-muted" style="font-size:13px;margin-bottom:16px">Contact {{ $provider->company_name }} directly, or ask your doctor to refer you.</p>
    @if ($provider->user?->phone)<div style="font-size:14px;margin-bottom:6px">{{ $provider->user->phone }}</div>@endif
    @if ($provider->user?->email)<div style="font-size:14px;margin-bottom:16px">{{ $provider->user->email }}</div>@endif
    <a class="pa-btn-ghost pa-btn-block" href="{{ route('shuttle') }}">Add a shuttle</a>
  </aside>
</div>
</x-layout>
