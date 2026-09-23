<x-layout title="Find a doctor · Prompt Aid">
<div class="pa-pagehead"><div class="inner">
  <div class="pa-crumb"><a href="{{ url('/') }}">Home</a> <span style="color:#CFC8B8">/</span> Find care</div>
  <h1>Find a doctor</h1><p class="pa-muted" style="font-size:15px;margin:6px 0 24px">{{ number_format($doctors->total()) }} doctors across South Africa.</p>
  <div class="pa-tabs" style="gap:2px">
    <a class="pa-tab is-on" href="{{ route('doctors.index') }}" style="border-bottom:0">All providers</a>
    <a class="pa-tab" href="{{ route('doctors.index') }}" style="border-bottom:0">Doctors</a>
    <a class="pa-tab" href="{{ route('clinics.index') }}" style="border-bottom:0">Clinics</a>
    <a class="pa-tab" href="{{ route('pharmacies.index') }}" style="border-bottom:0">Pharmacies</a>
    <a class="pa-tab" href="{{ route('labs.index') }}" style="border-bottom:0">Labs &amp; imaging</a>
    <a class="pa-tab" href="{{ route('specialists.index') }}" style="border-bottom:0">Physio &amp; specialists</a>
  </div>
</div></div>

<div class="pa-container" style="padding-top:32px;padding-bottom:80px;display:grid;grid-template-columns:268px minmax(0,1fr);gap:32px;align-items:start">
  <aside class="pa-card" style="position:sticky;top:96px">
    <div class="pa-spread" style="padding:18px 20px;border-bottom:1px solid var(--pa-line)"><span class="pa-label" style="margin:0">Filters</span><a href="{{ route('doctors.index') }}" style="font-size:12px">Clear</a></div>
    <form method="GET">
      <div style="padding:18px 20px;border-bottom:1px solid var(--pa-line)">
        <div class="pa-label" style="margin-bottom:11px">Name or symptom</div>
        <input class="pa-field" name="search" value="{{ request('search') }}" placeholder="e.g. Naledi, persistent cough" />
      </div>
      <div style="padding:18px 20px;border-bottom:1px solid var(--pa-line)">
        <div class="pa-label" style="margin-bottom:11px">Speciality</div>
        <input class="pa-field" name="specialization" value="{{ request('specialization') }}" placeholder="e.g. Cardiology" />
      </div>
      <div style="padding:18px 20px">
        <button type="submit" class="pa-btn pa-btn-block">Apply filters</button>
      </div>
    </form>
  </aside>
  <div>
    <div class="pa-spread" style="margin-bottom:16px">
      <div style="font-size:14px;color:var(--pa-muted)"><strong style="color:var(--pa-ink)">{{ $doctors->total() }}</strong> results</div>
    </div>
    <div class="pa-grid" style="grid-template-columns:1fr">
      @forelse ($doctors as $doctor)
        @php $schedule = $doctor->availabilitySummary(); @endphp
        <div style="background:var(--pa-surface);padding:22px 24px;display:grid;grid-template-columns:64px minmax(0,1fr) auto;gap:20px;align-items:start">
          <div class="pa-avatar" style="width:64px;height:64px;font-size:21px">{{ \Illuminate\Support\Str::of($doctor->user->name)->explode(' ')->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('') }}</div>
          <div style="min-width:0">
            <div class="pa-row" style="flex-wrap:wrap;margin-bottom:5px">
              <a href="{{ route('doctors.show', $doctor) }}" style="font-size:18px;font-weight:900;letter-spacing:-.012em;color:var(--pa-ink)">Dr {{ $doctor->user->name }}</a>
              <span class="pa-badge">Doctor</span>
            </div>
            <div style="font-size:14px;color:var(--pa-ink-soft);margin-bottom:4px">{{ $doctor->specialization }}@if($doctor->qualification) · {{ $doctor->qualification }}@endif</div>
            <div style="font-size:13px;color:var(--pa-muted);margin-bottom:12px">{{ $doctor->clinics->first()?->name ?? 'Video consultation available' }}</div>
            @if ($doctor->clinics->isNotEmpty())
              <div style="display:flex;gap:7px;flex-wrap:wrap">@foreach($doctor->clinics->take(3) as $clinic)<span class="pa-chip">{{ $clinic->name }}</span>@endforeach</div>
            @endif
          </div>
          <div style="text-align:right;min-width:158px">
            <div class="pa-num" style="font-size:23px">R {{ number_format($doctor->consultation_fee, 0) }}</div>
            <div style="font-size:12px;color:var(--pa-muted);margin-bottom:12px">per consult</div>
            <div style="font-size:12px;font-weight:900;color:{{ $doctor->isAvailableForBooking() ? 'var(--pa-go)' : 'var(--pa-muted)' }};margin-bottom:10px">{{ $doctor->isAvailableForBooking() ? ($schedule ?? 'Accepting bookings') : 'Not accepting bookings' }}</div>
            <a class="pa-btn pa-btn-block" href="{{ route('doctors.show', $doctor) }}">Book</a>
            <a class="pa-btn-ghost pa-btn-block pa-btn-sm" style="margin-top:6px" href="{{ route('shuttle') }}"><span class="pa-tick-beacon" style="width:6px;height:6px"></span>Add shuttle</a>
          </div>
        </div>
      @empty
        <p class="pa-muted">No doctors found.</p>
      @endforelse
    </div>
    <div style="margin-top:18px">{{ $doctors->links() }}</div>
  </div>
</div>
</x-layout>
