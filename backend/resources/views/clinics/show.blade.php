<x-layout :title="$clinic->name.' · Prompt Aid'">
<div class="pa-pagehead"><div class="inner">
  <div class="pa-crumb"><a href="{{ url('/') }}">Home</a> <span style="color:#CFC8B8">/</span> <a href="{{ route('clinics.index') }}">Clinics</a> <span style="color:#CFC8B8">/</span> {{ $clinic->name }}</div>
  <div style="display:grid;grid-template-columns:160px minmax(0,1fr);gap:28px;padding-bottom:28px">
    <div class="pa-avatar" style="width:160px;height:160px;font-size:40px">{{ mb_strtoupper(mb_substr($clinic->name, 0, 2)) }}</div>
    <div>
      <div class="pa-row" style="flex-wrap:wrap;margin-bottom:12px"><span class="pa-badge is-go">{{ $clinic->doctors->count() }} {{ Str::plural('doctor', $clinic->doctors->count()) }}</span></div>
      <h1 style="margin-bottom:8px">{{ $clinic->name }}</h1>
      <p style="font-size:17px;color:var(--pa-ink-soft);margin-bottom:18px">{{ $clinic->address }}@if($clinic->city), {{ $clinic->city }}@endif</p>
      @if ($clinic->working_hours)
        <div style="display:flex;gap:32px;flex-wrap:wrap;padding-top:18px;border-top:1px solid var(--pa-line)">
          <div><div class="pa-label" style="margin-bottom:4px">Hours</div><div style="font-size:15px;font-weight:700;padding-top:4px">{{ collect($clinic->working_hours)->map(fn ($h, $d) => "$d: $h")->implode(' · ') }}</div></div>
        </div>
      @endif
    </div>
  </div>
</div></div>

<div class="pa-container" style="padding-top:32px;padding-bottom:80px;display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:32px;align-items:start">
  <div>
    @if ($clinic->description)
      <div class="pa-card pa-card-pad" style="margin-bottom:24px">
        <h3 style="margin-bottom:12px">About</h3>
        <p style="font-size:15px;line-height:1.65;color:var(--pa-ink-soft);margin:0">{{ $clinic->description }}</p>
      </div>
    @endif

    @if ($clinic->services->isNotEmpty())
      <div class="pa-card" style="margin-bottom:24px">
        <div class="pa-card-head"><h3>Services &amp; fees</h3></div>
        @foreach ($clinic->services as $service)
          <div class="pa-spread" style="padding:16px 26px;border-bottom:1px solid var(--pa-line-soft)">
            <div><div style="font-size:15px;font-weight:700">{{ $service->name }}</div>@if($service->duration_minutes)<div style="font-size:13px;color:var(--pa-muted);margin-top:2px">{{ $service->duration_minutes }} minutes</div>@endif</div>
            <div class="pa-num" style="font-size:18px">R {{ number_format($service->price, 0) }}</div>
          </div>
        @endforeach
      </div>
    @endif

    @if ($clinic->reviews->isNotEmpty())
      <div class="pa-card" style="margin-bottom:24px">
        <div class="pa-card-head"><h3>Reviews</h3><span style="font-size:13px;color:var(--pa-muted)">Verified visits only</span></div>
        @foreach ($clinic->reviews as $review)
          <div style="padding:20px 26px;border-bottom:1px solid var(--pa-line-soft)">
            <div class="pa-spread" style="margin-bottom:8px"><div style="font-size:14px;font-weight:700">{{ $review->patient->user->name }}</div><div style="font-size:13px;color:var(--pa-signal);font-weight:900">{{ number_format($review->rating, 1) }}</div></div>
            @if ($review->comment)<div style="font-size:14px;color:var(--pa-ink-soft)">{{ $review->comment }}</div>@endif
            <div style="font-size:12px;color:var(--pa-muted);margin-top:8px">{{ $review->created_at->format('j F Y') }}</div>
          </div>
        @endforeach
      </div>
    @endif

    <div class="pa-card pa-card-pad">
      <h3 style="margin-bottom:14px">Where to find us</h3>
      <div style="font-size:15px;line-height:1.6;color:var(--pa-ink-soft)">{{ $clinic->name }}<br />{{ $clinic->address }}@if($clinic->city), {{ $clinic->city }}@endif
        <div style="margin-top:14px"><a class="pa-btn-ghost pa-btn-sm" href="{{ route('shuttle') }}?to={{ urlencode($clinic->name) }}"><span class="pa-tick-beacon" style="width:6px;height:6px"></span>Book a shuttle here</a></div>
      </div>
    </div>
  </div>

  <div style="position:sticky;top:96px">
    <div class="pa-pop">
      <div style="padding:20px 22px;border-bottom:1px solid var(--pa-line)"><div class="pa-label" style="margin:0">Doctors at this clinic</div></div>
      <div style="padding:8px 0">
        @forelse ($clinic->doctors as $doctor)
          <a href="{{ route('doctors.show', $doctor) }}" style="display:flex;gap:12px;align-items:center;padding:14px 22px;color:inherit;text-decoration:none;border-bottom:1px solid var(--pa-line-soft)">
            <div class="pa-avatar" style="width:40px;height:40px;font-size:14px">{{ \Illuminate\Support\Str::of($doctor->user->name)->explode(' ')->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('') }}</div>
            <div style="min-width:0;flex:1">
              <div style="font-size:14px;font-weight:700">Dr {{ $doctor->user->name }}</div>
              <div style="font-size:12px;color:var(--pa-muted)">{{ $doctor->specialization }}</div>
            </div>
            <span class="pa-badge {{ $doctor->isAvailableForBooking() ? 'is-go' : '' }}" style="font-size:10px">{{ $doctor->isAvailableForBooking() ? 'Book' : 'Full' }}</span>
          </a>
        @empty
          <p class="pa-muted" style="padding:14px 22px;font-size:13px">No doctors listed yet.</p>
        @endforelse
      </div>
    </div>
  </div>
</div>
</x-layout>
