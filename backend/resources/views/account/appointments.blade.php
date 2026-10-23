<x-layout title="Appointments · Prompt Aid">
@php
  $rescheduler = app(\App\Services\Health\PatientRescheduleService::class);
  $calendarOn = \App\Support\HealthSettings::feature('calendar');
@endphp
<div class="pa-account">
<x-account-nav active="appointments" />
<div>
  <div class="pa-spread" style="margin-bottom:22px">
    <div><h1 style="font-size:32px">Appointments</h1><p class="pa-muted" style="margin:6px 0 0">Past and upcoming visits, in person and by video.</p></div>
    <div style="display:flex;gap:10px;flex-wrap:wrap"><a class="pa-btn-ghost" href="{{ route('shuttle') }}">Book a shuttle</a><a class="pa-btn" href="{{ route('doctors.index') }}">New appointment</a></div>
  </div>

  @if (session('success'))
    <div class="pa-note" style="margin-bottom:18px">{{ session('success') }}</div>
  @endif
  @if (session('error'))
    <div class="pa-note-stop" style="margin-bottom:18px">{{ session('error') }}</div>
  @endif

  <div class="pa-tablewrap"><table class="pa-table">
    <thead><tr><th>Appointment</th><th>Provider</th><th>Type</th><th>Status</th><th></th></tr></thead>
    <tbody>
      @forelse ($appointments as $appt)
        <tr>
          <td><div class="t-main">{{ $appt->date->format('d M Y') }} {{ \Illuminate\Support\Carbon::parse($appt->start_time)->format('H:i') }}</div><div class="t-sub">{{ $appt->booking_ref }}</div></td>
          <td>{{ $appt->doctor?->user?->name ?? 'Matching…' }}</td>
          <td>{{ $appt->isVideo() ? 'Video consultation' : ($appt->isCallback() ? 'Call back' : 'In person') }}{{ $appt->clinic ? ' · '.$appt->clinic->name : '' }}</td>
          <td><span class="pa-badge {{ in_array($appt->status, ['completed', 'confirmed', 'checked_in']) ? 'is-go' : (in_array($appt->status, ['cancelled', 'no_show']) ? 'is-stop' : 'is-wait') }}">{{ str($appt->status)->headline() }}</span></td>
          <td class="t-right">
            @if ($appt->isVideo() && $appt->canJoinVideoNow())
              <a class="pa-btn pa-btn-sm" href="{{ route('account.consult-hub', $appt) }}">Join consult</a>
            @elseif ($appt->isCallback() && $appt->doctor_profile_id && in_array($appt->status, ['pending', 'confirmed', 'checked_in']))
              <a class="pa-btn pa-btn-sm" href="{{ route('account.consult-hub', $appt) }}">Open consult</a>
            @endif
            @if ($appt->doctor_profile_id && $appt->status !== 'matching')
              <form method="POST" action="{{ route('account.messages.start') }}" style="display:inline">
                @csrf
                <input type="hidden" name="appointment_id" value="{{ $appt->id }}">
                <button class="pa-btn-ghost pa-btn-sm" type="submit">Message doctor</button>
              </form>
            @endif
            @if ($calendarOn && in_array($appt->status, ['pending', 'confirmed']) && $appt->doctor_profile_id && $appt->date->toDateString() >= now()->subDay()->toDateString())
              <a class="pa-btn-ghost pa-btn-sm" href="{{ route('account.health.calendar', $appt->id) }}">Add to calendar</a>
            @endif
            @if ($rescheduler->canReschedule($appt))
              <a class="pa-btn-ghost pa-btn-sm" href="{{ route('account.health.reschedule', $appt->id) }}">Reschedule</a>
            @endif
            @if (in_array($appt->status, ['pending', 'confirmed']))
              <form method="POST" action="{{ route('account.appointments.cancel', $appt) }}" style="display:inline" onsubmit="return confirm('Cancel this appointment?');">
                @csrf
                <button class="pa-btn-ghost pa-btn-sm" type="submit">Cancel</button>
              </form>
            @endif
            @if ($appt->invoice)
              <a class="pa-btn-ghost pa-btn-sm" href="{{ route('account.invoices') }}">Invoice</a>
            @endif
          </td>
        </tr>
      @empty
        <tr><td colspan="5"><p class="pa-muted" style="padding:24px;text-align:center;font-size:14px">No appointments yet. <a href="{{ route('doctors.index') }}">Book one now</a>.</p></td></tr>
      @endforelse
    </tbody>
  </table></div>
</div>
</div>
</x-layout>
