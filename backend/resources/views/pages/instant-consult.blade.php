<x-layout title="See a doctor in 30 minutes · Prompt Aid" :noTriage="true">
@push('meta')
  @if ($current && $current->status === 'matching')<meta http-equiv="refresh" content="20">@endif
@endpush
<div class="pa-pagehead"><div class="inner">
  <div class="pa-crumb"><a href="{{ url('/') }}">Home</a> <span style="color:#CFC8B8">/</span> See a doctor now</div>
  <div class="pa-row" style="margin-bottom:12px"><span class="pa-blip"></span><span class="pa-eyebrow">PromptAid Emergency Scale · Orange</span></div>
  <h1>Get connected to a doctor in 30 minutes</h1>
  <p class="pa-muted" style="font-size:15px;margin:6px 0 24px;max-width:640px">We send your request to every GP who is flagged <strong style="color:#1F7A4C">green</strong> right now. The first GP to accept is booked with you 30 minutes later.</p>
</div></div>

<div class="pa-container" style="padding-top:32px;padding-bottom:80px;max-width:760px">
  @if (session('success'))
    <div class="pa-note" style="margin-bottom:18px;border-color:var(--pa-go);color:var(--pa-go)">{{ session('success') }}</div>
  @endif
  @if ($errors->any())
    <div class="pa-note" style="margin-bottom:18px;border-color:var(--pa-signal);color:var(--pa-signal)">{{ $errors->first() }}</div>
  @endif

  <div class="pa-note-stop" style="margin-bottom:22px"><strong>Not breathing, chest pain or heavy bleeding?</strong> Don't wait — call the PromptAid dispatch centre on <a href="{{ \App\Support\SiteLinks::dispatchTel() }}" style="color:inherit">{{ \App\Support\SiteLinks::dispatchPhone() }}</a>.</div>

  @if ($current && $current->status === 'matching')
    <div class="pa-pop" style="padding:28px">
      <div class="pa-eyebrow" style="margin-bottom:8px">Request {{ $current->booking_ref }}</div>
      <h3 style="margin-bottom:8px">Waiting for a GP to accept…</h3>
      <p class="pa-muted" style="font-size:14px">Sent {{ $current->created_at->diffForHumans() }} · {{ $current->mode === 'video' ? 'Video consult' : 'In person' }}. This page refreshes on its own.</p>
      @if ($current->created_at->lt(now()->subMinutes(15)))
        <p style="font-size:14px">No GP has accepted yet. You can keep waiting, <a href="{{ route('doctors.index') }}">book a doctor manually</a>, or <a href="{{ $whatsappUrl }}" target="_blank" rel="noopener">ask a doctor on WhatsApp</a>.</p>
      @endif
      <form method="POST" action="{{ route('instant-consult.cancel', $current) }}" style="margin-top:14px">@csrf<button class="pa-btn-ghost" type="submit">Cancel request</button></form>
    </div>
  @elseif ($current && $current->doctor)
    <div class="pa-pop" style="padding:28px;border-left:6px solid var(--pa-go)">
      <div class="pa-eyebrow" style="margin-bottom:8px;color:var(--pa-go)">Booked · {{ $current->booking_ref }}</div>
      <h3 style="margin-bottom:8px">Dr {{ $current->doctor->user->name }} will see you at {{ substr($current->start_time, 0, 5) }}</h3>
      <p class="pa-muted" style="font-size:14px">{{ $current->mode === 'video' ? 'Video consult — the join link appears in your appointments.' : 'In person'.($current->clinic ? ' at '.$current->clinic->name.', '.$current->clinic->address : '').'.' }}</p>
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px">
        <a class="pa-btn" href="{{ route('account.appointments') }}">My appointments</a>
        @if ($current->mode !== 'video')<a class="pa-btn-ghost" href="{{ route('shuttle') }}"><span class="pa-tick-beacon" style="width:6px;height:6px"></span>Add a shuttle</a>@endif
      </div>
    </div>
  @elseif (! auth()->check())
    <div class="pa-pop" style="padding:28px">
      <h3 style="margin-bottom:8px">Log in to request a doctor</h3>
      <p class="pa-muted" style="font-size:14px;margin-bottom:16px">We need your patient account so the GP can see who they are booked with. You'll come straight back here.</p>
      <div style="display:flex;gap:10px;flex-wrap:wrap"><a class="pa-btn" href="{{ route('login') }}">Log in</a><a class="pa-btn-ghost" href="{{ route('register.wizard.account') }}">Create an account</a></div>
    </div>
  @elseif (! $isPatient)
    <p class="pa-muted">Only patient accounts can request a doctor.</p>
  @elseif ($greenGpCount === 0)
    <div class="pa-pop" style="padding:28px">
      <h3 style="margin-bottom:8px">No GP is available within 30 minutes right now</h3>
      <p class="pa-muted" style="font-size:14px;margin-bottom:16px">Every GP is currently orange or red. You can book the next available slot today, or ask a doctor on WhatsApp.</p>
      <div style="display:flex;gap:10px;flex-wrap:wrap"><a class="pa-btn" href="{{ route('doctors.index') }}">Book a doctor for today</a><a class="pa-btn-whatsapp" style="flex:0 1 auto" href="{{ $whatsappUrl }}" target="_blank" rel="noopener">ASK A DOCTOR</a></div>
    </div>
  @else
    <form method="POST" action="{{ route('instant-consult.store') }}" class="pa-pop" style="padding:28px">
      @csrf
      <div class="pa-row" style="margin-bottom:16px"><span class="pa-status is-green">{{ $greenGpCount }} {{ \Illuminate\Support\Str::plural('GP', $greenGpCount) }} available now</span></div>
      <div class="pa-label" style="margin-bottom:10px">How would you like to be seen?</div>
      <div class="pa-tabs" style="display:grid;grid-template-columns:1fr 1fr;margin-bottom:16px">
        <label class="pa-tab is-on" style="text-align:center;cursor:pointer"><input type="radio" name="mode" value="video" checked style="margin-right:6px">Video consult</label>
        <label class="pa-tab" style="text-align:center;cursor:pointer"><input type="radio" name="mode" value="in_person" style="margin-right:6px">In person</label>
      </div>
      <div class="pa-formrow"><label class="pa-label">What's wrong? (optional)</label><textarea class="pa-field" name="reason" rows="3" maxlength="500" placeholder="e.g. Severe pain since this morning">{{ old('reason') }}</textarea></div>
      <button type="submit" class="pa-btn pa-btn-block pa-btn-lg">Request a GP now</button>
    </form>
  @endif
</div>
</x-layout>
