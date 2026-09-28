<x-layout title="Medical record · Prompt Aid">
<div class="pa-account">
<x-account-nav active="record" />
<div>
  <div class="pa-spread" style="margin-bottom:22px">
    <div><h1 style="font-size:32px">Medical record</h1><p class="pa-muted" style="margin:6px 0 0">Your clinical timeline. Only you and treating practitioners can see this.</p></div>
  </div>

  <div style="display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:24px;align-items:start">
    <div>
      @forelse ($encounters as $encounter)
        <div class="pa-card" style="margin-bottom:22px">
          <div class="pa-card-head"><h3>Encounter · {{ $encounter->created_at->format('d F Y') }}</h3><span class="pa-badge is-go">{{ $encounter->appointment->doctor->user->name }}</span></div>
          <div class="pa-card-body">
            @if ($encounter->vitals)
              <div class="pa-grid" style="grid-template-columns:repeat(auto-fit,minmax(110px,1fr));margin-bottom:22px">
                @foreach ($encounter->vitals as $label => $value)
                  <div style="padding:13px 14px"><div class="pa-label" style="margin-bottom:6px">{{ str($label)->headline() }}</div><div class="pa-num" style="font-size:19px">{{ $value }}</div></div>
                @endforeach
              </div>
            @endif
            @if ($encounter->chief_complaint)
              <div style="margin-bottom:16px"><div class="pa-label" style="margin-bottom:7px">Presenting complaint</div><div style="font-size:15px;line-height:1.6;color:var(--pa-ink-soft)">{{ $encounter->chief_complaint }}</div></div>
            @endif
            @if ($encounter->diagnosis)
              <div style="margin-bottom:16px"><div class="pa-label" style="margin-bottom:7px">Assessment</div><div style="font-size:15px;line-height:1.6;color:var(--pa-ink-soft)">{{ $encounter->diagnosis }}</div></div>
            @endif
            @if ($encounter->notes)
              <div style="margin-bottom:16px"><div class="pa-label" style="margin-bottom:7px">Notes</div><div style="font-size:15px;line-height:1.6;color:var(--pa-ink-soft)">{{ $encounter->notes }}</div></div>
            @endif
            @if ($encounter->prescription)
              <div class="pa-label" style="margin-bottom:8px">Prescribed</div>
              <div style="display:flex;gap:7px;flex-wrap:wrap">
                @foreach ($encounter->prescription->items as $item)
                  <span class="pa-badge is-ink">{{ $item->medicine_name }}</span>
                @endforeach
              </div>
            @endif
          </div>
        </div>
      @empty
        <div class="pa-card" style="margin-bottom:22px"><p class="pa-muted" style="padding:24px;text-align:center;font-size:14px">No encounter notes yet — these appear after a consultation.</p></div>
      @endforelse

      <div class="pa-card">
        <div class="pa-card-head"><h3>Timeline</h3><a class="pa-btn-ghost pa-btn-sm" href="{{ route('account.results') }}">Results only</a></div>
        @forelse ($timeline as $event)
          <div style="padding:15px 22px;border-bottom:1px solid var(--pa-line-soft);display:grid;grid-template-columns:110px 1fr;gap:16px">
            <div style="font-size:12px;color:var(--pa-muted)">{{ \Illuminate\Support\Carbon::parse($event['date'])->format('d M Y') }}</div>
            <div><div style="font-size:14px;font-weight:700">{{ $event['type'] }}</div><div style="font-size:13px;color:var(--pa-ink-soft);margin-top:2px">{{ $event['detail'] }}</div></div>
          </div>
        @empty
          <p class="pa-muted" style="padding:24px;text-align:center;font-size:14px">No activity recorded yet.</p>
        @endforelse
      </div>
    </div>
    <div>
      <div class="pa-card pa-card-pad" style="margin-bottom:20px">
        <div class="pa-label" style="margin-bottom:12px">Allergies &amp; alerts</div>
        <div style="display:flex;gap:7px;flex-wrap:wrap;margin-bottom:14px">
          @if ($patient->allergies)
            <span class="pa-badge is-stop">{{ $patient->allergies }}</span>
          @endif
          @if ($patient->chronic_conditions)
            <span class="pa-badge is-wait">{{ $patient->chronic_conditions }}</span>
          @endif
          @if (! $patient->allergies && ! $patient->chronic_conditions)
            <span class="pa-muted" style="font-size:13px">None recorded.</span>
          @endif
        </div>
        <a class="pa-btn-ghost pa-btn-block pa-btn-sm" href="{{ route('account.profile') }}">Update</a>
      </div>
      <div class="pa-card pa-card-pad">
        <div class="pa-label" style="margin-bottom:12px">Emergency contact</div>
        @if ($patient->emergency_contact_name)
          <p style="font-size:14px;color:var(--pa-ink-soft)">{{ $patient->emergency_contact_name }} · {{ $patient->emergency_contact_phone }}</p>
        @else
          <p style="font-size:14px;color:var(--pa-ink-soft)">No emergency contact on file.</p>
        @endif
        <a class="pa-btn-ghost pa-btn-block pa-btn-sm" href="{{ route('account.profile') }}">Update</a>
      </div>
    </div>
  </div>
</div>
</div>
</x-layout>
