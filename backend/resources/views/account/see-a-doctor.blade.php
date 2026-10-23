<x-layout title="See a doctor now · Prompt Aid">
@php
  $dnConfig = [
      'base' => url('/account/see-a-doctor'),
      'quoteUrl' => route('account.doctor-now.quote'),
      'storeUrl' => route('account.doctor-now.store'),
      'current' => $current,
      'savedLocation' => $savedLocation,
      'online' => $online,
      'radiusKm' => $radiusKm,
      'enabled' => $enabled,
      'triageRef' => $triageRef,
      'callbackTarget' => $callbackTarget,
      'dispatchPhone' => $dispatch?->phone,
      'dispatchTel' => $dispatch?->tel_url,
  ];
  $dnPreMode = in_array($preMode, $modes, true) ? $preMode : ($modes[0] ?? 'video');
  $dnSpecSelected = $preSpecialty !== '' ? $preSpecialty : (string) $defaultSpecialty;
@endphp
<div class="pa-account">
<x-account-nav active="doctor-now" />
<div>
  <div class="pa-spread" style="margin-bottom:22px">
    <div>
      <div class="pa-eyebrow" style="margin-bottom:6px">Emergency Scale · Orange</div>
      <h1 style="font-size:32px">Speak to a doctor now</h1>
      <p class="pa-muted" style="margin:6px 0 0">Start a video consult or ask a doctor to call you back. Your request goes to every available doctor at once — the first to accept takes your consult.</p>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap"><a class="pa-btn-ghost" href="{{ route('dispatch.request-doctor') }}">Any doctor later today / tomorrow</a><a class="pa-btn-ghost" href="{{ route('doctors.index') }}">Book a time slot instead</a></div>
  </div>

  @if (! $enabled && ! $current)
    <div class="pa-note-stop">On-demand consultations are switched off right now. Please <a href="{{ route('doctors.index') }}">book a time slot</a> instead.</div>
  @elseif (empty($specialties) && ! $current)
    <div class="pa-note">No doctors are registered for on-demand consults yet. Please <a href="{{ route('doctors.index') }}">book a time slot</a> instead.</div>
  @else

  <div id="dn-error" class="pa-note-stop pa-hide" style="margin-bottom:16px" role="alert"></div>

  {{-- ============ 1. Request form ============ --}}
  <section id="dn-form" class="pa-card {{ $current ? 'pa-hide' : '' }}">
    <div class="pa-card-head"><h3>How should the doctor reach you?</h3><span class="pa-muted" style="font-size:12px">Doctors have {{ $offerSeconds }}s to accept</span></div>
    <div class="pa-card-body">
      @if ($triageRef !== '')
        <div class="pa-note" style="margin-bottom:16px">Linked to your triage result <strong>{{ $triageRef }}</strong> — the doctor sees your answers before they accept.</div>
      @endif
      @if ($dispatch)
        <div class="pa-note-stop" style="margin-bottom:16px">Not breathing, chest pain or heavy bleeding? Don't wait — call <a href="{{ $dispatch->tel_url }}" style="color:inherit;font-weight:900">{{ $dispatch->phone }}</a> now.</div>
      @endif

      <div class="pa-dn-modes" role="radiogroup" aria-label="How to speak to the doctor" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr))">
        @if (in_array('video', $modes, true))
        <label class="pa-dn-mode">
          <input type="radio" name="dn-mode" value="video" @checked($dnPreMode === 'video')>
          <span><strong style="font-size:17px">Video consult now</strong><span class="pa-muted">The doctor appears on your screen in minutes — your phone or computer rings and joins automatically.</span></span>
        </label>
        @endif
        @if (in_array('callback', $modes, true))
        <label class="pa-dn-mode">
          <input type="radio" name="dn-mode" value="callback" @checked($dnPreMode === 'callback')>
          <span><strong style="font-size:17px">Request a call back</strong><span class="pa-muted">A doctor phones you (or WhatsApp-calls you) within about {{ $callbackTarget }} minutes.</span></span>
        </label>
        @endif
      </div>
      <label style="display:flex;gap:8px;align-items:center;margin-top:10px;font-size:13px;color:var(--pa-ink-soft)">
        <input type="radio" name="dn-mode" value="in_person" @checked($dnPreMode === 'in_person')> I'd rather go to a doctor's rooms near me (in person)
      </label>

      <div id="dn-callback" class="pa-hide" style="margin-top:18px">
        <div class="pa-formgrid">
          <div>
            <label class="pa-label" for="dn-cb-phone">Number the doctor should call</label>
            <input class="pa-field" id="dn-cb-phone" type="tel" autocomplete="tel" maxlength="32" value="{{ $callbackPhone }}" placeholder="082 123 4567">
          </div>
          <div>
            <div class="pa-label">How should they call?</div>
            <div style="display:flex;gap:14px;flex-wrap:wrap;font-size:14px;padding-top:8px">
              <label><input type="radio" name="dn-cb-channel" value="phone"> Normal call</label>
              <label><input type="radio" name="dn-cb-channel" value="whatsapp"> WhatsApp call</label>
              <label><input type="radio" name="dn-cb-channel" value="either" checked> Either</label>
            </div>
          </div>
        </div>
        <label class="pa-label" for="dn-cb-note" style="margin-top:12px">Anything the doctor should know? (optional)</label>
        <textarea class="pa-field" id="dn-cb-note" rows="2" maxlength="500" placeholder="e.g. Burn on my hand from boiling water 20 minutes ago"></textarea>
      </div>

      <div class="pa-formgrid" style="margin-top:18px">
        <div>
          <label class="pa-label" for="dn-specialty">Doctor</label>
          <select class="pa-field" id="dn-specialty">
            @foreach ($specialties as $spec)
              <option value="{{ $spec }}" @selected(strcasecmp($spec, $dnSpecSelected) === 0)>{{ $spec }}{{ ($online[$spec] ?? 0) > 0 ? ' · '.$online[$spec].' available now' : ' · none online' }}</option>
            @endforeach
          </select>
        </div>
      </div>

      <div id="dn-inperson" class="pa-hide" style="margin-top:18px">
        <div class="pa-note" style="margin-bottom:12px">
          <strong>In person means you travel to the doctor's rooms.</strong> We'll match you with an available doctor whose practice is within {{ rtrim(rtrim(number_format($radiusKm, 1), '0'), '.') }} km of you, then show directions. Need a lift? Once you're matched you can book a Prompt Aid shuttle straight to the practice.
        </div>
        <div class="pa-label">Your location</div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
          <button type="button" class="pa-btn-ghost pa-btn-sm" id="dn-locate">Use my current location</button>
          @if ($savedLocation)
            <button type="button" class="pa-btn-ghost pa-btn-sm" id="dn-saved">Use my saved address</button>
          @endif
          <span id="dn-locstatus" class="pa-muted" style="font-size:13px">Location not set yet.</span>
        </div>
        @php
          $dnRadiusOptions = \App\Support\DispatchSettings::radiusOptions();
          $dnRadiusDefault = auth()->user()?->patientProfile?->search_radius_km !== null ? (int) auth()->user()->patientProfile->search_radius_km : (int) \App\Support\DispatchSettings::defaultRadius();
        @endphp
        <div style="margin-top:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
          <label class="pa-label" for="dn-radius" style="margin:0">Search within</label>
          <select class="pa-field" id="dn-radius" style="max-width:140px">
            @foreach ($dnRadiusOptions as $km)
              <option value="{{ $km }}" @selected($km === $dnRadiusDefault)>{{ $km }} km</option>
            @endforeach
          </select>
        </div>
      </div>

      <div id="dn-quote" class="pa-dn-quote" style="margin-top:22px" aria-live="polite">
        <div class="pa-muted" style="font-size:13px">Loading price…</div>
      </div>

      <div style="margin-top:22px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <button type="button" class="pa-btn pa-btn-lg" id="dn-request">Start video consult now</button>
        <span class="pa-muted" style="font-size:12px">You're only billed once a doctor accepts. Free to cancel until then.</span>
      </div>
    </div>
  </section>

  {{-- ============ 2. Searching ============ --}}
  <section id="dn-searching" class="pa-card pa-hide">
    <div class="pa-card-body" style="text-align:center;padding:36px 22px">
      <div class="pa-dn-radar" aria-hidden="true"><span></span><span></span><span></span></div>
      <h2 style="font-size:24px;margin:18px 0 6px" id="dn-search-title">Finding your doctor</h2>
      <p class="pa-muted" id="dn-search-line" style="margin:0 0 4px">Alerting available doctors…</p>
      <p class="pa-muted" id="dn-search-meta" style="margin:0;font-size:13px"></p>
      <div id="dn-none-online" class="pa-note pa-hide" style="margin:18px auto 0;max-width:520px;text-align:left"></div>
      <div style="margin-top:22px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
        <button type="button" class="pa-btn-ghost" id="dn-cancel-search">Cancel request</button>
      </div>
    </div>
  </section>

  {{-- ============ 3. Matched ============ --}}
  <section id="dn-matched" class="pa-card pa-hide">
    <div class="pa-card-head"><h3>You're matched</h3><span class="pa-badge is-go" id="dn-m-badge">Confirmed</span></div>
    <div class="pa-card-body">
      <div class="pa-dn-doc">
        <div class="pa-avatar pa-dn-photo" id="dn-m-photo"></div>
        <div style="min-width:0">
          <div style="font-size:20px;font-weight:900" id="dn-m-name"></div>
          <div class="pa-muted" id="dn-m-spec" style="font-size:14px"></div>
          <div id="dn-m-rating" style="font-size:13px;margin-top:4px"></div>
        </div>
      </div>
      <div id="dn-m-where" style="margin-top:18px"></div>
      <div class="pa-dn-actions" id="dn-m-actions"></div>
      <div id="dn-m-bill" style="margin-top:18px"></div>
      <form method="POST" action="{{ route('account.messages.start') }}" id="dn-msg-form" class="pa-hide">
        @csrf
        <input type="hidden" name="appointment_id" id="dn-msg-appt" value="">
      </form>
    </div>
  </section>

  {{-- ============ 4. Closed (expired / cancelled) ============ --}}
  <section id="dn-closed" class="pa-card pa-hide">
    <div class="pa-card-head"><h3 id="dn-c-title">No doctor available</h3></div>
    <div class="pa-card-body">
      <p id="dn-c-msg" style="margin:0 0 16px"></p>
      <div class="pa-dn-actions" id="dn-c-actions"></div>
    </div>
  </section>

  @endif
</div>
</div>

@include('payments.plan-offer', ['scope' => 'telehealth_membership', 'heading' => 'Become a member: cheaper consults, priority matching'])
@include('payments.sheet')
<script>window.PromptAidDoctorNow = @json($dnConfig);</script>
@push('scripts')
<script src="{{ asset('assets/js/doctor-now.js') }}?v=3"></script>
@endpush
</x-layout>
