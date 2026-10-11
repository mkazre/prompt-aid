<x-layout title="Notifications · Prompt Aid">
<div class="pa-account">
<x-account-nav active="notifications" />
<div>
  <div class="pa-spread" style="margin-bottom:22px">
    <div><h1 style="font-size:32px">Notifications</h1><p class="pa-muted" style="margin:6px 0 0">Updates about your appointments, orders, rides and messages.</p></div>
    @if ($notifications->total() > 0)
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <form method="POST" action="{{ route('account.notifications.read-all') }}">@csrf<button class="pa-btn-ghost pa-btn-sm" type="submit">Mark all read</button></form>
        <form method="POST" action="{{ route('account.notifications.clear') }}" onsubmit="return confirm('Clear all notifications?');">@csrf<button class="pa-btn-ghost pa-btn-sm" type="submit">Clear all</button></form>
      </div>
    @endif
  </div>

  @if (session('success'))
    <div class="pa-note" style="margin-bottom:18px">{{ session('success') }}</div>
  @endif

  @forelse ($notifications as $n)
    <div class="pa-notif {{ $n->read_at ? '' : 'is-unread' }}">
      <div style="min-width:0;flex:1">
        <a class="ti" href="{{ route('account.notifications.open', $n->id) }}">{{ $n->title }}</a>
        @if ($n->body)<div class="bd">{{ $n->body }}</div>@endif
        <div class="tm">{{ $n->created_at?->diffForHumans() }}</div>
      </div>
      <form method="POST" action="{{ route('account.notifications.destroy', $n->id) }}">
        @csrf
        @method('DELETE')
        <button class="x" type="submit" aria-label="Delete notification" title="Delete">&times;</button>
      </form>
    </div>
  @empty
    <div class="pa-card pa-card-pad" style="text-align:center;padding:40px">
      <p style="font-size:16px;font-weight:700;margin:0 0 6px">You're all caught up</p>
      <p class="pa-muted" style="margin:0;font-size:14px">New notifications will appear here.</p>
    </div>
  @endforelse

  @if ($notifications->hasPages())
    <div style="display:flex;justify-content:space-between;margin-top:16px">
      @if ($notifications->previousPageUrl())<a class="pa-btn-ghost pa-btn-sm" href="{{ $notifications->previousPageUrl() }}">Newer</a>@else<span></span>@endif
      @if ($notifications->nextPageUrl())<a class="pa-btn-ghost pa-btn-sm" href="{{ $notifications->nextPageUrl() }}">Older</a>@endif
    </div>
  @endif
</div>
</div>
</x-layout>
