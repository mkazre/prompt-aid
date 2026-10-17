@php
    $event = match ($outcome) { 'success' => 'completed', 'cancel' => 'cancelled', default => 'error' };
    $returnCfg = ['intent' => $intent->uuid, 'event' => $event, 'message' => $message];
    $icon = match ($outcome) { 'success' => '&#10003;', 'cancel' => '&#8592;', default => '!' };
    $iconColor = match ($outcome) { 'success' => '#1F7A4C', 'cancel' => '#6E6A62', default => '#D0211C' };
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Payment · PromptAid</title>
<style>
  html,body{margin:0;background:#fff;color:#101012;font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif}
  .wrap{min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:28px 20px;text-align:center}
  .ic{width:56px;height:56px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:26px;font-weight:900;margin-bottom:16px}
  p{color:#6E6A62;font-size:15px;max-width:320px;margin:0}
</style>
</head>
<body>
<div class="wrap">
  <div class="ic" style="background:{{ $iconColor }}">{!! $icon !!}</div>
  <p>{{ $message }}</p>
</div>
<script>
(function () {
  var cfg = @json($returnCfg);
  var msg = JSON.stringify({ type: 'pa-payment', event: cfg.event, intent: cfg.intent, message: cfg.message });
  try { if (window.ReactNativeWebView) window.ReactNativeWebView.postMessage(msg); } catch (e) {}
  try { if (window.parent && window.parent !== window) window.parent.postMessage(msg, window.location.origin); } catch (e) {}
})();
</script>
</body>
</html>
