<x-layout title="Create your account · Prompt Aid">
<div class="pa-container" style="padding-top:56px;padding-bottom:80px;max-width:540px">
  <div class="pa-pop" style="padding:30px">
    <x-wizard-steps current="account" />
    <h3 style="margin-bottom:6px">Create your account</h3>
    <p style="font-size:14px;color:var(--pa-ink-soft);margin-bottom:18px">Free for patients. We'll ask about your medical scheme and health details next — all optional.</p>
    @if ($errors->any())
      <div class="pa-note" style="margin-bottom:16px;border-color:var(--pa-signal);color:var(--pa-signal)">{{ $errors->first() }}</div>
    @endif
    <form method="POST" action="{{ route('register.wizard.account.store') }}">
      @csrf
      <div class="pa-formrow"><label class="pa-label">Full name</label><input class="pa-field" name="name" required value="{{ old('name') }}" /></div>
      <div class="pa-formrow"><label class="pa-label">Mobile</label><input class="pa-field" name="phone" value="{{ old('phone') }}" placeholder="082 000 0000" /></div>
      <div class="pa-formrow"><label class="pa-label">Email</label><input class="pa-field" type="email" name="email" required value="{{ old('email') }}" placeholder="you@example.co.za" /></div>
      <div class="pa-formrow"><label class="pa-label">Password</label><input class="pa-field" type="password" name="password" required placeholder="At least 8 characters" /></div>
      <label class="pa-check"><input type="checkbox" required style="margin-right:6px">I consent to Prompt Aid processing my health information as set out in the <a href="{{ route('legal-popia') }}">POPIA notice</a>.</label>
      <label class="pa-check"><input type="checkbox" required style="margin-right:6px">I accept the <a href="{{ route('terms') }}">terms of use</a>.</label>
      <button type="submit" class="pa-btn pa-btn-block pa-btn-lg" style="margin-top:16px">Continue</button>
    </form>
    <div style="font-size:13px;color:var(--pa-muted);text-align:center;margin-top:16px">Already registered? <a href="{{ route('login') }}">Log in</a></div>
    <div style="font-size:13px;color:var(--pa-muted);text-align:center;margin-top:6px">Signing up as a shuttle driver? <a href="{{ route('register') }}">Use the driver form</a></div>
  </div>
</div>
</x-layout>
