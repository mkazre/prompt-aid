<x-layout title="Profile & scheme · Prompt Aid">
<div class="pa-account">
<x-account-nav active="profile" />
<div>
  <div class="pa-spread" style="margin-bottom:22px">
    <div><h1 style="font-size:32px">Profile &amp; scheme</h1><p class="pa-muted" style="margin:6px 0 0">Your details, medical scheme and emergency contact.</p></div>
  </div>

  @if (session('success'))
    <div class="pa-note" style="margin-bottom:18px">{{ session('success') }}</div>
  @endif
  @if ($errors->any())
    <div class="pa-note-stop" style="margin-bottom:18px">{{ $errors->first() }}</div>
  @endif

  <form method="POST" action="{{ route('account.profile.update') }}">
    @csrf
    <div class="pa-card" style="margin-bottom:20px">
      <div class="pa-card-head"><h3>Personal details</h3></div>
      <div class="pa-card-body">
        <div class="pa-formgrid">
          <div><label class="pa-label">Full name</label><input class="pa-field" name="name" value="{{ old('name', $user->name) }}" required></div>
          <div><label class="pa-label">Email</label><input class="pa-field" value="{{ $user->email }}" disabled></div>
          <div><label class="pa-label">Mobile</label><input class="pa-field" name="phone" value="{{ old('phone', $user->phone) }}"></div>
          <div><label class="pa-label">Date of birth</label><input class="pa-field" type="date" name="dob" value="{{ old('dob', $patient->dob?->format('Y-m-d')) }}"></div>
          <div><label class="pa-label">Gender</label>
            <select class="pa-field" name="gender">
              <option value="">—</option>
              <option value="male" @selected($patient->gender === 'male')>Male</option>
              <option value="female" @selected($patient->gender === 'female')>Female</option>
              <option value="other" @selected($patient->gender === 'other')>Other</option>
            </select>
          </div>
          <div><label class="pa-label">Blood group</label><input class="pa-field" name="blood_group" value="{{ old('blood_group', $patient->blood_group) }}"></div>
          <div style="grid-column:1/-1"><label class="pa-label">Home address</label><input class="pa-field" name="address" value="{{ old('address', $patient->address) }}"></div>
        </div>
        <div style="margin-top:18px"><button class="pa-btn" type="submit">Save changes</button></div>
      </div>
    </div>

    <div class="pa-card" style="margin-bottom:20px">
      <div class="pa-card-head"><h3>Clinical flags</h3></div>
      <div class="pa-card-body">
        <div class="pa-formgrid">
          <div style="grid-column:1/-1"><label class="pa-label">Allergies</label><input class="pa-field" name="allergies" value="{{ old('allergies', $patient->allergies) }}"></div>
          <div style="grid-column:1/-1"><label class="pa-label">Chronic conditions</label><input class="pa-field" name="chronic_conditions" value="{{ old('chronic_conditions', $patient->chronic_conditions) }}"></div>
        </div>
        <div style="margin-top:18px"><button class="pa-btn" type="submit">Save changes</button></div>
      </div>
    </div>

    <div class="pa-card">
      <div class="pa-card-head"><h3>Emergency contact</h3></div>
      <div class="pa-card-body">
        <div class="pa-formgrid">
          <div><label class="pa-label">Name</label><input class="pa-field" name="emergency_contact_name" value="{{ old('emergency_contact_name', $patient->emergency_contact_name) }}"></div>
          <div><label class="pa-label">Mobile</label><input class="pa-field" name="emergency_contact_phone" value="{{ old('emergency_contact_phone', $patient->emergency_contact_phone) }}"></div>
        </div>
        <div style="margin-top:18px"><button class="pa-btn" type="submit">Save changes</button></div>
      </div>
    </div>
  </form>

  <div class="pa-card" style="margin-top:20px">
    <div class="pa-card-head"><h3>Medical scheme</h3></div>
    <div class="pa-card-body">
      <form method="POST" action="{{ route('account.profile.update') }}">
        @csrf
        <div class="pa-formgrid">
          <div>
            <label class="pa-label">Scheme</label>
            <select class="pa-field" name="medical_scheme_id">
              <option value="">No scheme</option>
              @foreach ($schemes as $scheme)
                <option value="{{ $scheme->id }}" @selected($membership?->medical_scheme_id === $scheme->id)>{{ $scheme->name }}</option>
              @endforeach
            </select>
          </div>
          <div><label class="pa-label">Member number</label><input class="pa-field" name="member_number" value="{{ old('member_number', $membership?->member_number) }}"></div>
          <div><label class="pa-label">Dependant code</label><input class="pa-field" name="dependant_code" value="{{ old('dependant_code', $membership?->dependant_code) }}"></div>
          <div><label class="pa-label">Main member</label><input class="pa-field" name="main_member_name" value="{{ old('main_member_name', $membership?->main_member_name) }}"></div>
        </div>
        <div style="margin-top:18px">
          <button class="pa-btn" type="submit">Save scheme details</button>
          @if ($membership)
            <span class="pa-badge {{ $membership->isVerified() ? 'is-go' : 'is-wait' }}" style="margin-left:10px">{{ $membership->isVerified() ? 'Verified' : 'Pending verification' }}</span>
          @endif
        </div>
      </form>
    </div>
  </div>
</div>
</div>
</x-layout>
