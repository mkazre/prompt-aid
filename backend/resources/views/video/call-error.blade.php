@php
  $cssUrl = asset('assets/css/pa-video-call.css').'?v=1';
  $payload = json_encode(['type' => 'error', 'code' => $code, 'message' => $message], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
  $backUrl = route('account.appointments');
@endphp
<!doctype html>
<html lang="en" class="pa-call-page">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="robots" content="noindex, nofollow">
  <title>Video consult · Prompt Aid</title>
  <link rel="stylesheet" href="{{ $cssUrl }}">
</head>
<body>
  <div class="pa-call" data-mode="error">
    <div class="pa-call__body">
      <div class="pa-call__overlay">
        <p>{{ $message }}</p>
        <div class="pa-call__overlay-actions" data-web-only>
          <a class="pa-call__pill" href="{{ $backUrl }}">Back to appointments</a>
        </div>
      </div>
    </div>
  </div>
  <script>
    (function () {
      var msg = {!! $payload !!};
      if (window.ReactNativeWebView) {
        var el = document.querySelector('[data-web-only]');
        if (el) el.style.display = 'none';
        window.ReactNativeWebView.postMessage(JSON.stringify(msg));
      }
    })();
  </script>
</body>
</html>
