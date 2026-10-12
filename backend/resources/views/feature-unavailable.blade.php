<x-layout :title="$label.' is not available · Prompt Aid'">
<div class="pa-pagehead"><div class="inner">
  <div class="pa-crumb"><a href="{{ url('/') }}">Home</a> <span style="color:#CFC8B8">/</span> {{ $label }}</div>
  <div class="pa-row" style="margin-bottom:12px"><span class="pa-tick"></span><span class="pa-eyebrow">Temporarily unavailable</span></div>
  <h1>{{ $label }} is not available right now</h1>
  <p class="pa-muted" style="font-size:16px;margin:10px 0 0;max-width:640px">We have switched this service off for the moment. Everything else on Prompt Aid is working as normal. Please check back soon, or contact us if you need help.</p>
</div></div>
<section class="pa-section pa-section-last">
  <div style="display:flex;gap:12px;flex-wrap:wrap">
    <a class="pa-btn" href="{{ url('/') }}">Back to the home page</a>
    <a class="pa-btn-ghost" href="{{ route('doctors.index') }}">Find a doctor</a>
    <a class="pa-btn-ghost" href="{{ route('contact') }}">Contact us</a>
  </div>
  <p class="pa-muted" style="margin-top:22px;font-size:14px">In an emergency call {{ \App\Models\Setting::get('support_phone', '0800 776 678') }} or use the red Emergency button at the top of the page.</p>
</section>
</x-layout>
