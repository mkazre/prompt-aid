<x-layout title="Create an account · Prompt Aid">
<div class="pa-container" style="padding-top:56px;padding-bottom:80px;display:grid;grid-template-columns:minmax(0,1fr) 440px;gap:56px;align-items:start">
  <div>
    <h1 style="margin-bottom:16px">Create an account</h1>
    <p style="font-size:17px;color:var(--pa-ink-soft);max-width:520px;margin-bottom:28px">Free for patients, always. Doctors, clinics, pharmacies and partners apply via <a href="{{ route('for-providers') }}">For Providers</a> and are verified before going live.</p>
    <div style="border-top:1px solid var(--pa-line);padding:18px 0"><div style="font-size:16px;font-weight:700;margin-bottom:5px">Book across every provider type</div><div style="font-size:14px;color:var(--pa-ink-soft)">One diary covering doctors, clinics, pharmacies, labs and specialist services.</div></div>
    <div style="border-top:1px solid var(--pa-line);padding:18px 0"><div style="font-size:16px;font-weight:700;margin-bottom:5px">Keep one record</div><div style="font-size:14px;color:var(--pa-ink-soft)">Consultations, scripts, results and invoices in a single timeline you control.</div></div>
    <div style="border-top:1px solid var(--pa-line);padding:18px 0"><div style="font-size:16px;font-weight:700;margin-bottom:5px">Add transport to any booking</div><div style="font-size:14px;color:var(--pa-ink-soft)">Outbound and return legs scheduled against the appointment time.</div></div>
    <div style="border-top:1px solid var(--pa-line);padding:18px 0"><div style="font-size:16px;font-weight:700;margin-bottom:5px">Claim without paperwork</div><div style="font-size:14px;color:var(--pa-ink-soft)">Where a provider bills direct, the claim is submitted for you.</div></div>
  </div>
  <div class="pa-pop" style="padding:30px">
    <h3 style="margin-bottom:18px">Your details</h3>
    @if ($errors->any())
      <div class="pa-note" style="margin-bottom:16px;border-color:var(--pa-signal);color:var(--pa-signal)">{{ $errors->first() }}</div>
    @endif
    <form method="POST" action="{{ route('register.store') }}">
      @csrf
      <div class="pa-formrow"><label class="pa-label">I am a</label>
        <select class="pa-field" name="role" required>
          <option value="patient">Patient</option>
          <option value="driver">Shuttle driver</option>
        </select>
      </div>
      <div class="pa-formrow"><label class="pa-label">Full name</label><input class="pa-field" name="name" required value="{{ old('name') }}" /></div>
      <div class="pa-formrow"><label class="pa-label">Mobile</label><input class="pa-field" name="phone" value="{{ old('phone') }}" placeholder="082 000 0000" /></div>
      <div class="pa-formrow"><label class="pa-label">Email</label><input class="pa-field" type="email" name="email" required value="{{ old('email') }}" placeholder="you@example.co.za" /></div>
      <div class="pa-formrow"><label class="pa-label">Password</label><input class="pa-field" type="password" name="password" required placeholder="At least 8 characters" /></div>
      <label class="pa-check"><input type="checkbox" required style="margin-right:6px">I consent to Prompt Aid processing my health information as set out in the <a href="{{ route('legal-popia') }}">POPIA notice</a>.</label>
      <label class="pa-check"><input type="checkbox" required style="margin-right:6px">I accept the <a href="{{ route('terms') }}">terms of use</a>.</label>
      <button type="submit" class="pa-btn pa-btn-block pa-btn-lg" style="margin-top:16px">Create account</button>
    </form>
    <div style="font-size:13px;color:var(--pa-muted);text-align:center;margin-top:16px">Already registered? <a href="{{ route('login') }}">Log in</a></div>
  </div>
</div>
</x-layout>
