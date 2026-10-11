@php $c = \App\Support\SiteContent::get('home'); @endphp
<x-layout :title="$c['title'].' · Prompt Aid'">
<section class="pa-hero">
  <div class="pa-container pa-hero-grid">
    <div class="pa-hero-copy" style="animation:pa-rise .5s ease both">
      <div class="pa-row" style="margin-bottom:24px"><span class="pa-tick"></span><span class="pa-eyebrow">{{ $c['eyebrow'] }}</span></div>
      <h1 style="font-size:clamp(34px,4.2vw,58px);margin-bottom:22px">{{ $c['title'] }}</h1>
      @if ($c['lead'])<p style="font-size:17px;color:var(--pa-ink-soft);max-width:540px;margin-bottom:18px">{{ $c['lead'] }}</p>@endif
      @if ($c['app_store_url'] || $c['play_store_url'])
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:22px">
          @if ($c['app_store_url'])<a class="pa-btn" href="{{ $c['app_store_url'] }}" target="_blank" rel="noopener">Download on the App Store</a>@endif
          @if ($c['play_store_url'])<a class="pa-btn-ghost" href="{{ $c['play_store_url'] }}" target="_blank" rel="noopener">Get it on Google Play</a>@endif
        </div>
      @endif
      @if ($c['status_note'])
        <div class="pa-status-legend">
          <div>
            <span class="dot" style="background:#1F7A4C"></span><span class="dot" style="background:#E4701E"></span><span class="dot" style="background:#C8102E"></span>
            {{ $c['status_note'] }}
          </div>
        </div>
      @endif
      <div style="display:flex;gap:28px;flex-wrap:wrap">
        <div><div class="pa-num" style="font-size:30px">{{ number_format($stats['providers']) }}</div><div style="font-size:12px;color:var(--pa-muted);margin-top:2px">providers listed</div></div>
        <div style="width:1px;background:var(--pa-line)"></div>
        <div><div class="pa-num" style="font-size:30px">11 min</div><div style="font-size:12px;color:var(--pa-muted);margin-top:2px">median shuttle pickup</div></div>
        <div style="width:1px;background:var(--pa-line)"></div>
        <div><div class="pa-num" style="font-size:30px">{{ $stats['schemes'] }}</div><div style="font-size:12px;color:var(--pa-muted);margin-top:2px">schemes billed direct</div></div>
      </div>
    </div>
    <div class="pa-hero-widget">
      <div class="pa-pop" data-pa-tabs>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;border-bottom:1px solid var(--pa-line)">
          <button class="pa-tab is-on" data-pa-target="care" style="border:0;border-radius:0;padding:15px 8px">{{ $c['tab_care'] }}</button>
          <button class="pa-tab" data-pa-target="virtual" style="border:0;border-left:1px solid var(--pa-line);border-radius:0;padding:15px 8px">{{ $c['tab_virtual'] }}</button>
          <button class="pa-tab" data-pa-target="ride" style="border:0;border-left:1px solid var(--pa-line);border-radius:0;padding:15px 8px">{{ $c['tab_shuttle'] }}</button>
        </div>
        <div style="padding:26px">
          @foreach (['care' => 'doctors.index', 'virtual' => 'virtual-consult'] as $panel => $target)
          <form method="GET" action="{{ route($target) }}" data-pa-panel="{{ $panel }}" @class(['pa-hide' => $panel !== 'care'])>
            <div class="pa-formrow"><label class="pa-label">{{ $c['search_label'] }}</label><input class="pa-field" name="search" placeholder="{{ $c['search_placeholder'] }}" /></div>
            <div class="pa-formrow" style="margin-bottom:22px"><label class="pa-label">Where</label><input class="pa-field" placeholder="Suburb or postal code (coming soon)" disabled /></div>
            <button type="submit" class="pa-btn pa-btn-block pa-btn-lg">{{ $panel === 'care' ? 'Search '.number_format($stats['providers']).' providers' : 'Find a virtual consult' }}</button>
          </form>
          @endforeach
          <form method="GET" action="{{ route('shuttle') }}" data-pa-panel="ride" class="pa-hide">
            <div class="pa-formrow"><label class="pa-label">Pick up</label><input class="pa-field" placeholder="Home address (entered on the next step)" disabled /></div>
            <div class="pa-formrow" style="margin-bottom:22px"><label class="pa-label">Drop off</label><input class="pa-field" placeholder="Clinic, pharmacy or lab" disabled /></div>
            <button type="submit" class="pa-btn pa-btn-block pa-btn-lg">Get a fare estimate</button>
          </form>
          <div class="pa-row" style="margin-top:16px;font-size:12px;color:var(--pa-muted)"><span style="width:6px;height:6px;background:var(--pa-go);display:block"></span>{{ $stats['openToday'] }} doctors have openings today</div>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="pa-dark">
  <div class="pa-container" style="padding-top:18px;padding-bottom:18px;display:flex;gap:40px;align-items:center;flex-wrap:wrap;font-size:13px">
    <span class="pa-row" style="font-weight:700"><span class="pa-tick-beacon" style="width:7px;height:7px;animation:pa-blip 1.6s ease-in-out infinite"></span>Live now</span>
    <span style="color:#A9A49A"><strong style="color:var(--pa-paper)">{{ $stats['tripsToday'] }}</strong> trips today</span>
    <span style="color:#A9A49A"><strong style="color:var(--pa-paper)">9 min</strong> median pickup</span>
    <span style="color:#A9A49A"><strong style="color:var(--pa-paper)">96%</strong> on time</span>
    <span style="color:#A9A49A"><strong style="color:var(--pa-paper)">{{ $stats['scriptsThisWeek'] }}</strong> scripts dispensed this week</span>
  </div>
