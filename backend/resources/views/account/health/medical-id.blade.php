<x-layout title="Medical ID · Prompt Aid">
@push('meta')<link rel="stylesheet" href="{{ asset('assets/css/pa-health.css') }}" />@endpush
<div class="pa-account">
<x-account-nav active="medical-id" />
<div>
  <div class="pa-spread pa-noprint" style="margin-bottom:22px">
    <div><h1 style="font-size:32px">Medical ID</h1><p class="pa-muted" style="margin:6px 0 0">Your key health information on one card. Print it or keep it on your phone.</p></div>
    <button class="pa-btn" type="button" onclick="window.print()">Print card</button>
  </div>

  <div class="pa-noprint">@include('account.health._flash')</div>

  <div class="pa-mid" style="margin-bottom:28px">
    <div class="pa-mid-head"><b>✚ Medical ID</b><span style="font-size:12px;opacity:.9">Prompt Aid</span></div>
    <div class="pa-mid-body">
      @if ($card['photo_url'])
        <img class="pa-mid-photo" src="{{ $card['photo_url'] }}" alt="">
      @else
        <div class="pa-mid-photo">{{ $card['initials'] }}</div>
      @endif
      <div>
        <div style="font-size:24px;font-weight:900">{{ $card['name'] }}</div>
        <div class="pa-muted" style="font-size:14px">
          @if($card['dob']){{ \Carbon\Carbon::parse($card['dob'])->format('d M Y') }} ({{ $card['age'] }} yrs)@endif
          @if($card['gender']) · {{ ucfirst($card['gender']) }}@endif
          @if($card['phone']) · {{ $card['phone'] }}@endif
        </div>
        <div class="pa-mid-row">
          <div><div class="k">Blood group</div><div class="v">@if($card['blood_group'])<span class="pa-mid-blood">{{ $card['blood_group'] }}</span>@else<span class="pa-muted">Not set</span>@endif</div></div>
          <div><div class="k">Allergies</div><div class="v" style="color:{{ $card['allergies'] ? 'var(--pa-signal-ink)' : 'inherit' }}">{{ $card['allergies'] ?: 'None recorded' }}</div></div>
          <div><div class="k">Chronic conditions</div><div class="v">{{ $card['chronic_conditions'] ?: 'None recorded' }}</div></div>
        </div>
        <div class="pa-mid-row">
          <div style="grid-column:1/-1"><div class="k">Current medications</div>
            <div class="v">
              @forelse ($card['medications'] as $med)
                <div>{{ $med['name'] }} <span class="pa-muted" style="font-weight:400">— {{ $med['dose'] }} at {{ implode(', ', $med['times']) }}</span></div>
              @empty
                None recorded
              @endforelse
            </div>
          </div>
        </div>
        <div class="pa-mid-row">
          <div><div class="k">Emergency contact</div><div class="v">{{ $card['emergency_contact']['name'] ?: 'Not set' }}@if($card['emergency_contact']['phone'])<div style="font-weight:400">{{ $card['emergency_contact']['phone'] }}</div>@endif</div></div>
          <div><div class="k">Medical aid</div><div class="v">
            @if ($card['medical_aid'])
              {{ $card['medical_aid']['scheme'] }}<div style="font-weight:400">No. {{ $card['medical_aid']['member_number'] }}@if($card['medical_aid']['dependant_code']) · dep {{ $card['medical_aid']['dependant_code'] }}@endif</div>
            @else
              None / private
            @endif
          </div></div>
          @if ($card['emergency_numbers'])
            <div><div class="k">Emergency numbers</div><div class="v" style="font-weight:400">
              @foreach (array_slice($card['emergency_numbers'], 0, 3) as $num)
                <div><b>{{ $num['phone'] }}</b> {{ $num['label'] }}</div>
              @endforeach
            </div></div>
          @endif
        </div>
      </div>
    </div>
    <div class="pa-mid-foot">{{ $card['note'] }}</div>
  </div>

  <form method="POST" action="{{ route('account.health.medical-id.update') }}" class="pa-noprint">
    @csrf
    <div class="pa-card">
      <div class="pa-card-head"><h3>Edit medical details</h3></div>
      <div class="pa-card-body">
        <div class="pa-formgrid">
          <div><label class="pa-label">Blood group</label>
            <select class="pa-field" name="blood_group">
              <option value="">Unknown</option>
              @foreach ($bloodGroups as $bg)
                <option value="{{ $bg }}" @selected(old('blood_group', $patient->blood_group) === $bg)>{{ $bg }}</option>
              @endforeach
              @if ($patient->blood_group && ! in_array($patient->blood_group, $bloodGroups, true))
                <option value="{{ $patient->blood_group }}" selected>{{ $patient->blood_group }}</option>
              @endif
            </select>
          </div>
          <div><label class="pa-label">Emergency contact name</label><input class="pa-field" name="emergency_contact_name" value="{{ old('emergency_contact_name', $patient->emergency_contact_name) }}" maxlength="255"></div>
          <div><label class="pa-label">Emergency contact phone</label><input class="pa-field" name="emergency_contact_phone" value="{{ old('emergency_contact_phone', $patient->emergency_contact_phone) }}" maxlength="40"></div>
          <div style="grid-column:1/-1"><label class="pa-label">Allergies</label><input class="pa-field" name="allergies" value="{{ old('allergies', $patient->allergies) }}" maxlength="2000" placeholder="Comma separated"></div>
          <div style="grid-column:1/-1"><label class="pa-label">Chronic conditions</label><input class="pa-field" name="chronic_conditions" value="{{ old('chronic_conditions', $patient->chronic_conditions) }}" maxlength="2000" placeholder="Comma separated"></div>
        </div>
        <div style="margin-top:18px;display:flex;gap:10px;flex-wrap:wrap">
          <button class="pa-btn" type="submit">Save</button>
          @if (\App\Support\HealthSettings::feature('medications'))<a class="pa-btn-ghost" href="{{ route('account.health.medications') }}">Manage medications</a>@endif
          <a class="pa-btn-ghost" href="{{ route('account.profile') }}">Medical aid &amp; profile</a>
        </div>
      </div>
    </div>
  </form>
</div>
</div>
</x-layout>
