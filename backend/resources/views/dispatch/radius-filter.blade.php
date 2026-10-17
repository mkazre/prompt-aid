{{-- "Near me within X km" filter for listings (doctors, clinics, pharmacies). GET ?lat&lng&radius_km;
     keeps the page's other query params. Uses the browser location once, on request. --}}
@php
  $rfOptions = \App\Support\DispatchSettings::radiusOptions();
  $rfPref = auth()->user()?->patientProfile?->search_radius_km;
  $rfCurrent = request('radius_km') !== null ? (int) request('radius_km') : ($rfPref !== null ? (int) $rfPref : (int) \App\Support\DispatchSettings::defaultRadius());
  $rfActive = request()->filled('radius_km') && request()->filled('lat');
  $rfKeep = request()->except(['lat', 'lng', 'radius_km', 'page']);
  $rfClear = url()->current().($rfKeep ? '?'.http_build_query($rfKeep) : '');
@endphp
<form method="GET" class="pa-radius-filter" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0 0 16px" data-pa-radius>
  @foreach ($rfKeep as $k => $v)
    @if (is_scalar($v))<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif
  @endforeach
  <input type="hidden" name="lat" value="{{ request('lat') }}">
  <input type="hidden" name="lng" value="{{ request('lng') }}">
  <label class="pa-label" style="margin:0" for="pa-rf-km">Near me within</label>
  <select class="pa-field" id="pa-rf-km" name="radius_km" style="max-width:110px">
    @foreach ($rfOptions as $km)
      <option value="{{ $km }}" @selected($km === $rfCurrent)>{{ $km }} km</option>
    @endforeach
  </select>
  <button type="submit" class="pa-btn-ghost pa-btn-sm">{{ $rfActive ? 'Update' : 'Use my location' }}</button>
  @if ($rfActive)<a href="{{ $rfClear }}" style="font-size:13px">Show all</a>@endif
  <span class="pa-muted" style="font-size:12px" data-pa-radius-msg></span>
</form>
<script>
(function () {
  document.querySelectorAll('form[data-pa-radius]').forEach(function (f) {
    if (f.__bound) return; f.__bound = true;
    f.addEventListener('submit', function (e) {
      if (f.lat.value && f.lng.value) return;
      e.preventDefault();
      var msg = f.querySelector('[data-pa-radius-msg]');
      if (!navigator.geolocation) { msg.textContent = 'Your browser cannot share location.'; return; }
      msg.textContent = 'Finding your location…';
      navigator.geolocation.getCurrentPosition(function (p) { f.lat.value = p.coords.latitude.toFixed(5); f.lng.value = p.coords.longitude.toFixed(5); f.submit(); },
        function () { msg.textContent = 'Location permission denied.'; }, { timeout: 15000, maximumAge: 300000 });
    });
  });
})();
</script>
