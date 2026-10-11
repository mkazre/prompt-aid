<x-layout title="Messages · Prompt Aid">
@php
    $me = auth()->user();
    $activeCounterpart = $active ? ($me->isDoctor() ? $active->patient?->user?->name : 'Dr. '.($active->doctor?->user?->name ?? '')) : null;
    $activeSubtitle = $active ? ($me->isDoctor() ? 'Patient' : ($active->doctor?->specialization ?? 'Doctor')) : null;
    $activeAppt = $active?->appointment;
    $chatConfig = [
        'pollUrl' => $active ? route('account.messages.poll', $active) : null,
        'sendUrl' => $active ? route('account.messages.send', $active) : null,
        'canSend' => $active ? $active->isParticipant($me) : false,
        'lastId' => $threadMessages->last()['id'] ?? 0,
    ];
@endphp
<div class="pa-account">
<x-account-nav active="messages" />
<div>
  <div class="pa-spread" style="margin-bottom:22px">
    <div><h1 style="font-size:32px">Messages</h1><p class="pa-muted" style="margin:6px 0 0">Chat privately with doctors you have an appointment with.</p></div>
    <a class="pa-btn-ghost" href="{{ route('account.appointments') }}">My appointments</a>
  </div>

  @if (session('error'))
    <div class="pa-note-stop" style="margin-bottom:18px">{{ session('error') }}</div>
  @endif

  @if ($conversations->isEmpty())
    <div class="pa-card pa-card-pad" style="text-align:center;padding:40px">
      <p style="font-size:16px;font-weight:700;margin:0 0 6px">No conversations yet</p>
      <p class="pa-muted" style="margin:0 0 16px;font-size:14px">Open one of your appointments and choose “Message doctor” to start a conversation.</p>
      <a class="pa-btn" href="{{ route('account.appointments') }}">View appointments</a>
    </div>
  @else
  <div class="pa-chat">
    <div class="pa-chat-list">
      @foreach ($conversations as $c)
        @php
          $cName = $me->isDoctor() ? ($c->patient?->user?->name ?? 'Patient') : 'Dr. '.($c->doctor?->user?->name ?? '');
          $cPreview = $c->latestMessage ? ($c->latestMessage->body ?: '📎 '.$c->latestMessage->attachment_name) : 'No messages yet';
          $cInitials = collect(explode(' ', trim(str_replace('Dr. ', '', $cName))))->filter()->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->take(2)->implode('');
        @endphp
        <a class="pa-chat-item {{ $active && $active->id === $c->id ? 'is-on' : '' }}" href="{{ route('account.messages', ['c' => $c->id]) }}">
          <div class="pa-avatar" style="width:40px;height:40px;font-size:13px;flex-shrink:0">{{ $cInitials }}</div>
          <div style="min-width:0;flex:1">
            <div class="nm">{{ $cName }}</div>
            <div class="pv">{{ \Illuminate\Support\Str::limit($cPreview, 48) }}</div>
          </div>
          @if ($c->unread_count > 0 && !($active && $active->id === $c->id))
            <span class="pa-chat-unread">{{ $c->unread_count }}</span>
          @endif
        </a>
      @endforeach
    </div>

    <div class="pa-chat-thread">
      @if ($active)
        <div class="pa-chat-head">
          <div style="min-width:0;flex:1">
            <div style="font-size:15px;font-weight:700">{{ $activeCounterpart }}</div>
            <div style="font-size:12px;color:var(--pa-muted)">{{ $activeSubtitle }}</div>
          </div>
          @if ($activeAppt && $activeAppt->canJoinVideoNow())
            <a class="pa-btn pa-btn-sm" href="{{ route('account.appointments.video', $activeAppt) }}" target="_blank" rel="noopener">Join video</a>
          @endif
        </div>
        <div class="pa-chat-body" id="pa-chat-body">
          @if ($activeAppt)
            <div class="pa-chat-ctx">
              <strong style="color:var(--pa-ink)">Consultation</strong> · {{ $activeAppt->isVideo() ? 'Video' : 'In person' }}<br>
              {{ $activeAppt->date?->format('D d M Y') }} at {{ substr((string) $activeAppt->start_time, 0, 5) }}
            </div>
          @endif
          @foreach ($threadMessages as $m)
            <div class="pa-msg {{ $m['is_mine'] ? 'is-mine' : '' }}" data-id="{{ $m['id'] }}">
              <div class="bub">@if ($m['body']){{ $m['body'] }}@endif @if ($m['attachment'])@if ($m['attachment']['is_image'])<a href="{{ $m['attachment']['url'] }}" target="_blank" rel="noopener"><img src="{{ $m['attachment']['url'] }}" alt="{{ $m['attachment']['name'] }}"></a>@else<a href="{{ $m['attachment']['url'] }}" target="_blank" rel="noopener">📎 {{ $m['attachment']['name'] }}</a>@endif @endif</div>
              <div class="meta">{{ \Illuminate\Support\Carbon::parse($m['created_at'])->format('d M H:i') }}@if ($m['is_mine']) · <span class="rd">{{ $m['read_at'] ? 'Read' : 'Sent' }}</span>@endif</div>
            </div>
          @endforeach
        </div>
        @if ($chatConfig['canSend'])
          <div class="pa-chat-file" id="pa-chat-file" hidden></div>
          <form class="pa-chat-form" id="pa-chat-form">
            <label class="pa-chat-clip" title="Attach image or PDF">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 01-8.49-8.49l9.19-9.19a4 4 0 015.66 5.66l-9.2 9.19a2 2 0 01-2.83-2.83l8.49-8.48"/></svg>
              <input type="file" name="attachment" id="pa-chat-attach" accept="image/*,application/pdf">
            </label>
            <textarea name="body" id="pa-chat-input" rows="1" placeholder="Type a message…" maxlength="5000"></textarea>
            <button class="pa-btn" type="submit" id="pa-chat-send">Send</button>
          </form>
        @endif
      @endif
    </div>
  </div>
  @endif
