<x-layout title="Clinics · Prompt Aid">
<div class="pa-pagehead"><div class="inner">
  <div class="pa-crumb"><a href="{{ url('/') }}">Home</a> <span style="color:#CFC8B8">/</span> Clinics</div>
  <h1>Clinics</h1><p class="pa-muted" style="font-size:15px;margin:6px 0 24px">{{ number_format($clinics->total()) }} day hospitals, family practices and walk-in rooms.</p>
  <div class="pa-tabs" style="gap:2px">
    <a class="pa-tab" href="{{ route('doctors.index') }}" style="border-bottom:0">All providers</a>
    <a class="pa-tab" href="{{ route('doctors.index') }}" style="border-bottom:0">Doctors</a>
    <a class="pa-tab is-on" href="{{ route('clinics.index') }}" style="border-bottom:0">Clinics</a>
    <a class="pa-tab" href="{{ route('pharmacies.index') }}" style="border-bottom:0">Pharmacies</a>
    <a class="pa-tab" href="{{ route('labs.index') }}" style="border-bottom:0">Labs &amp; imaging</a>
    <a class="pa-tab" href="{{ route('specialists.index') }}" style="border-bottom:0">Physio &amp; specialists</a>
  </div>
</div></div>
<div class="pa-container" style="padding-top:32px;padding-bottom:80px">
  <form method="GET" style="margin-bottom:16px"><input class="pa-field" name="search" value="{{ request('search') }}" placeholder="Search clinics…" style="max-width:360px" /></form>
  <div class="pa-spread" style="margin-bottom:16px">
    <div style="font-size:14px;color:var(--pa-muted)"><strong style="color:var(--pa-ink)">{{ $clinics->total() }}</strong> results</div>
  </div>
  <div class="pa-grid" style="grid-template-columns:1fr">
    @forelse ($clinics as $clinic)
      <div style="background:var(--pa-surface);padding:22px 24px;display:grid;grid-template-columns:64px minmax(0,1fr) auto;gap:20px;align-items:start">
        <div class="pa-avatar" style="width:64px;height:64px;font-size:21px">{{ mb_strtoupper(mb_substr($clinic->name, 0, 2)) }}</div>
        <div style="min-width:0">
          <div class="pa-row" style="flex-wrap:wrap;margin-bottom:5px">
            <a href="{{ route('clinics.show', $clinic) }}" style="font-size:18px;font-weight:900;letter-spacing:-.012em;color:var(--pa-ink)">{{ $clinic->name }}</a>
            <span class="pa-badge">Clinic</span>
          </div>
          <div style="font-size:14px;color:var(--pa-ink-soft);margin-bottom:4px">{{ $clinic->doctors_count }} {{ Str::plural('doctor', $clinic->doctors_count) }}</div>
          <div style="font-size:13px;color:var(--pa-muted);margin-bottom:12px">{{ $clinic->address }}@if($clinic->city), {{ $clinic->city }}@endif</div>
        </div>
        <div style="text-align:right;min-width:158px">
          <a class="pa-btn pa-btn-block" href="{{ route('clinics.show', $clinic) }}">View clinic</a>
          <a class="pa-btn-ghost pa-btn-block pa-btn-sm" style="margin-top:6px" href="{{ route('shuttle') }}"><span class="pa-tick-beacon" style="width:6px;height:6px"></span>Add shuttle</a>
        </div>
      </div>
    @empty
      <p class="pa-muted">No clinics found.</p>
    @endforelse
  </div>
  <div style="margin-top:32px">{{ $clinics->links() }}</div>
</div>
</x-layout>
