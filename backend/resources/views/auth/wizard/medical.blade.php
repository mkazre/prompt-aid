<x-layout title="Medical details · Prompt Aid">
<div class="pa-container" style="padding-top:56px;padding-bottom:80px;max-width:540px">
  <div class="pa-pop" style="padding:30px">
    <x-wizard-steps current="medical" />
    <h3 style="margin-bottom:6px">Medical details</h3>
    <p style="font-size:14px;color:var(--pa-ink-soft);margin-bottom:18px">Optional, but helps a doctor treat you faster in an emergency. You can also add or change this later from your profile.</p>
    @if ($errors->any())
      <div class="pa-note" style="margin-bottom:16px;border-color:var(--pa-signal);color:var(--pa-signal)">{{ $errors->first() }}</div>
    @endif
    <form method="POST" action="{{ route('register.wizard.medical.store') }}">
      @csrf
      <div class="pa-formrow"><label class="pa-label">Allergies</label><input class="pa-field" name="allergies" value="{{ old('allergies') }}" placeholder="e.g. Penicillin" /></div>
      <div class="pa-formrow"><label class="pa-label">Chronic conditions</label><input class="pa-field" name="chronic_conditions" value="{{ old('chronic_conditions') }}" placeholder="e.g. Asthma, diabetes" /></div>
      <div class="pa-formrow"><label class="pa-label">Emergency contact name</label><input class="pa-field" name="emergency_contact_name" value="{{ old('emergency_contact_name') }}" /></div>
      <div class="pa-formrow"><label class="pa-label">Emergency contact mobile</label><input class="pa-field" name="emergency_contact_phone" value="{{ old('emergency_contact_phone') }}" /></div>
      <button type="submit" class="pa-btn pa-btn-block pa-btn-lg" style="margin-top:16px">Continue</button>
    </form>
  </div>
</div>
</x-layout>