</div>
</div>

@if ($active)
@push('scripts')
<script>
(function () {
  var cfg = @json($chatConfig);
  var body = document.getElementById('pa-chat-body');
  var form = document.getElementById('pa-chat-form');
  var input = document.getElementById('pa-chat-input');
  var attach = document.getElementById('pa-chat-attach');
  var fileLabel = document.getElementById('pa-chat-file');
  var token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
  var lastId = cfg.lastId || 0;
  var seen = {};
  document.querySelectorAll('.pa-msg[data-id]').forEach(function (el) { seen[el.getAttribute('data-id')] = true; });

  function scrollDown() { body.scrollTop = body.scrollHeight; }
  function fmt(iso) {
    var d = new Date(iso);
    return d.toLocaleDateString('en-ZA', { day: '2-digit', month: 'short' }) + ' ' + d.toLocaleTimeString('en-ZA', { hour: '2-digit', minute: '2-digit' });
  }
  function render(m) {
    if (seen[m.id]) return;
    seen[m.id] = true;
    var wrap = document.createElement('div');
    wrap.className = 'pa-msg' + (m.is_mine ? ' is-mine' : '');
    wrap.setAttribute('data-id', m.id);
    var bub = document.createElement('div');
    bub.className = 'bub';
    if (m.body) bub.appendChild(document.createTextNode(m.body));
    if (m.attachment) {
      var a = document.createElement('a');
      a.href = m.attachment.url; a.target = '_blank'; a.rel = 'noopener';
      if (m.attachment.is_image) {
        var img = document.createElement('img'); img.src = m.attachment.url; img.alt = m.attachment.name; a.appendChild(img);
      } else {
        a.textContent = '📎 ' + m.attachment.name;
      }
      bub.appendChild(a);
    }
    var meta = document.createElement('div');
    meta.className = 'meta';
    meta.textContent = fmt(m.created_at);
    if (m.is_mine) {
      var rd = document.createElement('span'); rd.className = 'rd'; rd.textContent = m.read_at ? 'Read' : 'Sent';
      meta.appendChild(document.createTextNode(' · ')); meta.appendChild(rd);
    }
    wrap.appendChild(bub); wrap.appendChild(meta);
    body.appendChild(wrap);
    if (m.id > lastId) lastId = m.id;
  }
  function markReadUpTo(id) {
    if (!id) return;
    document.querySelectorAll('.pa-msg.is-mine[data-id]').forEach(function (el) {
      if (parseInt(el.getAttribute('data-id'), 10) <= id) { var r = el.querySelector('.rd'); if (r) r.textContent = 'Read'; }
    });
  }
  function poll() {
    if (document.hidden) return;
    fetch(cfg.pollUrl + '?after=' + lastId, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (res) {
        if (!res) return;
        var atBottom = body.scrollHeight - body.scrollTop - body.clientHeight < 80;
        res.data.forEach(render);
        markReadUpTo(res.last_read_own_id);
        if (res.data.length && atBottom) scrollDown();
      })
      .catch(function () {});
  }
  scrollDown();
  setInterval(poll, 4000);

  if (form) {
    attach.addEventListener('change', function () {
      if (attach.files.length) { fileLabel.hidden = false; fileLabel.textContent = 'Attached: ' + attach.files[0].name; }
      else { fileLabel.hidden = true; }
    });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit')); }
    });
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!input.value.trim() && !attach.files.length) return;
      if (attach.files.length && attach.files[0].size > 10 * 1024 * 1024) { alert('Attachments can be at most 10 MB.'); return; }
      var fd = new FormData(form);
      var btn = document.getElementById('pa-chat-send');
      btn.disabled = true;
      fetch(cfg.sendUrl, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' } })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
          if (!res.ok) { alert(res.j.message || 'Message could not be sent.'); return; }
          render(res.j.data);
          input.value = ''; attach.value = ''; fileLabel.hidden = true;
          scrollDown();
        })
        .catch(function () { alert('Message could not be sent. Check your connection.'); })
        .finally(function () { btn.disabled = false; });
    });
  }
})();
</script>
@endpush
@endif
</x-layout>
