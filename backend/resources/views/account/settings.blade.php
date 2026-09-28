<x-layout title="Privacy & security · Prompt Aid">
<div class="pa-account">
<x-account-nav active="settings" />
<div>
  <div class="pa-spread" style="margin-bottom:22px">
    <div><h1 style="font-size:32px">Privacy &amp; security</h1><p class="pa-muted" style="margin:6px 0 0">How you sign in, and your rights over your data.</p></div>
  </div>

  @if (session('success'))
    <div class="pa-note" style="margin-bottom:18px">{{ session('success') }}</div>
  @endif
  @if ($errors->any())
    <div class="pa-note-stop" style="margin-bottom:18px">{{ $errors->first() }}</div>
  @endif

  <div class="pa-card" style="margin-bottom:20px">
    <div class="pa-card-head"><h3>Sign in</h3></div>
    <div class="pa-card-body">
      <form method="POST" action="{{ route('account.settings.password') }}">
        @csrf
        <div class="pa-formgrid">
          <div><label class="pa-label">Email</label><input class="pa-field" value="{{ $user->email }}" disabled></div>
          <div><label class="pa-label">Current password</label><input class="pa-field" type="password" name="current_password" required></div>
          <div><label class="pa-label">New password</label><input class="pa-field" type="password" name="password" required minlength="8"></div>
          <div><label class="pa-label">Confirm new password</label><input class="pa-field" type="password" name="password_confirmation" required minlength="8"></div>
        </div>
        <div style="margin-top:18px"><button class="pa-btn" type="submit">Update password</button></div>
      </form>
    </div>
  </div>

  <div class="pa-card">
    <div class="pa-card-head"><h3>Your data</h3></div>
    <div class="pa-card-body">
      <p style="font-size:15px;color:var(--pa-ink-soft)">Under POPIA you may request a copy of everything we hold, ask us to correct it, or close your account. Clinical records are kept for six years from the last entry as the HPCSA requires, even after closure.</p>
      <div style="display:flex;gap:10px;flex-wrap:wrap"><a class="pa-btn-ghost" href="{{ route('legal-popia') }}">Read the POPIA notice</a><a class="pa-btn-ghost" style="color:var(--pa-signal);border-color:var(--pa-signal)" href="{{ route('contact') }}">Request data access or account closure</a></div>
      <p class="pa-muted" style="margin-top:14px;font-size:13px">Record sharing with individual practitioners and notification preferences aren't available to manage from here yet — contact us if you need access changed.</p>
    </div>
  </div>
</div>
</div>
</x-layout>
