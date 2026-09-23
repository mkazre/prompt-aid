<x-layout title="List your practice · Prompt Aid">
<section class="pa-dark">
  <div class="pa-container" style="padding-top:64px;padding-bottom:64px;max-width:900px">
    <div class="pa-row" style="margin-bottom:22px"><span class="pa-tick"></span><span class="pa-eyebrow" style="color:var(--pa-beacon)">For providers</span></div>
    <h1 style="font-size:52px;margin-bottom:20px">Fill your diary, not your admin tray.</h1>
    <p style="font-size:18px;color:#B8B3A8;max-width:620px;margin:0">Bookings, encounters, scripts, invoices and scheme claims in one place — and patients who can actually get to you.</p>
  </div>
</section>
<div class="pa-container" style="padding-top:48px;padding-bottom:80px">
  <div class="pa-grid" style="grid-template-columns:repeat(auto-fit,minmax(260px,1fr));margin-bottom:48px">
    <div style="padding:26px 24px">
      <div style="font-size:17px;font-weight:700;margin-bottom:8px">Doctor</div>
      <div style="font-size:13px;color:var(--pa-muted);margin-bottom:16px">Your day list, encounters, prescriptions and lab requests.</div>
      <a class="pa-btn-ghost pa-btn-sm" href="{{ route('login') }}">See the dashboard</a></div><div style="padding:26px 24px">
      <div style="font-size:17px;font-weight:700;margin-bottom:8px">Clinic</div>
      <div style="font-size:13px;color:var(--pa-muted);margin-bottom:16px">Practice diary, doctors, receptionists, invoices and claims.</div>
      <a class="pa-btn-ghost pa-btn-sm" href="{{ route('login') }}">See the dashboard</a></div><div style="padding:26px 24px">
      <div style="font-size:17px;font-weight:700;margin-bottom:8px">Pharmacy</div>
      <div style="font-size:13px;color:var(--pa-muted);margin-bottom:16px">Orders, script approvals, stock, deliveries and payouts.</div>
      <a class="pa-btn-ghost pa-btn-sm" href="{{ route('login') }}">See the dashboard</a></div><div style="padding:26px 24px">
      <div style="font-size:17px;font-weight:700;margin-bottom:8px">Lab / X-ray / specialist</div>
      <div style="font-size:13px;color:var(--pa-muted);margin-bottom:16px">Your request queue, collections, results and catalogue.</div>
      <a class="pa-btn-ghost pa-btn-sm" href="{{ route('login') }}">See the dashboard</a></div><div style="padding:26px 24px">
      <div style="font-size:17px;font-weight:700;margin-bottom:8px">Shuttle driver</div>
      <div style="font-size:13px;color:var(--pa-muted);margin-bottom:16px">Trip offers, active trip, earnings and documents.</div>
      <a class="pa-btn-ghost pa-btn-sm" href="{{ route('login') }}">See the dashboard</a></div>
  </div>
  <div style="display:grid;grid-template-columns:minmax(0,1fr) 400px;gap:40px;align-items:start">
    <div>
      <h2 style="margin-bottom:20px">What joining involves</h2>
      <div style="border-top:1px solid var(--pa-line);padding:18px 0"><div style="font-size:16px;font-weight:700;margin-bottom:6px">1 · Send your registration details</div><div style="font-size:14px;color:var(--pa-ink-soft)">HPCSA, SAPC or practice number, plus proof of professional indemnity where it applies.</div></div><div style="border-top:1px solid var(--pa-line);padding:18px 0"><div style="font-size:16px;font-weight:700;margin-bottom:6px">2 · We verify</div><div style="font-size:14px;color:var(--pa-ink-soft)">Usually two working days. Pharmacies and labs also need a responsible-person letter.</div></div><div style="border-top:1px solid var(--pa-line);padding:18px 0"><div style="font-size:16px;font-weight:700;margin-bottom:6px">3 · Set up your profile</div><div style="font-size:14px;color:var(--pa-ink-soft)">Services, fees, rooms, hours and the schemes you bill directly. We can import from an existing system.</div></div><div style="border-top:1px solid var(--pa-line);padding:18px 0"><div style="font-size:16px;font-weight:700;margin-bottom:6px">4 · Go live</div><div style="font-size:14px;color:var(--pa-ink-soft)">Your archive and detail pages publish automatically, built from the site templates.</div></div>
    </div>
    <div class="pa-pop" style="padding:26px">
      <h3 style="margin-bottom:16px">Register your interest</h3>
      @if (session('success'))
        <div class="pa-note" style="margin-bottom:16px;border-color:var(--pa-go);color:var(--pa-go)">{{ session('success') }}</div>
      @endif
      <form method="POST" action="{{ route('for-providers.submit') }}">
        @csrf
        <div class="pa-formrow"><label class="pa-label">Practice or business name</label><input class="pa-field" name="business_name" required value="{{ old('business_name') }}" placeholder="Sunninghill Family Clinic" /></div>
        <div class="pa-formrow"><label class="pa-label">Type</label><select class="pa-field" name="type" required>
          <option value="Doctor in private practice">Doctor in private practice</option>
          <option value="Clinic or day hospital">Clinic or day hospital</option>
          <option value="Pharmacy">Pharmacy</option>
          <option value="Laboratory or imaging">Laboratory or imaging</option>
          <option value="Physiotherapy or specialist service">Physiotherapy or specialist service</option>
          <option value="Shuttle driver or fleet">Shuttle driver or fleet</option>
        </select></div>
        <div class="pa-formrow"><label class="pa-label">Registration number</label><input class="pa-field" name="registration_no" value="{{ old('registration_no') }}" placeholder="HPCSA / SAPC / practice no." /></div>
        <div class="pa-formrow"><label class="pa-label">Contact person</label><input class="pa-field" name="contact_name" required value="{{ old('contact_name') }}" placeholder="Full name" /></div>
        <div class="pa-formrow"><label class="pa-label">Email</label><input class="pa-field" type="email" name="email" required value="{{ old('email') }}" placeholder="you@practice.co.za" /></div>
        <div class="pa-formrow"><label class="pa-label">Mobile</label><input class="pa-field" name="phone" required value="{{ old('phone') }}" placeholder="082 000 0000" /></div>
        @error('business_name')<div style="color:var(--pa-signal);font-size:12px;margin-bottom:10px">{{ $message }}</div>@enderror
        <label class="pa-check"><input type="checkbox" name="terms_accepted" value="1" style="margin-right:6px">I accept the <a href="{{ route('terms') }}">provider terms</a> and the <a href="{{ route('legal-popia') }}">POPIA operator agreement</a>.</label>
        @error('terms_accepted')<div style="color:var(--pa-signal);font-size:12px;margin:6px 0">{{ $message }}</div>@enderror
        <button type="submit" class="pa-btn pa-btn-block pa-btn-lg" style="margin-top:16px">Send application</button>
      </form>
    </div>
  </div>
</div>
</x-layout>
