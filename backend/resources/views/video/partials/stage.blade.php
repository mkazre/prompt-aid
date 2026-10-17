{{--
  In-page call UI shared by the website patient page, the staff consult page
  and the app embed. Inputs: $config (array, precomputed by
  VideoRoomService::callConfig), optional $extraClass, $messageStartUrl
  (website: POST to open the chat), $messageUrl (staff: link), $appointmentId,
  $summary (staff: patient summary rows), $summaryTitle.
--}}
@php
  $cfgJson = json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
  $mode = $config['mode'] ?? 'web';
  $counterpart = $config['counterpart'] ?? 'Consultation';
  $hasSummary = ! empty($summary ?? null);
  $messageStartUrl = $messageStartUrl ?? null;
  $messageUrl = $messageUrl ?? null;
  $appointmentId = $appointmentId ?? null;
  $summaryTitle = $summaryTitle ?? 'Patient summary';
  $extraClass = $extraClass ?? '';
@endphp
<div class="pa-call {{ $extraClass }}" data-pa-call data-mode="{{ $mode }}">
  <script type="application/json" data-call-config>{!! $cfgJson !!}</script>

  <header class="pa-call__top">
    <div class="pa-call__who">
      <span class="pa-call__dot" aria-hidden="true"></span>
      <div style="min-width:0">
        <span class="pa-call__name">{{ $counterpart }}</span>
        <span class="pa-call__status" data-call-status role="status" aria-live="polite">Connecting…</span>
      </div>
    </div>
    <span class="pa-call__timer" data-call-timer aria-label="Call duration">00:00</span>
  </header>

  <div class="pa-call__body">
    <div class="pa-call__stage" data-call-stage></div>
    <div class="pa-call__overlay" data-call-overlay>
      <div class="pa-call__spinner" data-call-overlay-spin aria-hidden="true"></div>
      <p data-call-overlay-msg>Connecting securely…</p>
      <div class="pa-call__overlay-actions" data-call-overlay-actions></div>
    </div>
    @if ($hasSummary)
      <aside class="pa-call__panel" data-call-panel aria-label="{{ $summaryTitle }}">
        <button type="button" class="pa-call__panel-close" data-call-panel-close aria-label="Close summary">&times;</button>
        <h3>{{ $summaryTitle }}</h3>
        <dl>
          @foreach ($summary as $label => $value)
            <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
          @endforeach
        </dl>
      </aside>
    @endif
  </div>

  <footer class="pa-call__controls" role="toolbar" aria-label="Call controls">
    <button type="button" class="pa-call__btn" data-call-mic aria-pressed="false" aria-label="Mute microphone" title="Mute microphone">
      <svg class="ico-on" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
      <svg class="ico-off" viewBox="0 0 24 24" aria-hidden="true"><line x1="1" y1="1" x2="23" y2="23"/><path d="M9 9v3a3 3 0 0 0 5.12 2.12M15 9.34V4a3 3 0 0 0-5.94-.6"/><path d="M17 16.95A7 7 0 0 1 5 12v-2m14 0v2a7 7 0 0 1-.11 1.23"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
    </button>
    <button type="button" class="pa-call__btn" data-call-cam aria-pressed="false" aria-label="Turn camera off" title="Turn camera off">
      <svg class="ico-on" viewBox="0 0 24 24" aria-hidden="true"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>
      <svg class="ico-off" viewBox="0 0 24 24" aria-hidden="true"><path d="M16 16v1a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h2m5.66 0H14a2 2 0 0 1 2 2v3.34l1 1L23 7v10"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
    </button>
    <button type="button" class="pa-call__btn pa-call__btn--end" data-call-end aria-label="Hang up" title="Hang up">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10.68 13.31a16 16 0 0 0 3.41 2.6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7 2 2 0 0 1 1.72 2v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.42 19.42 0 0 1-3.33-2.67m-2.67-3.34a19.79 19.79 0 0 1-3.07-8.63A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91"/><line x1="23" y1="1" x2="1" y2="23"/></svg>
    </button>
    @if ($messageStartUrl && $appointmentId)
      <form method="POST" action="{{ $messageStartUrl }}" target="_blank" style="margin:0;display:inline-flex">
        @csrf
        <input type="hidden" name="appointment_id" value="{{ $appointmentId }}">
        <button type="submit" class="pa-call__btn" aria-label="Message (opens in a new tab)" title="Message">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        </button>
      </form>
    @elseif ($messageUrl)
      <a class="pa-call__btn" href="{{ $messageUrl }}" target="_blank" rel="noopener" aria-label="Message (opens in a new tab)" title="Message">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
      </a>
    @endif
    @if ($hasSummary)
      <button type="button" class="pa-call__btn is-active" data-call-panel-toggle aria-expanded="true" aria-label="Patient summary" title="Patient summary">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      </button>
    @endif
  </footer>
</div>
