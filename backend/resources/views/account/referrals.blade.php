<x-layout title="Invite friends · Prompt Aid">
<div class="pa-account">
<x-account-nav active="referrals" />
<div>
  <div class="pa-spread" style="margin-bottom:22px">
    <div><h1 style="font-size:32px">Invite friends</h1><p class="pa-muted" style="margin:6px 0 0">Share your code with friends and family.</p></div>
  </div>

  @unless ($summary['enabled'])
    <div class="pa-note" style="margin-bottom:18px">The referral programme is paused at the moment. Your code still works when it reopens.</div>
  @endunless

  @if ($summary['reward_text'])
    <div class="pa-card pa-card-pad" style="margin-bottom:20px">
      <div class="pa-label" style="margin-bottom:6px">How it works</div>
      <p style="margin:0;font-size:15px;color:var(--pa-ink-soft)">{{ $summary['reward_text'] }}</p>
    </div>
  @endif

  <div class="pa-grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));margin-bottom:24px">
    <div class="pa-stat"><div class="k">Your code</div><div class="v" id="ref-code" style="letter-spacing:1px">{{ $summary['code'] }}</div><div class="d flat">Friends enter it when they sign up</div></div>
    <div class="pa-stat"><div class="k">Friends joined</div><div class="v">{{ $summary['joined_count'] }}</div><div class="d {{ $summary['joined_count'] ? 'go' : 'flat' }}">{{ $summary['joined_count'] === 1 ? 'person' : 'people' }} signed up with your code</div></div>
  </div>

  <div class="pa-card pa-card-pad" style="margin-bottom:24px">
    <label class="pa-label" for="ref-link">Your invite link</label>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <input class="pa-field" id="ref-link" readonly value="{{ $summary['link'] }}" style="flex:1;min-width:0" />
      <button type="button" class="pa-btn" id="ref-copy">Copy link</button>
      <button type="button" class="pa-btn-ghost" id="ref-share" hidden>Share</button>
      <a class="pa-btn-ghost" href="https://wa.me/?text={{ rawurlencode($summary['share_message']) }}" target="_blank" rel="noopener">WhatsApp</a>
    </div>
    <div id="ref-msg" class="pa-muted" style="font-size:12px;margin-top:8px" aria-live="polite"></div>
  </div>

  <div class="pa-tablewrap"><table class="pa-table">
    <thead><tr><th>Friend</th><th>Joined</th></tr></thead>
    <tbody>
      @forelse ($summary['recent'] as $friend)
        <tr><td><div class="t-main">{{ $friend['name'] }}</div></td><td>{{ \Illuminate\Support\Carbon::parse($friend['joined_at'])->format('d M Y') }}</td></tr>
      @empty
        <tr><td colspan="2"><p class="pa-muted" style="padding:24px;text-align:center;font-size:14px">No one has joined with your code yet. Share your link to get started.</p></td></tr>
      @endforelse
    </tbody>
  </table></div>
</div>
</div>
<script>
  (function () {
    const link = document.getElementById('ref-link');
    const msg = document.getElementById('ref-msg');
    const shareText = @json($summary['share_message']);
    document.getElementById('ref-copy').addEventListener('click', async function () {
      try { await navigator.clipboard.writeText(link.value); } catch (e) { link.select(); document.execCommand('copy'); }
      msg.textContent = 'Link copied.';
    });
    const shareBtn = document.getElementById('ref-share');
    if (navigator.share) {
      shareBtn.hidden = false;
      shareBtn.addEventListener('click', function () { navigator.share({ title: 'PromptAid', text: shareText, url: link.value }).catch(function () {}); });
    }
  })();
</script>
</x-layout>
