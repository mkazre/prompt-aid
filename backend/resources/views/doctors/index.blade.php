<x-layout :title="$heading.' · Prompt Aid'">
<div class="pa-pagehead"><div class="inner">
  <div class="pa-crumb"><a href="{{ url('/') }}">Home</a> <span style="color:#CFC8B8">/</span> @if($group)<a href="{{ route('doctors.index') }}">Find care</a> <span style="color:#CFC8B8">/</span> {{ $heading }}@else Find care @endif</div>
  <h1>{{ $heading }}</h1>
  <p class="pa-muted" style="font-size:15px;margin:6px 0 18px">{{ $description ?: number_format($doctors->total()).' practitioners across South Africa.' }}</p>
  <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px;max-width:620px">
    <input class="pa-field" name="search" value="{{ request('search') }}" placeholder="Search by doctor name or speciality, e.g. Dr Smith or cardiologist" style="flex:1 1 260px" />
    <button class="pa-btn" type="submit">Search</button>
  </form>
  <x-care-tabs :active="in_array($group, ['gp', 'dentists', 'specialists'], true) ? $group : null" />
</div></div>

<div class="pa-container pa-findcare" style="padding-top:32px;padding-bottom:80px">
  <aside class="pa-card pa-findcare-filters">
    <div class="pa-spread" style="padding:18px 20px;border-bottom:1px solid var(--pa-line)"><span class="pa-label" style="margin:0">Filters</span><a href="{{ url()->current() }}" style="font-size:12px">Clear</a></div>
    <form method="GET">
      <div style="padding:18px 20px;border-bottom:1px solid var(--pa-line)">
        <div class="pa-label" style="margin-bottom:11px">Name</div>
        <input class="pa-field" name="search" value="{{ request('search') }}" placeholder="e.g. Dr Smith" />
      </div>
      <div style="padding:18px 20px;border-bottom:1px solid var(--pa-line)">
        <div class="pa-label" style="margin-bottom:11px">Speciality</div>
        <input class="pa-field" name="specialization" value="{{ request('specialization') }}" placeholder="e.g. Cardiology" />
      </div>
      <div style="padding:18px 20px">
        <button type="submit" class="pa-btn pa-btn-block">Apply filters</button>
      </div>
    </form>
    <div style="padding:16px 20px;border-top:1px solid var(--pa-line);display:grid;gap:8px;font-size:12px;color:var(--pa-ink-soft)">
      @foreach (['green', 'orange', 'red'] as $c)
        <div><span class="pa-status is-{{ $c }}" style="font-size:10px;padding:3px 7px">{{ ucfirst($c) }}</span> {{ \App\Support\SiteContent::get("status.$c.description") }}</div>
      @endforeach
    </div>
  </aside>
  <div>
    <x-see-doctor-now />
    <div class="pa-spread" style="margin-bottom:16px">
      <div style="font-size:14px;color:var(--pa-muted)"><strong style="color:var(--pa-ink)">{{ $doctors->total() }}</strong> results</div>
    </div>
    <div class="pa-grid" style="grid-template-columns:1fr">
      @forelse ($doctors as $doctor)
        <div class="pa-listing">
          <div class="pa-avatar" style="width:64px;height:64px;font-size:21px">{{ \Illuminate\Support\Str::of($doctor->user->name)->explode(' ')->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('') }}</div>
          <div style="min-width:0">
            <div class="pa-row" style="flex-wrap:wrap;margin-bottom:5px">
              <a href="{{ route('doctors.show', $doctor) }}" style="font-size:18px;font-weight:900;letter-spacing:-.012em;color:var(--pa-ink)">{{ $doctor->displayName() }}</a>
              <span class="pa-status is-{{ $doctor->liveStatus() }}" title="{{ $doctor->liveStatusDescription() }}">{{ $doctor->liveStatusLabel() }}</span>
              @if ($doctor->offers_video)<span class="pa-badge">Video</span>@endif
            </div>
            <div style="font-size:14px;color:var(--pa-ink-soft);margin-bottom:4px">{{ $doctor->specialization }}@if($doctor->qualification) · {{ $doctor->qualification }}@endif</div>
            <div style="font-size:13px;color:var(--pa-muted);margin-bottom:12px">{{ $doctor->clinics->first()?->name ?? 'Video consultation available' }}</div>
            @if ($doctor->clinics->isNotEmpty())
              <div style="display:flex;gap:7px;flex-wrap:wrap">@foreach($doctor->clinics->take(3) as $clinic)<span class="pa-chip">{{ $clinic->name }}</span>@endforeach</div>
            @endif
          </div>
          <div class="pa-listing-side">
            <div class="pa-num" style="font-size:23px">R {{ number_format($doctor->consultation_fee, 0) }}</div>
            <div style="font-size:12px;color:var(--pa-muted);margin-bottom:12px">per consult</div>
            @if ($schedule = $doctor->availabilitySummary())<div style="font-size:12px;color:var(--pa-ink-soft);margin-bottom:10px">{{ $schedule }}</div>@endif
            <a class="pa-btn pa-btn-block" href="{{ route('doctors.show', $doctor) }}">Book</a>
            <a class="pa-btn-ghost pa-btn-block pa-btn-sm" style="margin-top:6px" href="{{ route('shuttle') }}?to={{ urlencode($doctor->displayName()) }}"><span class="pa-tick-beacon" style="width:6px;height:6px"></span>Add shuttle</a>
          </div>
        </div>
      @empty
        <p class="pa-muted">No practitioners found.</p>
      @endforelse
    </div>
    <div style="margin-top:18px">{{ $doctors->links() }}</div>

    @if ($dentalPractices->isNotEmpty())
      <h2 style="margin:40px 0 16px">Dental practices</h2>
      <div class="pa-grid" style="grid-template-columns:1fr">
        @foreach ($dentalPractices as $provider)
          <div class="pa-listing">
            <div class="pa-avatar" style="width:64px;height:64px;font-size:21px">{{ mb_strtoupper(mb_substr($provider->company_name, 0, 2)) }}</div>
            <div style="min-width:0"><a href="{{ route('allied.show', $provider) }}" style="font-size:18px;font-weight:900;color:var(--pa-ink)">{{ $provider->company_name }}</a><div style="font-size:14px;color:var(--pa-ink-soft)">{{ $provider->service_type }}</div></div>
            <div class="pa-listing-side"><a class="pa-btn pa-btn-block" href="{{ route('allied.show', $provider) }}">View practice</a></div>
          </div>
        @endforeach
      </div>
    @endif
  </div>
</div>
</x-layout>
