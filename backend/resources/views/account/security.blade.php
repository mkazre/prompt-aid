<x-layout title="Login activity · Prompt Aid">
<div class="pa-account">
<x-account-nav active="security" />
<div>
  <div class="pa-spread" style="margin-bottom:22px">
    <div><h1 style="font-size:32px">Login activity</h1><p class="pa-muted" style="margin:6px 0 0">Where and when your account was signed in. Kept for {{ $retentionDays }} days.</p></div>
  </div>

  @if (session('success'))
    <div class="pa-note" style="margin-bottom:18px">{{ session('success') }}</div>
  @endif
  @if ($errors->any())
    <div class="pa-note-stop" style="margin-bottom:18px">{{ $errors->first() }}</div>
  @endif

  <div class="pa-card" style="margin-bottom:20px">
    <div class="pa-card-head"><h3>Sign out other devices</h3></div>
    <div class="pa-card-body">
      <p style="font-size:15px;color:var(--pa-ink-soft);margin-top:0">
        Lost a phone, or signed in on a shared computer? This signs you out everywhere except this browser — the Prompt Aid app on other phones, other browsers and the staff panels.
        @if ($otherDevices > 0)
          <strong>{{ $otherDevices }} other {{ \Illuminate\Support\Str::plural('device', $otherDevices) }} or {{ \Illuminate\Support\Str::plural('session', $otherDevices) }}</strong> currently signed in.
        @else
          No other devices are signed in right now.
        @endif
      </p>
      <form method="POST" action="{{ route('account.security.logout-others') }}" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
        @csrf
        <div style="flex:1;min-width:220px"><label class="pa-label" for="sec-password">Confirm with your password</label><input class="pa-field" id="sec-password" type="password" name="password" required autocomplete="current-password"></div>
        <button class="pa-btn" type="submit">Sign out other devices</button>
      </form>
      <p class="pa-muted" style="font-size:13px;margin:12px 0 0">Don't recognise a sign-in below? Sign out other devices, then <a href="{{ route('account.settings') }}">change your password</a>.</p>
    </div>
  </div>

  <div class="pa-tablewrap"><table class="pa-table">
    <thead><tr><th>When</th><th>Where</th><th>Device</th><th>IP address</th><th>Result</th></tr></thead>
    <tbody>
      @forelse ($events as $event)
        <tr>
          <td><div class="t-main">{{ $event->created_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}</div><div class="t-sub">{{ $event->created_at?->diffForHumans() }}</div></td>
          <td>{{ $event->channelLabel() }}</td>
          <td>{{ $event->deviceLabel() }}</td>
          <td>{{ $event->ip ?? '—' }}</td>
          <td>@if ($event->success)<span class="pa-badge is-go">Signed in</span>@else<span class="pa-badge is-stop">Wrong password</span>@endif</td>
        </tr>
      @empty
        <tr><td colspan="5"><p class="pa-muted" style="padding:24px;text-align:center;font-size:14px">No sign-ins recorded yet.</p></td></tr>
      @endforelse
    </tbody>
  </table></div>
</div>
</div>
</x-layout>
