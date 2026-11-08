@php
  try {
      $appDl = (array) \App\Support\SiteContent::get('app_downloads', []);
  } catch (\Throwable $e) {
      $appDl = [];
  }
  $appAndroid = trim((string) ($appDl['android_url'] ?? ''));
  $appIos = trim((string) ($appDl['ios_url'] ?? ''));
  $appIsApk = ! empty($appDl['android_is_apk']);
  $appHeading = trim((string) ($appDl['heading'] ?? '')) ?: 'Get the app';
@endphp
@if ($appAndroid !== '' || $appIos !== '')
<div class="pa-appdl">
  <div class="ft">{{ $appHeading }}</div>
  <div class="pa-appdl-row">
    @if ($appAndroid !== '')
      <a class="pa-appdl-badge" href="{{ $appAndroid }}" @if ($appIsApk) download @else target="_blank" rel="noopener" @endif aria-label="{{ $appIsApk ? 'Download the Android app' : 'Get it on Google Play' }}">
        @if ($appIsApk)
          <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="#3DDC84" d="M17.6 9.48l1.84-3.18a.38.38 0 0 0-.66-.38l-1.86 3.22a11.4 11.4 0 0 0-9.84 0L5.22 5.92a.38.38 0 1 0-.66.38L6.4 9.48A10.8 10.8 0 0 0 1 18h22a10.8 10.8 0 0 0-5.4-8.52zM7 15.25a1.25 1.25 0 1 1 0-2.5 1.25 1.25 0 0 1 0 2.5zm10 0a1.25 1.25 0 1 1 0-2.5 1.25 1.25 0 0 1 0 2.5z"/></svg>
          <span><small>Download for</small>Android</span>
        @else
          <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="#34A853" d="M3.6 1.8 13.4 12l-9.8 10.2c-.4-.2-.6-.6-.6-1.1V2.9c0-.5.2-.9.6-1.1z"/><path fill="#FBBC04" d="M16.8 8.6 13.4 12l3.4 3.4 3.9-2.2c.8-.5.8-1.9 0-2.4z"/><path fill="#4285F4" d="M3.6 1.8c.3-.2.8-.2 1.2 0l12 6.8-3.4 3.4z"/><path fill="#EA4335" d="M13.4 12l3.4 3.4-12 6.8c-.4.2-.9.2-1.2 0z"/></svg>
          <span><small>Get it on</small>Google Play</span>
        @endif
      </a>
    @endif
    @if ($appIos !== '')
      <a class="pa-appdl-badge" href="{{ $appIos }}" target="_blank" rel="noopener" aria-label="Download on the App Store">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="#fff" d="M16.4 12.6c0-2.6 2.1-3.8 2.2-3.9-1.2-1.8-3.1-2-3.7-2-1.6-.2-3.1.9-3.9.9s-2-.9-3.4-.9a5 5 0 0 0-4.2 2.6c-1.8 3.1-.5 7.7 1.3 10.2.8 1.2 1.8 2.6 3.1 2.5 1.2 0 1.7-.8 3.2-.8s1.9.8 3.2.8 2.2-1.3 3-2.5a10.6 10.6 0 0 0 1.4-2.8c0-.1-2.6-1-2.6-4.1zM13.9 5c.7-.8 1.1-1.9 1-3-1 0-2.1.7-2.8 1.5-.6.7-1.2 1.8-1 2.9 1.1.1 2.1-.6 2.8-1.4z"/></svg>
        <span><small>Download on the</small>App Store</span>
      </a>
    @endif
  </div>
  @if ($appAndroid !== '' && $appIsApk)
    <p class="pa-appdl-note">Android: open the downloaded file and allow installs from your browser if your phone asks.</p>
  @endif
</div>
@endif
