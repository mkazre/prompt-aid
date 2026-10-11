<section class="pa-section" id="benefits">
  <div class="pa-eyebrow" style="margin-bottom:10px">{{ \App\Support\SiteContent::get('home.benefits_eyebrow') }}</div>
  <h2 style="margin-bottom:28px">{{ \App\Support\SiteContent::get('home.benefits_heading') }}</h2>
  <div style="display:grid;gap:1px;background:var(--pa-line);border:1px solid var(--pa-line)">
    @foreach (\App\Support\SiteContent::get('benefits') as $i => $benefit)
      <div id="benefit-{{ $i + 1 }}" style="background:var(--pa-surface);padding:26px 28px;display:grid;grid-template-columns:56px minmax(0,1fr);gap:18px;scroll-margin-top:110px">
        <div class="pa-num" style="font-size:26px;color:var(--pa-signal)">{{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}</div>
        <div>
          <h3 style="margin-bottom:8px">{{ $benefit['title'] }}</h3>
          <p style="font-size:15px;line-height:1.65;color:var(--pa-ink-soft);margin:0">{{ $benefit['detail'] ?: $benefit['summary'] }}</p>
        </div>
      </div>
    @endforeach
  </div>
</section>
<style>#benefits :target, [id^="benefit-"]:target{outline:2px solid var(--pa-beacon);outline-offset:-2px}</style>
