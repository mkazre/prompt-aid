<x-layout title="Contact · Prompt Aid">
<div class="pa-pagehead"><div class="inner"><div class="pa-crumb"><a href="{{ url('/') }}">Home</a> <span style="color:#CFC8B8">/</span> Contact</div><h1>Contact</h1><p class="pa-muted" style="font-size:15px;margin:6px 0 24px">Emergencies go to 10177 or your nearest casualty. We are not an emergency service.</p></div></div>
<div class="pa-container" style="padding-top:36px;padding-bottom:80px;display:grid;grid-template-columns:minmax(0,1fr) 400px;gap:40px;align-items:start">
  <div>
    <div class="pa-grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));margin-bottom:28px">
      <div style="padding:22px"><div class="pa-label" style="margin-bottom:8px">Patient support</div><div style="font-size:16px;font-weight:700;margin-bottom:4px">{{ \App\Models\Setting::get('support_phone', '0800 776 678') }}</div><div style="font-size:13px"><a href="mailto:{{ \App\Models\Setting::get('support_email', 'help@promptaid.health') }}">{{ \App\Models\Setting::get('support_email', 'help@promptaid.health') }}</a></div><div style="font-size:12px;color:var(--pa-muted);margin-top:6px">Mon–Sun 06:00–22:00</div></div>
      <div style="padding:22px"><div class="pa-label" style="margin-bottom:8px">Provider support</div><div style="font-size:16px;font-weight:700;margin-bottom:4px">011 568 4120</div><div style="font-size:13px"><a href="mailto:providers@promptaid.health">providers@promptaid.health</a></div><div style="font-size:12px;color:var(--pa-muted);margin-top:6px">Mon–Fri 08:00–17:00</div></div>
      <div style="padding:22px"><div class="pa-label" style="margin-bottom:8px">Shuttle dispatch</div><div style="font-size:16px;font-weight:700;margin-bottom:4px">0800 776 679</div><div style="font-size:13px"><a href="mailto:rides@promptaid.health">rides@promptaid.health</a></div><div style="font-size:12px;color:var(--pa-muted);margin-top:6px">24 hours</div></div>
      <div style="padding:22px"><div class="pa-label" style="margin-bottom:8px">Data &amp; POPIA requests</div><div style="font-size:16px;font-weight:700;margin-bottom:4px">—</div><div style="font-size:13px"><a href="mailto:privacy@promptaid.health">privacy@promptaid.health</a></div><div style="font-size:12px;color:var(--pa-muted);margin-top:6px">Within 30 days</div></div>
    </div>
    <div class="pa-card pa-card-pad">
      <h3 style="margin-bottom:12px">Head office</h3>
      <p style="font-size:15px;color:var(--pa-ink-soft);margin-bottom:16px">Prompt Aid (Pty) Ltd<br />2nd floor, 158 Jan Smuts Avenue<br />Rosebank, Johannesburg, 2196<br />Reg. 2024/518402/07</p>
      <div class="pa-map-light" style="height:220px"><span style="position:absolute;left:50%;top:50%;width:14px;height:14px;background:var(--pa-signal);border-radius:999px;transform:translate(-50%,-50%)"></span></div>
    </div>
  </div>
  <div class="pa-pop" style="padding:26px">
    <h3 style="margin-bottom:16px">Send a message</h3>
    @if (session('success'))
      <div class="pa-note" style="margin-bottom:16px;border-color:var(--pa-go);color:var(--pa-go)">{{ session('success') }}</div>
    @endif
    <form method="POST" action="{{ route('contact.submit') }}">
      @csrf
      <div class="pa-formrow"><label class="pa-label">Your name</label><input class="pa-field" name="name" required value="{{ old('name') }}" /></div>
      <div class="pa-formrow"><label class="pa-label">Email</label><input class="pa-field" type="email" name="email" required value="{{ old('email') }}" /></div>
      <div class="pa-formrow"><label class="pa-label">About</label><select class="pa-field" name="subject">
        <option>A booking</option><option>A pharmacy order</option><option>A shuttle trip</option>
        <option>A medical scheme claim</option><option>Listing my practice</option><option>My data under POPIA</option><option>Something else</option>
      </select></div>
      <div class="pa-formrow"><label class="pa-label">Message</label><textarea class="pa-field" name="message" required>{{ old('message') }}</textarea></div>
      @error('message')<div style="color:var(--pa-signal);font-size:12px;margin-bottom:10px">{{ $message }}</div>@enderror
      <button type="submit" class="pa-btn pa-btn-block pa-btn-lg">Send</button>
      <div class="pa-note" style="margin-top:14px">Please do not send clinical details or test results through this form. Use your @auth<a href="{{ route('dashboard') }}">record</a>@else<a href="{{ route('login') }}">record</a>@endauth instead.</div>
    </form>
  </div>
</div>
</x-layout>
