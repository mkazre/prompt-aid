{{--
  Reusable payment sheet (website). Include once on any page that has pay
  buttons:  @include('payments.sheet')
  Then either add  data-pa-pay="invoice|order|ride_charge" data-id=".." data-amount=".." data-title=".."
  (and optionally data-reload="0") to a button, or  data-pa-subscribe="{planId}" data-amount=".." data-title="..",
  or call  window.PromptAidPay.open({...}) / .subscribe({...}) from JS.
--}}
@once
@php
    $paPayCfg = [
        'optionsUrl' => route('payments.options'),
        'intentsUrl' => route('payments.intents.store'),
        'intentBase' => url('/payments/intents'),
        'subscribeUrl' => route('payments.subscriptions.store'),
        'csrf' => csrf_token(),
    ];
@endphp
<div class="pa-modal pa-pay" id="pa-pay" hidden role="dialog" aria-modal="true" aria-labelledby="pa-pay-title">
  <div class="pa-modal-box pa-pay-box">
    <div class="pa-modal-head">
      <div>
        <div class="pa-label" style="margin:0" id="pa-pay-kicker">Secure payment</div>
        <h3 id="pa-pay-title" style="font-size:20px;margin-top:4px">Pay</h3>
      </div>
      <div class="pa-pay-amount" id="pa-pay-amount"></div>
    </div>

    {{-- Step 1: choose a method --}}
    <div class="pa-modal-body pa-pay-step" data-step="choose">
      <div class="pa-pay-test" id="pa-pay-test" hidden>Test mode — online payments are not switched on yet, no real money moves.</div>
      <div class="pa-pay-methods" id="pa-pay-methods"><p class="pa-muted">Loading payment methods…</p></div>
      <label class="pa-pay-save" id="pa-pay-save-wrap" hidden>
        <input type="checkbox" id="pa-pay-save"> Save my card for future shuttle fares and fees
      </label>
      <div class="pa-pay-error" id="pa-pay-error" hidden></div>
      <button type="button" class="pa-btn pa-pay-go" id="pa-pay-go" disabled>Continue</button>
      <p class="pa-pay-fine">Card and bank details are entered on the provider's secure page. PromptAid never sees or stores them.</p>
    </div>

    {{-- Step 2: the gateway widget, embedded --}}
    <div class="pa-pay-step pa-pay-framewrap" data-step="frame" hidden>
      <iframe id="pa-pay-frame" class="pa-pay-frame" title="Secure payment" allow="payment *"></iframe>
      <div class="pa-pay-framebar">
        <button type="button" class="pa-btn-quiet pa-btn-sm" id="pa-pay-back">&larr; Change method</button>
        <a class="pa-btn-quiet pa-btn-sm" id="pa-pay-newtab" href="#" target="_blank" rel="noopener">Trouble loading? Open securely in a new tab</a>
      </div>
    </div>

    {{-- Step 3: confirming / done --}}
    <div class="pa-modal-body pa-pay-step pa-pay-result" data-step="result" hidden>
      <div class="pa-pay-ic" id="pa-pay-ic"><span class="pa-pay-spin"></span></div>
      <h3 id="pa-pay-rtitle" style="font-size:20px">Confirming your payment…</h3>
      <p class="pa-muted" id="pa-pay-rmsg">This usually takes a few seconds.</p>
      <button type="button" class="pa-btn" id="pa-pay-done" hidden>Done</button>
    </div>

    <div class="pa-modal-foot">
      <span class="pa-muted" style="font-size:12px">&#128274; Secured by our payment partners</span>
      <button type="button" class="pa-btn-ghost pa-btn-sm" id="pa-pay-close">Close</button>
    </div>
  </div>
</div>
<script>window.PromptAidPayConfig = @json($paPayCfg);</script>
@push('scripts')
<script src="{{ asset('assets/js/payments.js') }}"></script>
@endpush
@endonce
