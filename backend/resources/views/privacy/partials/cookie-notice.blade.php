{{-- Essential-cookies notice. The public site loads no analytics or advertising
     trackers (checked: components/layout.blade.php), so a dismissible notice is
     sufficient — no consent gating needed. Dismissal is remembered in
     localStorage (not a cookie). --}}
@php
  $cookieNoticeOn = false;
  $cookieNoticeText = '';
  try {
      $cookieNoticeOn = \App\Support\PrivacySettings::bool('privacy_cookie_notice_enabled');
      $cookieNoticeText = \App\Support\PrivacySettings::get('privacy_cookie_notice_text');
  } catch (\Throwable $e) {
      $cookieNoticeOn = false;
  }
@endphp
@if ($cookieNoticeOn && $cookieNoticeText)
<div class="pa-cookie" id="pa-cookie" role="region" aria-label="Cookie notice" hidden>
  <div class="pa-cookie-text">{{ $cookieNoticeText }} <a href="{{ route('privacy') }}">Privacy policy</a></div>
  <button type="button" class="pa-btn pa-btn-sm" id="pa-cookie-ok">OK</button>
</div>
<script>
(function () {
  'use strict';
  var KEY = 'pa_cookie_notice_v1';
  var box = document.getElementById('pa-cookie');
  if (!box) return;
  try { if (window.localStorage.getItem(KEY)) return; } catch (e) {}
  box.hidden = false;
  document.getElementById('pa-cookie-ok').addEventListener('click', function () {
    box.hidden = true;
    try { window.localStorage.setItem(KEY, '1'); } catch (e) {}
  });
})();
</script>
@endif
