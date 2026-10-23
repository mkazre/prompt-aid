<x-layout title="Your consult · Prompt Aid">
@php
  $hubAppointmentId = (int) $appointment->id;
  $videoJsUrl = asset('assets/js/pa-video-call.js').'?v=2';
  $hubJsUrl = asset('assets/js/pa-consult-hub.js').'?v=1';
@endphp
{{-- This page answers its own rings (pa-consult-ring.js skips this appointment). --}}
<script>window.PA_HUB_APPT = {{ $hubAppointmentId }};</script>
<div style="max-width:1200px;margin:0 auto;padding:20px 16px 40px">
  <div style="margin-bottom:14px">
    <div class="pa-eyebrow" style="margin-bottom:4px">Your consult</div>
    <h1 style="font-size:28px;margin:0">Connect with your doctor</h1>
  </div>
  @include('consult-hub.hub', ['hub' => $hub, 'hubConfig' => $hubConfig, 'callConfig' => $callConfig, 'role' => 'patient'])
</div>
@push('scripts')
<script src="{{ $videoJsUrl }}"></script>
<script src="{{ $hubJsUrl }}"></script>
@endpush
</x-layout>
