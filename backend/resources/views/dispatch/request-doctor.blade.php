<x-layout title="Any doctor later · Prompt Aid">
@php
  $rdSpecialties = \App\Services\Doctors\DoctorMatchService::specialties();
  $rdRadiusOptions = \App\Support\DispatchSettings::radiusOptions();
  $rdPatient = auth()->user()?->patientProfile;
  $rdRadius = $rdPatient?->search_radius_km !== null ? (int) $rdPatient->search_radius_km : (int) \App\Support\DispatchSettings::defaultRadius();
  $rdLat = $rdPatient?->lat !== null ? (float) $rdPatient->lat : null;
  $rdLng = $rdPatient?->lng !== null ? (float) $rdPatient->lng : null;
@endphp
<div class="pa-account">
<x-account-nav active="doctor-now" />
<div>
  <div style="margin-bottom:22px">
    <div class="pa-eyebrow" style="margin-bottom:6px">Later today or tomorrow</div>
    <h1 style="font-size:32px">Any available doctor</h1>
    <p class="pa-muted" style="margin:6px 0 0">Choose a time window. Every doctor of that specialty who is available then (and close enough, for in-person) gets your request — the first to accept is booked for you.</p>
  </div>

  @if (session('error'))<div class="pa-note-stop" style="margin-bottom:16px" role="alert">{{ session('error') }}</div>@endif
  @if ($errors->any())<div class="pa-note-stop" style="margin-bottom:16px" role="alert">{{ $errors->first() }}</div>@endif

  @if (empty($rdSpecialties))
    <div class="pa-note">No doctors are registered yet. Please <a href="{{ route('doctors.index') }}">browse doctors</a> instead.</div>
  @else
  <form method="POST" action="{{ route('dispatch.request-doctor.store') }}" class="pa-card" id="rd-form">
    @csrf
    <div class="pa-card-body">
      <div class="pa-formgrid" style="margin-bottom:16px">
        <div>
          <label class="pa-label" for="rd-spec">Specialty</label>
          <select class="pa-field" id="rd-spec" name="specialty" required>
            @foreach ($rdSpecialties as $sp)
              <option value="{{ $sp }}" @selected(old('specialty') === $sp)>{{ $sp }}</option>
            @endforeach
          </select>
        </div>
        <div>
          <label class="pa-label" for="rd-mode">How</label>
          <select class="pa-field" id="rd-mode" name="mode">
            <option value="video" @selected(old('mode') !== 'in_person')>Video consult</option>
            <option value="in_person" @selected(old('mode') === 'in_person')>In person at their rooms</option>
          </select>
        </div>
        <div>
          <label class="pa-label" for="rd-day">Day</label>
          <select class="pa-field" id="rd-day" name="day">
            <option value="today" @selected(old('day') !== 'tomorrow')>Later today</option>
            <option value="tomorrow" @selected(old('day') === 'tomorrow')>Tomorrow</option>
          </select>
        </div>
        <div>
          <label class="pa-label">Between</label>
          <div style="display:flex;gap:8px;align-items:center"><input class="pa-field" type="time" name="from" value="{{ old('from', '14:00') }}" required> – <input class="pa-field" type="time" name="to" value="{{ old('to', '17:00') }}" required></div>
        </div>
        <div>
          <label class="pa-label" for="rd-radius">Search within (in person)</label>
          <select class="pa-field" id="rd-radius" name="radius_km">
            @foreach ($rdRadiusOptions as $km)
              <option value="{{ $km }}" @selected($km === $rdRadius)>{{ $km }} km</option>
            @endforeach
          </select>
        </div>
      </div>
      <label class="pa-label" for="rd-reason">Reason (optional)</label>
      <textarea class="pa-field" id="rd-reason" name="reason" rows="2" maxlength="500">{{ old('reason') }}</textarea>
      <input type="hidden" name="lat" id="rd-lat" value="{{ old('lat', $rdLat) }}">
      <input type="hidden" name="lng" id="rd-lng" value="{{ old('lng', $rdLng) }}">
      <p class="pa-muted" id="rd-loc" style="font-size:13px;margin:10px 0 0">{{ $rdLat !== null ? 'Using your saved address for in-person requests.' : 'For in-person requests we will ask for your location.' }}</p>
      <div style="margin-top:18px"><button type="submit" class="pa-btn pa-btn-lg">Send to available doctors</button></div>
    </div>
  </form>
  <script>
  (function () {
    var f = document.getElementById('rd-form');
    f.addEventListener('submit', function (e) {
      if (document.getElementById('rd-mode').value !== 'in_person' || document.getElementById('rd-lat').value) return;
      e.preventDefault();
      if (!navigator.geolocation) { document.getElementById('rd-loc').textContent = 'Your browser cannot share location — choose a video consult.'; return; }
      document.getElementById('rd-loc').textContent = 'Getting your location…';
      navigator.geolocation.getCurrentPosition(function (p) {
        document.getElementById('rd-lat').value = p.coords.latitude; document.getElementById('rd-lng').value = p.coords.longitude; f.submit();
      }, function () { document.getElementById('rd-loc').textContent = 'Location permission denied — allow location or choose a video consult.'; }, { timeout: 15000 });
    });
  })();
  </script>
  @endif
</div>
</div>
</x-layout>
