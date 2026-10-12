<x-layout title="Help & support · Prompt Aid">
<x-support-shell>
  <div class="pa-spread" style="margin-bottom:22px;flex-wrap:wrap;gap:12px">
    <div>
      <h1 style="font-size:32px">Help &amp; support</h1>
      <p class="pa-muted" style="margin:6px 0 0;max-width:620px">{{ \App\Support\SupportSettings::str('support_intro') }}</p>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <a class="pa-btn-ghost pa-btn-sm" href="{{ route('help') }}">Browse the help centre</a>
      @if (\App\Support\SupportSettings::bool('support_tickets_enabled'))
        <a class="pa-btn pa-btn-sm" href="{{ route('account.support.create') }}">New ticket</a>
      @endif
    </div>
  </div>

  @if (session('success'))
    <div class="pa-note" style="margin-bottom:18px">{{ session('success') }}</div>
  @endif

  @if ($tickets->isEmpty())
    <div class="pa-card pa-card-pad" style="text-align:center;padding:40px">
      <p style="font-size:16px;font-weight:700;margin:0 0 6px">No support tickets yet</p>
      <p class="pa-muted" style="margin:0 0 16px;font-size:14px">Most questions are answered in the <a href="{{ route('help') }}">help centre</a>. Still stuck? Open a ticket and we'll get back to you.</p>
      @if (\App\Support\SupportSettings::bool('support_tickets_enabled'))
        <a class="pa-btn" href="{{ route('account.support.create') }}">Open a ticket</a>
      @endif
    </div>
  @else
    <div class="pa-card">
      @foreach ($tickets as $t)
        <a class="pa-sup-row" href="{{ route('account.support.show', $t) }}">
          <div style="min-width:0;flex:1">
            <div class="pa-sup-subject">{{ $t->subject }}</div>
            <div class="pa-sup-meta">{{ $t->reference }} · {{ $t->categoryLabel() }} · updated {{ ($t->last_reply_at ?? $t->created_at)?->diffForHumans() }}</div>
          </div>
          <span class="pa-badge {{ ['open' => 'is-wait', 'awaiting_reply' => 'is-stop', 'resolved' => 'is-go', 'closed' => ''][$t->status] ?? '' }}">{{ $t->statusLabel() }}</span>
        </a>
      @endforeach
    </div>
    @if ($tickets->hasPages())
      <div style="display:flex;justify-content:space-between;margin-top:16px">
        @if ($tickets->previousPageUrl())<a class="pa-btn-ghost pa-btn-sm" href="{{ $tickets->previousPageUrl() }}">← Newer</a>@else<span></span>@endif
        @if ($tickets->nextPageUrl())<a class="pa-btn-ghost pa-btn-sm" href="{{ $tickets->nextPageUrl() }}">Older →</a>@endif
      </div>
    @endif
  @endif

  <p class="pa-muted" style="font-size:13px;margin-top:18px">{{ \App\Support\SupportSettings::str('support_response_note') }}</p>
</x-support-shell>
</x-layout>
