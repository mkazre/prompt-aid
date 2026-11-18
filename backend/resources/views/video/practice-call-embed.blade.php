@php
  $cssUrl = asset('assets/css/pa-video-call.css').'?v=2';
  $jsUrl = asset('assets/js/pa-video-call.js').'?v=2';
@endphp
<!doctype html>
<html lang="en" class="pa-call-page">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="robots" content="noindex, nofollow">
  <meta name="referrer" content="strict-origin">
  <title>Practice call · Prompt Aid</title>
  <link rel="stylesheet" href="{{ $cssUrl }}">
</head>
<body>
  @if ($notice)
    <div style="position:fixed;top:0;left:0;right:0;z-index:5;background:#7C2D12;color:#fff;font:600 12px/1.35 system-ui,sans-serif;padding:6px 10px">● REC — {{ $notice }}</div>
  @endif
  @include('video.partials.stage', ['config' => $config, 'appointmentId' => null])
  <script src="{{ $jsUrl }}"></script>
</body>
</html>
