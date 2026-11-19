@php
  $cssUrl = asset('assets/css/pa-video-call.css').'?v=3';
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
<body @if ($notice) class="has-notice" @endif>
  @if ($notice)
    <div class="pa-call-notice">● REC — {{ $notice }}</div>
  @endif
  @include('video.partials.stage', ['config' => $config, 'appointmentId' => null, 'extraClass' => 'pa-call--compact'])
  <script src="{{ $jsUrl }}"></script>
</body>
</html>
