<x-layout title="Family members · Prompt Aid">
@push('meta')<link rel="stylesheet" href="{{ asset('assets/css/pa-health.css') }}" />@endpush
@php $m = $editing; @endphp
<div class="pa-account">
<x-account-nav active="family" />
<div>
  <div class="pa-spread" style="margin-bottom:22px">
    <div><h1 style="font-size:32px">Family members</h1><p class="pa-muted" style="margin:6px 0 0">Dependants you manage: children, partner or parents you book and care for.</p></div>
  </div>

  @include('account.health._flash')

  <div class="pa-tablewrap" style="margin-bottom:24px"><table class="pa-table">
    <thead><tr><th>Name</th><th>Relationship</th><th>Born</th><th>Medical aid</th><th>Allergies</th><th></th></tr></thead>
    <tbody>
      @forelse ($members as $member)
        <tr>
          <td><div class="t-main">{{ $member->name }}</div>@if($member->id_number)<div class="t-sub">ID {{ $member->id_number }}</div>@endif</td>
          <td>{{ $member->relationshipLabel() }}@if($member->gender) · {{ \App\Models\FamilyMember::GENDERS[$member->gender] ?? $member->gender }}@endif</td>
          <td>{{ $member->dob ? $member->dob->format('d M Y').' ('.$member->dob->age.')' : '—' }}</td>
          <td>{{ $member->medical_aid_scheme ?: '—' }}@if($member->medical_aid_number)<div class="t-sub">{{ $member->medical_aid_number }}</div>@endif</td>
          <td>@if($member->allergies)<span class="pa-hl-chip is-red">{{ \Illuminate\Support\Str::limit($member->allergies, 40) }}</span>@else — @endif</td>
          <td class="t-right" style="white-space:nowrap">
            <a class="pa-btn-ghost pa-btn-sm" href="{{ route('account.health.family', ['edit' => $member->id]) }}#member-form">Edit</a>
            <form method="POST" action="{{ route('account.health.family.destroy', $member->id) }}" class="pa-hl-inline" onsubmit="return confirm('Remove {{ addslashes($member->name) }}?');">
              @csrf @method('DELETE')
              <button class="pa-btn-ghost pa-btn-sm" type="submit">Remove</button>
            </form>
          </td>
        </tr>
      @empty
        <tr><td colspan="6"><p class="pa-muted" style="padding:24px;text-align:center;font-size:14px">No family members yet. Add one below.</p></td></tr>
      @endforelse
    </tbody>
  </table></div>

  <form method="POST" id="member-form" action="{{ $m ? route('account.health.family.update', $m->id) : route('account.health.family.store') }}">
    @csrf
    @if ($m) @method('PUT') @endif
    <div class="pa-card">
      <div class="pa-card-head"><h3>{{ $m ? 'Edit '.$m->name : 'Add a family member' }}</h3></div>
      <div class="pa-card-body">
        <div class="pa-formgrid">
          <div><label class="pa-label">Full name</label><input class="pa-field" name="name" value="{{ old('name', $m?->name) }}" required maxlength="255"></div>
          <div><label class="pa-label">Relationship</label>
            <select class="pa-field" name="relationship" required>
              @foreach (\App\Models\FamilyMember::RELATIONSHIPS as $key => $label)
                <option value="{{ $key }}" @selected(old('relationship', $m?->relationship) === $key)>{{ $label }}</option>
              @endforeach
            </select>
          </div>
          <div><label class="pa-label">Date of birth</label><input class="pa-field" type="date" name="dob" max="{{ now()->toDateString() }}" value="{{ old('dob', $m?->dob?->format('Y-m-d')) }}"></div>
          <div><label class="pa-label">Gender</label>
            <select class="pa-field" name="gender">
              <option value="">—</option>
              @foreach (\App\Models\FamilyMember::GENDERS as $key => $label)
                <option value="{{ $key }}" @selected(old('gender', $m?->gender) === $key)>{{ $label }}</option>
              @endforeach
            </select>
          </div>
          <div><label class="pa-label">ID number (optional)</label><input class="pa-field" name="id_number" value="{{ old('id_number', $m?->id_number) }}" maxlength="40"></div>
          <div><label class="pa-label">Medical aid scheme (optional)</label><input class="pa-field" name="medical_aid_scheme" value="{{ old('medical_aid_scheme', $m?->medical_aid_scheme) }}" maxlength="255"></div>
          <div><label class="pa-label">Medical aid number (optional)</label><input class="pa-field" name="medical_aid_number" value="{{ old('medical_aid_number', $m?->medical_aid_number) }}" maxlength="60"></div>
          <div style="grid-column:1/-1"><label class="pa-label">Allergies</label><input class="pa-field" name="allergies" value="{{ old('allergies', $m?->allergies) }}" placeholder="e.g. Penicillin, peanuts" maxlength="2000"></div>
        </div>
        <div style="margin-top:18px;display:flex;gap:10px">
          <button class="pa-btn" type="submit">{{ $m ? 'Save changes' : 'Add family member' }}</button>
          @if ($m)<a class="pa-btn-ghost" href="{{ route('account.health.family') }}">Cancel</a>@endif
        </div>
      </div>
    </div>
  </form>
</div>
</div>
</x-layout>
