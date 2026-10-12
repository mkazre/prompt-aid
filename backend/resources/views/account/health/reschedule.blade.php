<x-layout title="Reschedule appointment · Prompt Aid">
@push('meta')<link rel="stylesheet" href="{{ asset('assets/css/pa-health.css') }}" />@endpush
<div class="pa-account">
<x-account-nav active="appointments" />
<div>
  <div class="pa-spread" style="margin-bottom:22px">
    <div><h1 style="font-size:32px">Reschedule</h1><p class="pa-muted" style="margin:6px 0 0">Move your appointment with {{ $appointment->doctor?->displayName() }} to another free time.</p></div>
    <a class="pa-btn-ghost" href="{{ route('account.appointments') }}">Back to appointments</a>
  </div>

  @include('account.health._flash')

  <div class="pa-card pa-card-pad" style="margin-bottom:20px">
    <div class="pa-label" style="margin-bottom:6px">Currently booked</div>
    <div style="font-size:18px;font-weight:900">{{ $appointment->date->format('l j F Y') }} at {{ substr($appointment->start_time, 0, 5) }}</div>
    <div class="pa-muted" style="font-size:14px;margin-top:4px">{{ $appointment->isVideo() ? 'Video consult' : ($appointment->clinic?->name ?? 'In person') }} · {{ $appointment->booking_ref }}</div>
  </div>

  <form method="POST" action="{{ route('account.health.reschedule.store', $appointment->id) }}" id="pa-resched" data-slots-url="{{ route('account.health.reschedule.slots', $appointment->id) }}">
    @csrf
    <div class="pa-card">
      <div class="pa-card-head"><h3>Choose a new time</h3></div>
      <div class="pa-card-body">
        <div style="max-width:260px;margin-bottom:18px">
          <label class="pa-label" for="pa-resched-date">Date</label>
          <input class="pa-field" type="date" id="pa-resched-date" name="date" min="{{ $minDate }}" max="{{ $maxDate }}" value="{{ old('date', $initialDate) }}" required>
        </div>
        <input type="hidden" name="start_time" id="pa-resched-time" value="{{ old('start_time') }}">
        <div class="pa-label" style="margin-bottom:8px">Available times</div>
        <div id="pa-resched-slots" class="pa-hl-slots" aria-live="polite"><span class="pa-muted" style="font-size:14px">Loading…</span></div>
        <div style="margin-top:20px">
          <button class="pa-btn" type="submit" id="pa-resched-submit" disabled>Move appointment</button>
        </div>
      </div>
    </div>
  </form>
</div>
</div>
@push('scripts')<script src="{{ asset('assets/js/pa-health.js') }}"></script>@endpush
</x-layout>
