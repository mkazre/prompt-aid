{{--
  Consult hub UI shared by the patient website page (account/consult-hub)
  and the doctor's staff page (filament/pages/consult-hub). Inputs (all
  precomputed by the controller / Livewire page): $hub (payload),
  $hubConfig (JSON-safe array for pa-consult-hub.js), $callConfig (Jitsi
  config or null when the room isn't open), $role ('patient'|'doctor').
  Driven by public/assets/js/pa-consult-hub.js; the call itself by
  pa-video-call.js. Styles: pa-consult-hub.css + pa-video-call.css.
--}}
@php
  $hubJson = json_encode($hubConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
  $isDoctor = $role === 'doctor';
  $doc = $hub['doctor'];
  $headName = $isDoctor ? ($hub['patient']['name'] ?? 'Patient') : $doc['name'];
  $headSub = $isDoctor
      ? implode(' · ', array_filter([($hub['patient']['age'] ?? null) !== null ? $hub['patient']['age'].' yrs' : null, $hub['patient']['sex'] ?? null, $hub['booking_ref']]))
      : implode(' · ', array_filter([$doc['specialization'], $doc['qualification']]));
  $headPhoto = $isDoctor ? null : $doc['photo_url'];
  $headInitials = collect(preg_split('/\s+/', trim(preg_replace('/^Dr\.?\s*/', '', $headName))))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
  $ratingText = (! $isDoctor && $doc['rating_count'] > 0) ? '★ '.$doc['rating'].' ('.$doc['rating_count'].' reviews)' : null;
  $patient = $hub['patient'] ?? null;
  $triage = $hub['triage'] ?? null;
  $triagePresenting = $triage ? implode(', ', array_merge($triage['symptoms'], $triage['discriminators'])) : '';
  $fallback = $hub['fallback'] ?? null;
  $callbackRequested = (bool) ($hub['callback_requested'] ?? false);
  $callbackPhone = $hub['callback']['phone_display'] ?? '';
  $outcomes = $hub['outcomes'] ?? [];
  $emergency = $hub['emergency'] ?? null;
  $cssUrl = asset('assets/css/pa-consult-hub.css').'?v=1';
  $videoCssUrl = asset('assets/css/pa-video-call.css').'?v=2';
  $requestUrl = $hub['urls']['request'] ?? null;
@endphp
<link rel="stylesheet" href="{{ $videoCssUrl }}">
<link rel="stylesheet" href="{{ $cssUrl }}">
<div class="pa-hub" data-pa-hub data-role="{{ $role }}">
  <script type="application/json" data-hub-config>{!! $hubJson !!}</script>

  <header class="pa-hub__head">
    <div class="pa-hub__who">
      <div class="pa-hub__photo" aria-hidden="true">
        @if ($headPhoto)<img src="{{ $headPhoto }}" alt="">@else{{ $headInitials }}@endif
      </div>
      <div style="min-width:0">
        <div class="pa-hub__name">{{ $headName }}</div>
        @if ($headSub)<div class="pa-hub__sub">{{ $headSub }}</div>@endif
        @if ($ratingText)<div class="pa-hub__sub">{{ $ratingText }}</div>@endif
      </div>
    </div>
    <div class="pa-hub__state">
      <span class="pa-hub__status" data-hub-status role="status" aria-live="polite">{{ $hub['status_text'] }}</span>
      <span class="pa-hub__timer" data-hub-timer aria-label="Consult time">--:--</span>
    </div>
  </header>

  <div class="pa-hub__grid">
    <section class="pa-hub__main" aria-label="Connect">
      <div class="pa-hub__ring" data-hub-ring hidden>
        <strong data-hub-ring-title></strong>
        <span data-hub-ring-line></span>
        <div class="pa-hub__row">
          <button type="button" class="pa-hub__btn pa-hub__btn--go" data-hub-ring-join>Answer</button>
          <button type="button" class="pa-hub__btn pa-hub__btn--ghost" data-hub-ring-decline>Not now</button>
        </div>
      </div>

      <div class="pa-hub__channels" role="toolbar" aria-label="Connection options">
        <button type="button" class="pa-hub__ch" data-hub-ch="video">
          <svg viewBox="0 0 24 24" aria-hidden="true"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>
          <span><strong>{{ $isDoctor ? 'Start video' : 'Video call' }}</strong><small>{{ $isDoctor ? 'Rings the patient' : 'Recommended' }}</small></span>
        </button>
        <button type="button" class="pa-hub__ch" data-hub-ch="voice">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/></svg>
          <span><strong>{{ $isDoctor ? 'Start voice' : 'Voice call' }}</strong><small>In-app · uses data</small></span>
        </button>
        <button type="button" class="pa-hub__ch" data-hub-ch="chat">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
          <span><strong>Chat</strong><small>Messages &amp; photos</small></span>
        </button>
      </div>
      <p class="pa-hub__note" data-hub-note hidden></p>

      <div class="pa-hub__call" data-hub-call>
        @if ($callConfig)
          @include('video.partials.stage', ['config' => $callConfig, 'extraClass' => 'pa-call--hub'])
        @else
          <div class="pa-hub__nocall" data-hub-nocall>
            <p data-hub-nocall-text>{{ $hub['channels']['video']['reason'] ?? 'The call room is not open right now.' }}</p>
          </div>
        @endif
      </div>

      <details class="pa-hub__fallback" data-hub-fallback @if ($callbackRequested) open @endif>
        <summary>{{ $isDoctor ? 'Phone / WhatsApp fallback' : 'Connection problems? Phone or WhatsApp' }}</summary>
        @if ($isDoctor)
          @if ($fallback && $fallback['tel_url'])
            <p class="pa-hub__muted">Use only if the in-app call doesn't work. Patient's number: <strong>{{ $fallback['phone_display'] }}</strong></p>
            <div class="pa-hub__row">
              <a class="pa-hub__btn pa-hub__btn--go" href="{{ $fallback['tel_url'] }}" data-hub-calling="phone">Call {{ $fallback['phone_display'] }}</a>
              <a class="pa-hub__btn pa-hub__btn--wa" href="{{ $fallback['whatsapp_url'] }}" target="_blank" rel="noopener" data-hub-calling="whatsapp">WhatsApp</a>
            </div>
            <div class="pa-hub__row" style="margin-top:10px">
              <button type="button" class="pa-hub__btn pa-hub__btn--ghost" data-hub-connected="phone">Patient answered (phone)</button>
              <button type="button" class="pa-hub__btn pa-hub__btn--ghost" data-hub-connected="whatsapp">Patient answered (WhatsApp)</button>
            </div>
            <p class="pa-hub__muted" style="font-size:12px">{{ $fallback['whatsapp_note'] }}</p>
          @else
            <p class="pa-hub__muted">The patient has no phone number on file. Use video, voice or chat.</p>
          @endif
        @else
          <p class="pa-hub__muted">In-app video and voice work over Wi-Fi or mobile data. If they keep failing, ask the doctor to phone you instead.</p>
          <label class="pa-hub__label" for="pa-hub-cb-phone">Number the doctor should call</label>
          <input class="pa-hub__input" id="pa-hub-cb-phone" type="tel" autocomplete="tel" maxlength="32" value="{{ $callbackPhone }}" placeholder="082 123 4567" data-hub-cb-phone>
          <div class="pa-hub__row" style="margin-top:10px">
            <button type="button" class="pa-hub__btn pa-hub__btn--ghost" data-hub-callback="phone">Ask the doctor to phone me</button>
            <button type="button" class="pa-hub__btn pa-hub__btn--ghost" data-hub-callback="whatsapp">Ask for a WhatsApp call</button>
          </div>
          @if ($emergency && $emergency['tel_url'])
            <p class="pa-hub__muted" style="margin-top:12px">Getting worse? Call emergency services: <a href="{{ $emergency['tel_url'] }}"><strong>{{ $emergency['phone'] }}</strong></a> or 112 from a mobile.</p>
          @endif
        @endif
      </details>

      @if ($isDoctor)
        <form class="pa-hub__end" data-hub-end>
          <div class="pa-hub__label">End consult</div>
          <select class="pa-hub__input" name="outcome" required aria-label="Outcome">
            @foreach ($outcomes as $o)
              <option value="{{ $o['value'] }}">{{ $o['label'] }}</option>
            @endforeach
          </select>
          <textarea class="pa-hub__input" name="notes" rows="2" maxlength="2000" placeholder="Notes (optional, kept on the consult record)" aria-label="Notes"></textarea>
          <button type="submit" class="pa-hub__btn pa-hub__btn--end">End consult</button>
        </form>
      @elseif ($requestUrl)
        <p class="pa-hub__muted" style="margin-top:14px"><a href="{{ $requestUrl }}">Consult request, receipt &amp; payment</a></p>
      @endif
    </section>

    <aside class="pa-hub__side" aria-label="{{ $isDoctor ? 'Chat and patient summary' : 'Chat' }}">
      @if ($isDoctor)
        <div class="pa-hub__tabs" role="tablist">
          <button type="button" role="tab" class="is-active" aria-selected="true" data-hub-tab="chat">Chat</button>
          <button type="button" role="tab" aria-selected="false" data-hub-tab="summary">Patient summary</button>
        </div>
      @else
        <div class="pa-hub__tabs"><span class="is-active">Chat with {{ $doc['name'] }}</span></div>
      @endif

      <div class="pa-hub__chat" data-hub-panel="chat">
        <div class="pa-hub__msgs" data-hub-msgs aria-live="polite">
          <p class="pa-hub__muted" data-hub-msgs-empty>No messages yet. Say hello — you can also send a photo or PDF.</p>
        </div>
        <form class="pa-hub__compose" data-hub-compose>
          <label class="pa-hub__attach" title="Attach a photo or PDF">
            <input type="file" accept="image/*,application/pdf" data-hub-file hidden>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
            <span class="pa-call__sr">Attach a photo or PDF</span>
          </label>
          <textarea rows="1" maxlength="5000" placeholder="Type a message…" aria-label="Message" data-hub-text></textarea>
          <button type="submit" class="pa-hub__send" aria-label="Send">
            <svg viewBox="0 0 24 24" aria-hidden="true"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
          </button>
        </form>
        <div class="pa-hub__filechip" data-hub-filechip hidden></div>
      </div>

      @if ($isDoctor && $patient)
        <div class="pa-hub__summary" data-hub-panel="summary" hidden>
          <dl>
            <div><dt>Patient</dt><dd>{{ $patient['name'] }}</dd></div>
            @if ($patient['age'] !== null)<div><dt>Age</dt><dd>{{ $patient['age'] }}</dd></div>@endif
            @if ($patient['sex'])<div><dt>Sex</dt><dd>{{ $patient['sex'] }}</dd></div>@endif
            @if ($patient['blood_group'])<div><dt>Blood group</dt><dd>{{ $patient['blood_group'] }}</dd></div>@endif
            <div><dt>Reason</dt><dd>{{ $patient['reason'] ?: '—' }}</dd></div>
            @if ($triage)
              <div><dt>Triage</dt><dd>{{ $triage['level_label'] }} · {{ $triage['reference'] }}</dd></div>
              @if ($triagePresenting !== '')<div><dt>Presenting</dt><dd>{{ $triagePresenting }}</dd></div>@endif
              @if ($triage['reasons'])<div><dt>Why this colour</dt><dd>{{ implode('; ', $triage['reasons']) }}</dd></div>@endif
              @if ($triage['pregnant'])<div><dt>Pregnant</dt><dd>Yes</dd></div>@endif
            @endif
            <div><dt>Allergies</dt><dd>{{ $patient['allergies'] ?: 'None recorded' }}</dd></div>
            <div><dt>Chronic conditions</dt><dd>{{ $patient['chronic_conditions'] ?: 'None recorded' }}</dd></div>
            <div><dt>Medications</dt><dd>{{ $patient['medications'] ? implode(', ', $patient['medications']) : 'None recorded' }}</dd></div>
            @if ($patient['emergency_contact'])<div><dt>Emergency contact</dt><dd>{{ $patient['emergency_contact'] }}</dd></div>@endif
          </dl>
        </div>
      @endif
    </aside>
  </div>
</div>
