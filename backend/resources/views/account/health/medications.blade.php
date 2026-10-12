<x-layout title="Medications · Prompt Aid">
@push('meta')<link rel="stylesheet" href="{{ asset('assets/css/pa-health.css') }}" />@endpush
@php
  $m = $editing;
  $chip = ['taken' => ['is-go', 'Taken'], 'due' => ['is-orange', 'Due now'], 'missed' => ['is-red', 'Missed'], 'upcoming' => ['is-info', 'Upcoming']];
@endphp
<div class="pa-account">
<x-account-nav active="medications" />
<div>
  <div class="pa-spread" style="margin-bottom:22px">
    <div><h1 style="font-size:32px">Medications</h1><p class="pa-muted" style="margin:6px 0 0">Your medicines and daily doses.@if($remindersOn) We’ll remind you at each dose time.@endif</p></div>
    <a class="pa-btn" href="#med-form">Add medication</a>
  </div>

  @include('account.health._flash')

  <div class="pa-card" style="margin-bottom:24px">
    <div class="pa-card-head"><h3>Today’s doses</h3><span class="pa-muted" style="font-size:13px">{{ \Carbon\Carbon::parse($localToday)->format('l j F') }}</span></div>
    <div class="pa-hl-doses">
      @forelse ($today as $dose)
        <div class="pa-hl-dose">
          <div class="t">{{ $dose['time'] }}</div>
          <div class="n"><b>{{ $dose['name'] }}</b><span>{{ $dose['dose'] }}@if($dose['taken_at']) · taken {{ $dose['taken_at'] }}@endif</span></div>
          <span class="pa-hl-chip {{ $chip[$dose['status']][0] }}">{{ $chip[$dose['status']][1] }}</span>
          <form method="POST" action="{{ route('account.health.medications.taken', $dose['medication_id']) }}" class="pa-hl-inline">
            @csrf
            <input type="hidden" name="time" value="{{ $dose['time'] }}">
            @if ($dose['status'] === 'taken')
              <input type="hidden" name="undo" value="1">
              <button class="pa-btn-ghost pa-btn-sm" type="submit">Undo</button>
            @else
              <button class="pa-btn pa-btn-sm" type="submit">Taken</button>
            @endif
          </form>
        </div>
      @empty
        <div style="padding:18px 26px;font-size:14px" class="pa-muted">No doses scheduled today.</div>
      @endforelse
    </div>
  </div>

  <div class="pa-tablewrap" style="margin-bottom:24px"><table class="pa-table">
    <thead><tr><th>Medication</th><th>Times</th><th>Course</th><th>Reminders</th><th></th></tr></thead>
    <tbody>
      @forelse ($medications as $med)
        @php $ended = $med->end_date && $med->end_date->toDateString() < $localToday; @endphp
        <tr style="{{ $ended ? 'opacity:.6' : '' }}">
          <td><div class="t-main">{{ $med->name }}</div><div class="t-sub">{{ $med->dose }}@if($med->notes) · {{ \Illuminate\Support\Str::limit($med->notes, 60) }}@endif</div></td>
          <td>{{ implode(', ', $med->doseTimes()) }}</td>
          <td>{{ $med->start_date->format('d M Y') }} – {{ $med->end_date ? $med->end_date->format('d M Y') : 'ongoing' }}@if($ended) <span class="pa-badge">Ended</span>@endif</td>
          <td>{!! $med->reminders_enabled ? '<span class="pa-hl-chip is-go">On</span>' : '<span class="pa-hl-chip">Off</span>' !!}</td>
          <td class="t-right" style="white-space:nowrap">
            <a class="pa-btn-ghost pa-btn-sm" href="{{ route('account.health.medications', ['edit' => $med->id]) }}#med-form">Edit</a>
            <form method="POST" action="{{ route('account.health.medications.destroy', $med->id) }}" class="pa-hl-inline" onsubmit="return confirm('Remove this medication?');">
              @csrf @method('DELETE')
              <button class="pa-btn-ghost pa-btn-sm" type="submit">Remove</button>
            </form>
          </td>
        </tr>
      @empty
        <tr><td colspan="5"><p class="pa-muted" style="padding:24px;text-align:center;font-size:14px">No medications yet.</p></td></tr>
      @endforelse
    </tbody>
  </table></div>

  <form method="POST" id="med-form" action="{{ $m ? route('account.health.medications.update', $m->id) : route('account.health.medications.store') }}" style="margin-bottom:24px">
    @csrf
    @if ($m) @method('PUT') @endif
    <div class="pa-card">
      <div class="pa-card-head"><h3>{{ $m ? 'Edit '.$m->name : 'Add a medication' }}</h3></div>
      <div class="pa-card-body">
        <div class="pa-formgrid">
          <div><label class="pa-label">Medication</label><input class="pa-field" name="name" value="{{ old('name', $m?->name) }}" required maxlength="255" placeholder="e.g. Metformin"></div>
          <div><label class="pa-label">Dose</label><input class="pa-field" name="dose" value="{{ old('dose', $m?->dose) }}" required maxlength="120" placeholder="e.g. 1 tablet (500 mg)"></div>
          <div><label class="pa-label">Times per day (HH:MM, comma separated)</label><input class="pa-field" name="times" value="{{ old('times', $m ? implode(', ', $m->doseTimes()) : '08:00, 20:00') }}" required pattern="\s*\d{1,2}:\d{2}(\s*[,; ]\s*\d{1,2}:\d{2})*\s*"></div>
          <div><label class="pa-label">Start date</label><input class="pa-field" type="date" name="start_date" value="{{ old('start_date', $m?->start_date?->format('Y-m-d') ?? $localToday) }}" required></div>
          <div><label class="pa-label">End date (optional)</label><input class="pa-field" type="date" name="end_date" value="{{ old('end_date', $m?->end_date?->format('Y-m-d')) }}"></div>
          <div style="display:flex;align-items:flex-end"><label style="display:flex;gap:8px;align-items:center;font-size:14px;font-weight:700"><input type="checkbox" name="reminders_enabled" value="1" @checked(old('reminders_enabled', $m ? $m->reminders_enabled : true))> Remind me at each time</label></div>
          <div style="grid-column:1/-1"><label class="pa-label">Notes</label><input class="pa-field" name="notes" value="{{ old('notes', $m?->notes) }}" maxlength="2000" placeholder="e.g. Take with food"></div>
        </div>
        <div style="margin-top:18px;display:flex;gap:10px">
          <button class="pa-btn" type="submit">{{ $m ? 'Save changes' : 'Add medication' }}</button>
          @if ($m)<a class="pa-btn-ghost" href="{{ route('account.health.medications') }}">Cancel</a>@endif
        </div>
      </div>
    </div>
  </form>

  <div class="pa-card">
    <div class="pa-card-head"><h3>Taken log</h3><span class="pa-muted" style="font-size:13px">Last 14 days</span></div>
    @forelse ($takenLog as $log)
      <div class="pa-spread" style="padding:10px 26px;border-bottom:1px solid var(--pa-line-soft);font-size:14px">
        <span><b>{{ $log->medication?->name }}</b> <span class="pa-muted">{{ $log->medication?->dose }}</span></span>
        <span class="pa-num">{{ $log->dose_date->format('d M') }} · {{ $log->dose_time }} <span class="pa-muted">(taken {{ $log->taken_at->copy()->setTimezone(\App\Support\HealthSettings::TIMEZONE)->format('H:i') }})</span></span>
      </div>
    @empty
      <div style="padding:16px 26px;font-size:14px" class="pa-muted">Nothing marked as taken yet.</div>
    @endforelse
  </div>
</div>
</div>
</x-layout>