</section>

<section class="pa-section" id="benefits">
  <div class="pa-spread" style="align-items:baseline;margin-bottom:28px">
    <div>@if($c['benefits_eyebrow'])<div class="pa-eyebrow" style="margin-bottom:10px">{{ $c['benefits_eyebrow'] }}</div>@endif<h2>{{ $c['benefits_heading'] }}</h2></div>
    <a class="pa-btn-ghost" href="{{ route('about') }}#benefits">About PromptAid</a>
  </div>
  <div class="pa-grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr))">
    @foreach (\App\Support\SiteContent::get('benefits') as $i => $benefit)
      <a href="{{ route('about') }}#benefit-{{ $i + 1 }}" class="pa-benefit">
        <div class="pa-num" style="font-size:26px;color:var(--pa-signal);margin-bottom:14px">{{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}</div>
        <div style="font-size:16px;font-weight:700;margin-bottom:6px">{{ $benefit['title'] }}</div>
        @if (! empty($benefit['summary']))<div style="font-size:13px;color:var(--pa-muted);margin-bottom:14px">{{ $benefit['summary'] }}</div>@endif
        <span class="pa-benefit-more">Learn more →</span>
      </a>
    @endforeach
  </div>
</section>

<section class="pa-section">
  <div class="pa-dark" style="padding:56px 56px 0;display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:56px;align-items:end">
    <div style="padding-bottom:56px">
      <div class="pa-row" style="margin-bottom:20px"><span class="pa-tick-beacon"></span><span class="pa-eyebrow" style="color:var(--pa-beacon)">The shuttle</span></div>
      <h2 style="font-size:44px;margin-bottom:18px">Missing the appointment<br />is the appointment problem.</h2>
      <p style="font-size:16px;color:#B8B3A8;max-width:460px;margin-bottom:30px">Request a vehicle from home, watch it arrive, and book the return leg at the same time. Wheelchair and stretcher vehicles on the same map.</p>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1px;background:#2A2A2E;border:1px solid #2A2A2E;margin-bottom:30px">
        <div style="background:var(--pa-ink);padding:18px 20px"><div style="font-size:14px;font-weight:700;margin-bottom:5px">Standard</div><div style="font-size:12px;color:#8A857C;margin-bottom:10px">Sedan, 1–3 seats</div><div class="pa-num" style="font-size:20px;color:var(--pa-beacon)">R 18 / km</div></div>
        <div style="background:var(--pa-ink);padding:18px 20px"><div style="font-size:14px;font-weight:700;margin-bottom:5px">Comfort</div><div style="font-size:12px;color:#8A857C;margin-bottom:10px">SUV, extra legroom</div><div class="pa-num" style="font-size:20px;color:var(--pa-beacon)">R 24 / km</div></div>
        <div style="background:var(--pa-ink);padding:18px 20px"><div style="font-size:14px;font-weight:700;margin-bottom:5px">Wheelchair</div><div style="font-size:12px;color:#8A857C;margin-bottom:10px">Ramp and restraints</div><div class="pa-num" style="font-size:20px;color:var(--pa-beacon)">R 32 / km</div></div>
        <div style="background:var(--pa-ink);padding:18px 20px"><div style="font-size:14px;font-weight:700;margin-bottom:5px">Stretcher</div><div style="font-size:12px;color:#8A857C;margin-bottom:10px">Non-emergency transfer</div><div class="pa-num" style="font-size:20px;color:var(--pa-beacon)">R 46 / km</div></div>
      </div>
      <a class="pa-btn-beacon pa-btn-lg" href="{{ route('shuttle') }}">Request a shuttle</a>
    </div>
    <div class="pa-map" style="height:420px;border-left:1px solid #2A2A2E;border-top:1px solid #2A2A2E">
      <div style="position:absolute;left:20px;bottom:20px;right:20px;background:var(--pa-paper);color:var(--pa-ink);padding:16px 18px;display:flex;justify-content:space-between;align-items:center;gap:16px">
        <div><div class="pa-eyebrow">Fare estimate</div><div style="font-size:13px;color:var(--pa-ink-soft);margin-top:3px">Get a real quote in under a minute</div></div>
        <a class="pa-btn" href="{{ route('shuttle') }}">Get quote</a>
      </div>
    </div>
  </div>
</section>

@if ($topDoctors->count())
<section class="pa-section">
  <div class="pa-spread" style="align-items:baseline;margin-bottom:28px"><h2>{{ $c['top_doctors_heading'] }}</h2><a class="pa-btn-ghost" href="{{ route('doctors.index') }}">All doctors</a></div>
  <div class="pa-grid" style="grid-template-columns:repeat(auto-fit,minmax(240px,1fr))">
    @foreach ($topDoctors as $doctor)
      <a href="{{ route('doctors.show', $doctor) }}" style="padding:24px;color:inherit;text-decoration:none;display:block">
        <div style="display:flex;gap:14px;margin-bottom:14px"><div class="pa-avatar" style="width:52px;height:52px;font-size:18px">{{ \Illuminate\Support\Str::of($doctor->user->name)->explode(' ')->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('') }}</div>
        <div><div style="font-size:16px;font-weight:700;line-height:1.25">Dr {{ $doctor->user->name }}</div><div style="font-size:13px;color:var(--pa-muted);margin-top:3px">{{ $doctor->specialization }}</div></div></div>
        <div style="margin-bottom:12px"><span class="pa-status is-{{ $doctor->liveStatus() }}">{{ $doctor->liveStatusLabel() }}</span></div>
        <div style="font-size:13px;color:var(--pa-ink-soft);margin-bottom:14px">{{ $doctor->clinics->first()?->name ?? 'Video consultation available' }}</div>
        <div class="pa-spread" style="border-top:1px solid var(--pa-line);padding-top:14px"><span style="font-size:13px;color:var(--pa-muted)">@if($doctor->rating_count)★ {{ number_format($doctor->rating_avg, 1) }} ({{ $doctor->rating_count }})@else New @endif</span><span class="pa-num" style="font-size:19px">R {{ number_format($doctor->consultation_fee, 0) }}</span></div></a>
    @endforeach
  </div>
</section>
@endif

@if ($topServices->count())
<section class="pa-section">
  <div class="pa-rule-heavy" style="margin-bottom:36px"></div>
  <div class="pa-spread" style="align-items:baseline;margin-bottom:28px"><h2>{{ $c['top_services_heading'] }}</h2></div>
  <div class="pa-grid" style="grid-template-columns:repeat(auto-fit,minmax(240px,1fr))">
    @foreach ($topServices as $service)
      @php $m = $service['model']; $name = $m->company_name ?? $m->name; @endphp
      <a href="{{ route($service['route'], $m) }}" style="padding:24px;color:inherit;text-decoration:none;display:block">
        <div style="display:flex;gap:14px;margin-bottom:14px"><div class="pa-avatar" style="width:52px;height:52px;font-size:18px">{{ mb_strtoupper(mb_substr($name, 0, 2)) }}</div>
        <div><div style="font-size:16px;font-weight:700;line-height:1.25">{{ $name }}</div><div style="font-size:13px;color:var(--pa-muted);margin-top:3px">{{ $service['kind'] }}</div></div></div>
        <div class="pa-spread" style="border-top:1px solid var(--pa-line);padding-top:14px"><span style="font-size:13px;color:var(--pa-muted)">@if($m->rating_count)★ {{ number_format($m->rating_avg, 1) }} ({{ $m->rating_count }})@else New @endif</span><span class="pa-num" style="font-size:15px">View</span></div></a>
    @endforeach
  </div>
</section>
@endif

@if ($schemeNames->count())
<section class="pa-section">
  <div class="pa-card pa-spread" style="padding:28px 32px">
    <div class="pa-eyebrow">Billed directly to</div>
    <div style="display:flex;gap:28px;flex-wrap:wrap;font-size:17px;font-weight:700;color:var(--pa-ink-soft)">
      @foreach ($schemeNames as $name)<span>{{ $name }}</span>@endforeach
    </div>
  </div>
</section>
@endif

<section class="pa-section pa-section-last">
  <div style="background:var(--pa-signal);color:#fff;padding:52px 48px;display:flex;align-items:center;justify-content:space-between;gap:36px;flex-wrap:wrap">
    <div><h2 style="font-size:38px;color:#fff;margin-bottom:10px">{{ $c['topic_heading'] }}</h2>
    <p style="font-size:17px;color:#FBD5D3;margin:0;max-width:620px">{{ $topic?->title ?? 'A new health topic is coming soon.' }}</p></div>
    <div style="display:flex;gap:12px;flex-wrap:wrap">
      <a class="pa-btn-lg" style="background:#fff;color:var(--pa-ink);border:1px solid #fff;font-weight:700;padding:16px 26px;display:inline-flex;align-items:center" href="{{ route('topic.show') }}">{{ $c['topic_read_label'] }}</a>
      <a class="pa-btn-lg" style="background:transparent;color:#fff;border:1px solid #fff;font-weight:700;padding:16px 26px;display:inline-flex;align-items:center" href="{{ route('testimonials.create', $topic ? ['topic' => $topic->id] : []) }}">{{ $c['topic_testimonial_label'] }}</a>
    </div>
  </div>
</section>
</x-layout>
