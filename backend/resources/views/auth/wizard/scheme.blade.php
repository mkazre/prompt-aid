<x-layout title="Medical scheme · Prompt Aid">
<div class="pa-container" style="padding-top:56px;padding-bottom:80px;max-width:540px">
  <div class="pa-pop" style="padding:30px">
    <x-wizard-steps current="scheme" />
    <h3 style="margin-bottom:6px">Medical scheme</h3>
    <p style="font-size:14px;color:var(--pa-ink-soft);margin-bottom:18px">Optional — paying cash? Skip this and add it later from your profile.</p>
    @if ($errors->any())
      <div class="pa-note" style="margin-bottom:16px;border-color:var(--pa-signal);color:var(--pa-signal)">{{ $errors->first() }}</div>
    @endif
    <form method="POST" action="{{ route('register.wizard.scheme.store') }}">
      @csrf
      <div class="pa-formrow"><label class="pa-label">Scheme</label>
        <select class="pa-field" name="medical_scheme_id">
          <option value="">No scheme</option>
          @foreach ($schemes as $scheme)
            <option value="{{ $scheme->id }}" @selected(old('medical_scheme_id') == $scheme->id)>{{ $scheme->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="pa-formrow"><label class="pa-label">Member number</label><input class="pa-field" name="member_number" value="{{ old('member_number') }}" /></div>
      <div class="pa-formrow"><label class="pa-label">Dependant code</label><input class="pa-field" name="dependant_code" value="{{ old('dependant_code') }}" /></div>
      <div class="pa-formrow"><label class="pa-label">Main member name</label><input class="pa-field" name="main_member_name" value="{{ old('main_member_name') }}" /></div>
      <div style="display:flex;gap:10px;margin-top:16px">
        <button type="submit" name="skip" value="1" formnovalidate class="pa-btn pa-btn-block" style="background:transparent;color:var(--pa-ink);border:1px solid var(--pa-line)">Skip for now</button>
        <button type="submit" class="pa-btn pa-btn-block pa-btn-lg">Continue</button>
      </div>
    </form>
  </div>
</div>
</x-layout>
