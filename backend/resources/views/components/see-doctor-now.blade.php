{{-- "Don't want to wait for a slot?" — opens the PromptAid Emergency Scale popup. --}}
<div class="pa-dark" style="padding:18px 22px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:18px">
  <div>
    <div style="font-size:16px;font-weight:900">{{ \App\Support\SiteContent::get('care.see_now_title') }}</div>
    <div style="font-size:13px;color:#B8B3A8;margin-top:3px">{{ \App\Support\SiteContent::get('care.see_now_text') }}</div>
  </div>
  <a class="pa-btn-beacon" href="{{ route('emergency') }}" data-pa-open-triage>{{ \App\Support\SiteContent::get('care.see_now_button') }}</a>
</div>
