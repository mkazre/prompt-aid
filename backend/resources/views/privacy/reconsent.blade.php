<x-layout title="Updated terms · Prompt Aid">
<div class="pa-container" style="padding-top:56px;padding-bottom:80px;max-width:620px">
  <div class="pa-pop" style="padding:30px">
    <h3 style="margin-bottom:6px">We've updated our terms</h3>
    <p style="font-size:14px;color:var(--pa-ink-soft);margin-bottom:18px">Please read what changed and accept to keep using Prompt Aid.</p>
    @if ($errors->any())
      <div class="pa-note-stop" style="margin-bottom:16px">{{ $errors->first() }}</div>
    @endif
    <form method="POST" action="{{ route('account.privacy.reconsent.accept') }}">
      @csrf
      @foreach ($documents as $doc)
        <div class="pa-privacy-doc">
          <div class="t-main">{{ $doc['title'] }} <span class="pa-muted pa-privacy-sub">· version {{ $doc['version'] }}</span></div>
          @if (! empty($doc['summary_of_changes']))
            <p class="pa-privacy-sub" style="margin:6px 0">{{ $doc['summary_of_changes'] }}</p>
          @endif
          @if (! empty($doc['page_slug']))
            <a class="pa-privacy-sub" href="{{ url('/'.ltrim($doc['page_slug'], '/')) }}" target="_blank" rel="noopener">Read the full document</a>
          @endif
          <label class="pa-check" style="margin-top:8px"><input type="checkbox" name="accept[]" value="{{ $doc['purpose'] }}" required style="margin-right:6px">I have read and accept the updated {{ \Illuminate\Support\Str::lower($doc['title']) }}.</label>
        </div>
      @endforeach
      <button type="submit" class="pa-btn pa-btn-block pa-btn-lg" style="margin-top:16px">Accept and continue</button>
    </form>
    <form method="POST" action="{{ route('logout') }}" style="margin-top:12px">
      @csrf
      <button type="submit" class="pa-btn-ghost pa-btn-block pa-btn-sm">Not now — log out</button>
    </form>
    <div style="font-size:13px;color:var(--pa-muted);text-align:center;margin-top:12px">Don't agree? You can <a href="{{ route('account.privacy') }}">request a copy of your data or account deletion</a>.</div>
  </div>
</div>
</x-layout>
