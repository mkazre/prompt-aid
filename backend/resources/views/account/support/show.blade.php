<x-layout :title="$ticket->reference.' · Help & support · Prompt Aid'">
<x-support-shell>
  <div class="pa-crumb" style="margin-bottom:10px"><a href="{{ route('account.support.index') }}">Help &amp; support</a> <span style="color:#CFC8B8">/</span> {{ $ticket->reference }}</div>
  <div class="pa-spread" style="margin-bottom:18px;flex-wrap:wrap;gap:12px;align-items:flex-start">
    <div style="min-width:0">
      <h1 style="font-size:28px;margin-bottom:6px">{{ $ticket->subject }}</h1>
      <div class="pa-muted" style="font-size:13px">{{ $ticket->reference }} · {{ $ticket->categoryLabel() }} · opened {{ $ticket->created_at?->format('j M Y, H:i') }}</div>
    </div>
    <span class="pa-badge {{ ['open' => 'is-wait', 'awaiting_reply' => 'is-stop', 'resolved' => 'is-go', 'closed' => ''][$ticket->status] ?? '' }}" style="font-size:11px">{{ $ticket->statusLabel() }}</span>
  </div>

  @if (session('success'))
    <div class="pa-note" style="margin-bottom:18px">{{ session('success') }}</div>
  @endif

  <div class="pa-sup-thread">
    @foreach ($ticket->replies as $r)
      <div class="pa-sup-msg {{ $r->is_staff ? 'is-staff' : 'is-me' }}">
        <div class="pa-sup-msg-head">
          <strong>{{ $r->is_staff ? 'PromptAid support' : 'You' }}</strong>
          <span>{{ $r->created_at?->format('j M, H:i') }}</span>
        </div>
        <div class="pa-sup-msg-body">{!! nl2br(e($r->body)) !!}</div>
        @if ($r->hasAttachment())
          <a class="pa-sup-file" href="{{ $r->attachmentUrl() }}" target="_blank" rel="noopener">📎 {{ $r->attachment_name }}</a>
        @endif
      </div>
    @endforeach
  </div>

  @if ($ticket->isClosed())
    <div class="pa-note" style="margin-top:18px">This ticket is closed. Need more help? <a href="{{ route('account.support.create') }}">Open a new ticket</a>.</div>
  @else
    <form method="POST" action="{{ route('account.support.reply', $ticket) }}" enctype="multipart/form-data" class="pa-card pa-card-pad" style="margin-top:18px" data-pa-once>
      @csrf
      <div class="pa-formrow"><label class="pa-label" for="sup-reply">Your reply</label>
        <textarea class="pa-field" id="sup-reply" name="message" rows="4" maxlength="5000" required>{{ old('message') }}</textarea>
        @error('message')<div class="pa-sup-err">{{ $message }}</div>@enderror
      </div>
      <div class="pa-formrow"><input class="pa-field" type="file" name="attachment" accept=".jpg,.jpeg,.png,.webp,.heic,.heif,.gif,.pdf" aria-label="Attachment (optional)" />
        @error('attachment')<div class="pa-sup-err">{{ $message }}</div>@enderror
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <button type="submit" class="pa-btn">Send reply</button>
      </div>
    </form>
    <form method="POST" action="{{ route('account.support.status', $ticket) }}" style="margin-top:12px">
      @csrf
      @if ($ticket->status === 'resolved')
        <input type="hidden" name="status" value="open" />
        <button type="submit" class="pa-btn-ghost pa-btn-sm">Reopen ticket</button>
      @else
        <input type="hidden" name="status" value="resolved" />
        <button type="submit" class="pa-btn-ghost pa-btn-sm">My issue is resolved</button>
      @endif
    </form>
  @endif
</x-support-shell>
</x-layout>
