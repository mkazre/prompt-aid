@php
    $mode = $checkout['mode'] ?? 'mock';
    $data = $checkout['data'] ?? [];
    $gatewayLabel = match ($intent->gateway) { 'payfast' => 'PayFast', 'paystack' => 'Paystack', 'ozow' => 'Ozow', default => 'Test payment' };
    $embedCfg = [
        'intent' => $intent->uuid,
        'mode' => $mode,
        'returnBase' => $returnBase,
        'payfastUuid' => $mode === 'payfast_onsite' ? ($data['uuid'] ?? null) : null,
        'paystackAccessCode' => $mode === 'paystack_inline' ? ($data['access_code'] ?? null) : null,
    ];
    $engineSrc = $mode === 'payfast_onsite' ? ($data['engine'] ?? 'https://www.payfast.co.za/onsite/engine.js') : null;
    $formAction = $mode === 'form_post' ? ($data['action'] ?? '') : '';
    $formFields = $mode === 'form_post' ? (array) ($data['fields'] ?? []) : [];
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Secure payment · PromptAid</title>
<style>
  :root{--ink:#101012;--muted:#6E6A62;--line:#E0DACB;--signal:#D0211C;--go:#1F7A4C}
  *{box-sizing:border-box}
  html,body{margin:0;background:#fff;color:var(--ink);font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;-webkit-font-smoothing:antialiased}
  .wrap{min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:28px 20px;text-align:center}
  .brand{font-weight:900;letter-spacing:-.02em;font-size:15px;margin-bottom:22px;display:flex;align-items:center;gap:8px}
  .brand i{display:inline-block;width:10px;height:10px;background:var(--signal)}
  .amt{font-size:34px;font-weight:900;letter-spacing:-.03em;margin:4px 0}
  .desc{color:var(--muted);font-size:14px;margin:0 0 22px;max-width:340px}
  .spin{width:34px;height:34px;border:3px solid #eee;border-top-color:var(--ink);border-radius:50%;animation:s 0.8s linear infinite;margin:6px auto 14px}
  @keyframes s{to{transform:rotate(360deg)}}
  .note{font-size:13px;color:var(--muted)}
  .btn{display:block;width:100%;max-width:340px;margin:10px auto 0;padding:15px 18px;border:0;background:var(--ink);color:#fff;font:700 15px/1 inherit;font-family:inherit;cursor:pointer;border-radius:2px}
  .btn.ghost{background:#fff;color:var(--ink);border:1px solid var(--line)}
  .test{display:inline-block;font-size:11px;font-weight:900;letter-spacing:.12em;text-transform:uppercase;background:#FEF6DA;border:1px solid #EBD98A;padding:5px 9px;margin-bottom:14px}
  .lock{font-size:12px;color:var(--muted);margin-top:22px}
  [hidden]{display:none!important}
</style>
</head>
<body>
<div class="wrap">
  <div class="brand"><i></i>PromptAid</div>
  <div class="amt">R {{ number_format((float) $intent->amount, 2) }}</div>
  <p class="desc">{{ $intent->description }}</p>

  @if ($mode === 'mock')
    <span class="test">Test mode · no real money</span>
    <form method="POST" action="{{ $mockUrl }}" id="mock-form">
      <input type="hidden" name="outcome" value="success" id="mock-outcome">
      <button class="btn" type="submit">Pay R {{ number_format((float) $intent->amount, 2) }}</button>
      <button class="btn ghost" type="submit" onclick="document.getElementById('mock-outcome').value='cancel'">Cancel</button>
    </form>
    <p class="note" style="margin-top:14px">Online payments are not switched on yet. This simulates a successful card payment.</p>
  @elseif ($mode === 'form_post')
    <div class="spin"></div>
    <p class="note">Opening secure {{ $gatewayLabel }} checkout…</p>
    <form method="POST" action="{{ $formAction }}" id="gw-form">
      @foreach ($formFields as $name => $value)
        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
      @endforeach
      <button class="btn" type="submit" id="gw-continue" hidden>Continue to {{ $gatewayLabel }}</button>
    </form>
  @else
    <div id="loading"><div class="spin"></div><p class="note">Opening secure {{ $gatewayLabel }} checkout…</p></div>
    <div id="reopen" hidden>
      <p class="note">The payment window was closed.</p>
      <button class="btn" type="button" id="reopen-btn">Pay with {{ $gatewayLabel }}</button>
      <button class="btn ghost" type="button" id="cancel-btn">Cancel</button>
    </div>
    <p class="note" id="err" hidden></p>
  @endif

  <div class="lock">&#128274; Card and bank details are entered on {{ $gatewayLabel }}'s secure page — PromptAid never sees them.</div>
</div>

<script>
(function () {
  var cfg = @json($embedCfg);
  function notify(event, extra) {
    var msg = JSON.stringify(Object.assign({ type: 'pa-payment', event: event, intent: cfg.intent }, extra || {}));
    try { if (window.ReactNativeWebView) window.ReactNativeWebView.postMessage(msg); } catch (e) {}
    try { if (window.parent && window.parent !== window) window.parent.postMessage(msg, window.location.origin); } catch (e) {}
  }
  function go(outcome) { window.location.replace(cfg.returnBase + '/' + outcome); }
  function show(id, on) { var el = document.getElementById(id); if (el) el.hidden = !on; }
  function fail(text) { show('loading', false); var e = document.getElementById('err'); if (e) { e.textContent = text; e.hidden = false; } show('reopen', true); }

  notify('loaded');

  if (cfg.mode === 'form_post') {
    var f = document.getElementById('gw-form');
    setTimeout(function () { show('gw-continue', true); }, 4000);
    if (f) f.submit();
    return;
  }

  function loadScript(src, cb) {
    var s = document.createElement('script');
    s.src = src; s.async = true;
    s.onload = cb; s.onerror = function () { fail('Could not reach the payment provider. Check your connection and try again.'); };
    document.head.appendChild(s);
  }

  function openPayFast() {
    show('loading', true); show('reopen', false);
    if (typeof window.payfast_do_onsite_payment !== 'function') { fail('PayFast did not load.'); return; }
    window.payfast_do_onsite_payment({ uuid: cfg.payfastUuid }, function (result) {
      if (result === true) { go('success'); } else { show('loading', false); show('reopen', true); notify('closed'); }
    });
  }

  function openPaystack() {
    show('loading', true); show('reopen', false);
    if (typeof window.PaystackPop !== 'function') { fail('Paystack did not load.'); return; }
    var popup = new window.PaystackPop();
    popup.resumeTransaction(cfg.paystackAccessCode, {
      onSuccess: function () { go('success'); },
      onCancel: function () { show('loading', false); show('reopen', true); notify('closed'); },
      onError: function (e) { fail((e && e.message) || 'Paystack could not process the payment.'); }
    });
  }

  var open = cfg.mode === 'payfast_onsite' ? openPayFast : cfg.mode === 'paystack_inline' ? openPaystack : null;
  var rb = document.getElementById('reopen-btn'); if (rb && open) rb.addEventListener('click', open);
  var cb = document.getElementById('cancel-btn'); if (cb) cb.addEventListener('click', function () { go('cancel'); });

  if (cfg.mode === 'payfast_onsite') loadScript(@json($engineSrc), openPayFast);
  else if (cfg.mode === 'paystack_inline') loadScript('https://js.paystack.co/v2/inline.js', openPaystack);
})();
</script>
</body>
</html>
