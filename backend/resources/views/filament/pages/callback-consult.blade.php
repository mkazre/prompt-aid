@php
  $c = $this->card();
  $cb = $c['callback'] ?? null;
  $p = $c['patient'];
  $t = $c['triage'];
  $levelColours = ['red' => '#D0211C', 'orange' => '#E4701E', 'yellow' => '#D9A400', 'green' => '#1E8E3E'];
  $levelColour = $t ? ($levelColours[$t['level']] ?? '#6B675F') : '#6B675F';
  $who = implode(' · ', array_filter([$p['age'] !== null ? $p['age'].' yrs' : null, $p['sex']]));
  $presenting = $t ? array_merge($t['symptoms'], $t['discriminators']) : [];
  $dueText = ($cb && $cb['due_at']) ? \Illuminate\Support\Carbon::parse($cb['due_at'])->setTimezone('Africa/Johannesburg')->format('H:i') : null;
  $box = 'background:#fff;border:1px solid #e5e5ea;border-radius:16px;padding:20px;color:#101012';
  $btn = 'display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:48px;padding:0 20px;border-radius:12px;font-weight:800;font-size:15px;text-decoration:none;border:0;cursor:pointer';
  $lbl = 'font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#6B675F;margin:0 0 4px';
@endphp
<x-filament-panels::page>
  <div wire:poll.15s style="display:grid;gap:16px;max-width:880px">

    @if (! $c['is_open'])
      <div style="{{ $box }};background:#F3F1EC">
        <strong>This consult is closed</strong> ({{ str_replace('_', ' ', $c['status']) }}{{ $cb && $cb['outcome'] ? ' · '.str_replace('_', ' ', $cb['outcome']) : '' }}).
        <a href="{{ url('/staff/live-consults') }}" style="color:#D0211C;font-weight:700;margin-left:6px">Back to Live consults</a>
      </div>
    @endif

    <div style="{{ $box }}">
      <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-start">
        <div>
          <p style="{{ $lbl }}">{{ $c['mode'] === 'callback' ? 'Call back' : ucfirst(str_replace('_', ' ', $c['mode'])) }} · {{ $c['booking_ref'] }}</p>
          <div style="font-size:24px;font-weight:900">{{ $p['name'] }}</div>
          @if ($who)<div style="color:#3f3f46;font-size:14px;margin-top:2px">{{ $who }}</div>@endif
        </div>
        @if ($t)
          <span style="background:{{ $levelColour }};color:#fff;border-radius:999px;padding:6px 14px;font-weight:900;font-size:13px">{{ $t['level_label'] }}</span>
        @endif
      </div>

      @if ($t)
        <div style="margin-top:14px;font-size:14px;line-height:1.6">
          @if ($presenting)<div><strong>Presenting:</strong> {{ implode(', ', $presenting) }}</div>@endif
          @if ($t['reasons'])<div><strong>Why this colour:</strong> {{ implode('; ', $t['reasons']) }}</div>@endif
          @if ($t['pregnant'])<div><strong>Pregnant</strong></div>@endif
          <div style="color:#6B675F;font-size:12px">Triage {{ $t['reference'] }}</div>
        </div>
      @endif
      @if ($p['allergies'] || $p['chronic_conditions'])
        <div style="margin-top:10px;font-size:13px;color:#3f3f46">
          @if ($p['allergies'])<div><strong>Allergies:</strong> {{ $p['allergies'] }}</div>@endif
          @if ($p['chronic_conditions'])<div><strong>Chronic:</strong> {{ $p['chronic_conditions'] }}</div>@endif
        </div>
      @endif
    </div>

    @if ($cb)
      <div style="{{ $box }}">
        <p style="{{ $lbl }}">Call the patient</p>
        <div style="font-size:28px;font-weight:900;letter-spacing:.02em">{{ $cb['phone_display'] ?? 'No number on file' }}</div>
        <div style="color:#3f3f46;font-size:14px;margin:4px 0 12px">Prefers: <strong>{{ $cb['channel_label'] }}</strong>
          @if ($dueText) · call by <strong style="color:{{ $cb['overdue'] ? '#D0211C' : '#101012' }}">{{ $dueText }}</strong>{{ $cb['overdue'] ? ' (overdue)' : '' }}@endif
        </div>
        @if ($cb['note'])<div style="background:#FFF7E8;border-radius:10px;padding:10px 12px;font-size:14px;margin-bottom:12px"><strong>Patient's note:</strong> {{ $cb['note'] }}</div>@endif

        @if ($c['is_open'] && $cb['tel_url'])
          <div style="display:flex;gap:10px;flex-wrap:wrap">
            <a href="{{ $cb['tel_url'] }}" wire:click="calling('phone')" style="{{ $btn }};background:#1E8E3E;color:#fff">Call {{ $cb['phone_display'] }}</a>
            <a href="{{ $cb['whatsapp_url'] }}" target="_blank" rel="noopener" wire:click="calling('whatsapp')" style="{{ $btn }};background:#1FA855;color:#fff">WhatsApp</a>
            @if ($c['can_start_video'])
              <button type="button" wire:click="startVideo" wire:confirm="Switch to a video consult? The patient's phone will ring in the Prompt Aid app / browser." style="{{ $btn }};background:#101012;color:#fff">Start video instead</button>
            @endif
          </div>
          <p style="font-size:12px;color:#6B675F;margin:10px 0 0">{{ $cb['whatsapp_note'] }}</p>
        @endif

        <div style="margin-top:14px;font-size:13px;color:#3f3f46">
          @if ($cb['called_at'])
            First call {{ \Illuminate\Support\Carbon::parse($cb['called_at'])->setTimezone('Africa/Johannesburg')->format('H:i') }} · {{ $cb['call_attempts'] }} attempt{{ $cb['call_attempts'] === 1 ? '' : 's' }}
          @else
            Not called yet — the patient was told to expect your call within {{ $cb['target_minutes'] }} minutes.
          @endif
          @if ($cb['outcome']) · last outcome: <strong>{{ str_replace('_', ' ', $cb['outcome']) }}</strong>@endif
        </div>
      </div>

      @if ($c['is_open'])
        <div style="{{ $box }}">
          <p style="{{ $lbl }}">After the call</p>
          <div style="display:flex;gap:10px;flex-wrap:wrap">
            <button type="button" wire:click="outcome('completed')" wire:confirm="Mark this consult as done?" style="{{ $btn }};background:#1E8E3E;color:#fff">Called — consult done</button>
            <button type="button" wire:click="outcome('no_answer')" style="{{ $btn }};background:#F3F1EC;color:#101012">No answer — retry</button>
            <button type="button" wire:click="outcome('escalated')" wire:confirm="Tell the patient to call an ambulance now and close this consult?" style="{{ $btn }};background:#D0211C;color:#fff">Escalate: advise ambulance</button>
          </div>
        </div>
      @endif
    @elseif ($c['video_call_url'])
      <div style="{{ $box }}">
        This is a video consult. <a href="{{ $c['video_call_url'] }}" style="color:#D0211C;font-weight:800">Open the video call</a>
      </div>
    @endif
  </div>
</x-filament-panels::page>
