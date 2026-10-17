{{-- Trip audio recorder widget (public/assets/js/trip-recorder.js).
     Usage: @include('safety.trip-recorder', ['ride' => $ride, 'role' => 'patient'|'driver', 'showList' => true])
     JS-rendered pages (driver console) can instead add
     <div data-pa-trip-recorder data-ride-id="ID" data-role="driver"></div> and load the script once. --}}
@php
    $trRole = ($role ?? 'patient') === 'driver' ? 'driver' : 'patient';
    $trBase = route('ride-recordings.index', $ride);
    $trList = ! empty($showList) ? '1' : '0';
@endphp
<div data-pa-trip-recorder data-ride-id="{{ $ride->id }}" data-role="{{ $trRole }}" data-base="{{ $trBase }}" data-show-list="{{ $trList }}"></div>
@once
<script src="{{ asset('assets/js/trip-recorder.js') }}?v=1" defer></script>
@endonce
