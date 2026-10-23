@php
  $cssUrl = asset('assets/css/pa-video-call.css').'?v=2';
  $jsUrl = asset('assets/js/pa-video-call.js').'?v=2';
  $pageTitle = 'Video consult · Prompt Aid';
@endphp
<!doctype html>
<html lang="en" class="pa-call-page">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="robots" content="noindex, nofollow">
  <meta name="referrer" content="strict-origin">
  <meta name="theme-color" content="#0b0b0d">
  <title>{{ $pageTitle }}</title>
  <link rel="stylesheet" href="{{ $cssUrl }}">
</head>
<body>
  @include('video.partials.stage', [
    'config' => $config,
    'messageStartUrl' => $messageStartUrl ?? null,
    'appointmentId' => $appointment->id,
  ])
  <script src="{{ $jsUrl }}"></script>
</body>
</html>
