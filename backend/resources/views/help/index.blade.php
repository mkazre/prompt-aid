<x-layout :title="$heading.' · Prompt Aid'">
<div class="pa-pagehead"><div class="inner">
  <div class="pa-crumb"><a href="{{ url('/') }}">Home</a> <span style="color:#CFC8B8">/</span> Help centre</div>
  <h1>{{ $heading }}</h1>
  <p class="pa-muted" style="font-size:15px;margin:6px 0 18px;max-width:640px">{{ $intro }}</p>
  <form method="GET" action="{{ route('help') }}" class="pa-help-search" role="search">
    <input class="pa-field" type="search" name="q" id="pa-help-q" value="{{ $term }}" placeholder="Search e.g. cancel, shuttle, medical aid" aria-label="Search the help centre" autocomplete="off" />
    <button class="pa-btn" type="submit">Search</button>
  </form>
</div></div>

<div class="pa-container" style="padding-top:32px;padding-bottom:80px;max-width:900px">
  @if ($term !== '')
    <p class="pa-muted" style="margin:0 0 18px">{{ $total }} {{ \Illuminate\Support\Str::plural('answer', $total) }} for “{{ $term }}” · <a href="{{ route('help') }}">Show all</a></p>
  @endif

  <div id="pa-help-list">
    @forelse ($groups as $group)
      <section class="pa-help-group" data-pa-help-group>
        <h2 class="pa-help-cat">{{ $group['category']?->name ?? 'More questions' }}</h2>
        @foreach ($group['faqs'] as $faq)
          <details class="pa-help-item" data-pa-help-item data-text="{{ mb_strtolower($faq->question.' '.strip_tags($faq->answer)) }}" @if($term !== '' && $loop->parent->first && $loop->first) open @endif>
            <summary>
              <span>{{ $faq->question }}</span>
              @if ($faq->audience !== 'all')<span class="pa-badge" style="margin-left:8px">{{ \App\Models\Faq::AUDIENCES[$faq->audience] ?? $faq->audience }}</span>@endif
            </summary>
            <div class="pa-help-answer pa-prose">{!! \Illuminate\Support\Str::of($faq->answer)->stripTags('<p><br><strong><b><em><i><ul><ol><li><a><h3><h4><blockquote>') !!}</div>
          </details>
        @endforeach
      </section>
    @empty
      <div class="pa-card pa-card-pad" style="text-align:center">
        <p style="font-weight:700;margin:0 0 6px">No answers found</p>
        <p class="pa-muted" style="margin:0">Try another word, or contact us below.</p>
      </div>
    @endforelse
    <div class="pa-card pa-card-pad" id="pa-help-none" hidden style="text-align:center">
      <p style="font-weight:700;margin:0 0 6px">No answers match that search</p>
      <p class="pa-muted" style="margin:0">Try another word, or contact us below.</p>
    </div>
  </div>

  <div class="pa-card pa-spread" style="padding:28px 32px;margin-top:40px;flex-wrap:wrap;gap:16px">
    <div><h3 style="margin-bottom:6px">Still stuck?</h3><p class="pa-muted" style="margin:0">Our support team will get back to you.</p></div>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      @auth<a class="pa-btn" href="{{ route('account.support.create') }}">Open a support ticket</a>@endauth
      <a class="{{ auth()->check() ? 'pa-btn-ghost' : 'pa-btn' }}" href="{{ route('contact') }}">Contact us</a>
    </div>
  </div>
</div>
</x-layout>
