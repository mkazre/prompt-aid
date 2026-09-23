<x-layout title="Log in · Prompt Aid">
<div class="pa-container" style="padding-top:56px;padding-bottom:80px;display:grid;grid-template-columns:minmax(0,1fr) 420px;gap:56px;align-items:start">
  <div>
    <div class="pa-row" style="margin-bottom:20px"><span class="pa-tick"></span><span class="pa-eyebrow">One account, every role</span></div>
    <h1 style="margin-bottom:16px">Log in</h1>
    <p style="font-size:17px;color:var(--pa-ink-soft);max-width:520px;margin-bottom:32px">The same credentials work across the website and the mobile app. Your dashboard is built from the role attached to your account.</p>
    <div class="pa-grid" style="grid-template-columns:repeat(auto-fit,minmax(240px,1fr))">
      <div style="padding:22px"><div style="font-size:16px;font-weight:700;margin-bottom:6px">Patient</div><div style="font-size:13px;color:var(--pa-muted)">Book care, order medicine, track a shuttle and keep your record.</div></div>
      <div style="padding:22px"><div style="font-size:16px;font-weight:700;margin-bottom:6px">Doctor / Clinic / Pharmacy / Partner</div><div style="font-size:13px;color:var(--pa-muted)">Use the staff panel login for your dashboard.</div></div>
      <div style="padding:22px"><div style="font-size:16px;font-weight:700;margin-bottom:6px">Shuttle driver</div><div style="font-size:13px;color:var(--pa-muted)">Trip offers, active trip, earnings and documents — via the mobile app.</div></div>
    </div>
    <div class="pa-note" style="margin-top:24px">Clinic and platform staff use the <a href="{{ url('/staff/login') }}">staff panel</a> instead of this form.</div>
  </div>
  <div class="pa-pop" style="padding:30px">
    <h3 style="margin-bottom:18px">Sign in</h3>
    @if ($errors->any())
      <div class="pa-note" style="margin-bottom:16px;border-color:var(--pa-signal);color:var(--pa-signal)">{{ $errors->first() }}</div>
    @endif
    <form method="POST" action="{{ route('login.store') }}">
      @csrf
      <div class="pa-formrow"><label class="pa-label">Email</label><input class="pa-field" type="email" name="email" required value="{{ old('email') }}" placeholder="you@example.co.za" /></div>
      <div class="pa-formrow"><label class="pa-label">Password</label><input class="pa-field" type="password" name="password" required placeholder="••••••••" /></div>
      <div class="pa-spread" style="margin-bottom:18px">
        <label class="pa-check is-on" style="padding:0"><input type="checkbox" name="remember" value="1" checked style="margin-right:6px">Keep me signed in</label>
        <a href="{{ route('contact') }}" style="font-size:13px">Forgot password</a>
      </div>
      <button type="submit" class="pa-btn pa-btn-block pa-btn-lg">Log in</button>
    </form>
    <div class="pa-rule" style="margin:22px 0"></div>
    <div style="font-size:13px;color:var(--pa-muted);text-align:center">New here? <a href="{{ route('register') }}">Create an account</a></div>
  </div>
</div>
</x-layout>
