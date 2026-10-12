<x-layout :title="$pharmacy->name.' · Prompt Aid'">
<div class="pa-pagehead"><div class="inner">
  <div class="pa-crumb"><a href="{{ url('/') }}">Home</a> <span style="color:#CFC8B8">/</span> <a href="{{ route('pharmacies.index') }}">Pharmacies</a> <span style="color:#CFC8B8">/</span> {{ $pharmacy->name }}</div>
  <div style="display:grid;grid-template-columns:160px minmax(0,1fr);gap:28px;padding-bottom:28px">
    <div class="pa-avatar" style="width:160px;height:160px;font-size:40px">{{ mb_strtoupper(mb_substr($pharmacy->name, 0, 2)) }}</div>
    <div>
      <h1 style="margin-bottom:8px">{{ $pharmacy->name }}</h1>
      <p style="font-size:17px;color:var(--pa-ink-soft);margin-bottom:18px">{{ $pharmacy->address }}@if($pharmacy->city), {{ $pharmacy->city }}@endif</p>
      <div style="display:flex;gap:32px;flex-wrap:wrap;padding-top:18px;border-top:1px solid var(--pa-line)">
        <div><div class="pa-label" style="margin-bottom:4px">Delivery</div><div class="pa-num" style="font-size:22px">R {{ number_format($pharmacy->delivery_fee, 0) }}</div></div>
        @if ($pharmacy->rating_count)
          <div><div class="pa-label" style="margin-bottom:4px">Rating</div><div class="pa-num" style="font-size:22px">{{ number_format($pharmacy->rating_avg, 1) }} <span style="font-size:14px;color:var(--pa-muted);font-weight:400">/ {{ $pharmacy->rating_count }}</span></div></div>
        @endif
      </div>
    </div>
  </div>
</div></div>

@guest
  <div class="pa-container" style="padding-top:32px;padding-bottom:80px">
    <div class="pa-card pa-card-pad" style="text-align:center">
      <p class="pa-muted">Please <a href="{{ route('login') }}">sign in</a> to order from this pharmacy.</p>
    </div>
  </div>
@else
  <form method="POST" action="{{ route('pharmacies.checkout', $pharmacy) }}" enctype="multipart/form-data" id="pharmacy-order-form">
    @csrf
    <div class="pa-container" style="padding-top:32px;padding-bottom:80px;display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:32px;align-items:start">
      <div>
        @if ($errors->any())
          <div class="pa-note" style="margin-bottom:16px;border-color:var(--pa-signal);color:var(--pa-signal)">{{ $errors->first() }}</div>
        @endif
        @foreach ($products->groupBy(fn ($product) => $product->serviceCategories->pluck('name')->join(', ') ?: 'Other') as $category => $items)
          <div class="pa-card" style="margin-bottom:24px">
            <div class="pa-card-head"><h3>{{ $category ?: 'Other' }}</h3></div>
            @foreach ($items as $product)
              <div class="pa-spread" style="padding:16px 26px;border-bottom:1px solid var(--pa-line-soft)">
                <div>
                  <div style="font-size:15px;font-weight:700">{{ $product->name }}</div>
                  <div style="font-size:13px;color:var(--pa-muted);margin-top:2px">R {{ number_format($product->price, 2) }}@if($product->requires_prescription) · <span style="color:var(--pa-signal)">Prescription required</span>@endif</div>
                </div>
                <div style="display:flex;align-items:center;gap:8px">
                  <label class="pa-label" style="margin:0">Qty</label>
                  <input type="number" class="pa-field order-qty" data-price="{{ $product->price }}" name="qty[{{ $product->id }}]" min="0" max="{{ $product->stock }}" value="0" style="width:70px;text-align:center" />
                </div>
              </div>
            @endforeach
          </div>
        @endforeach
      </div>

      <div style="position:sticky;top:96px">
        <div class="pa-pop">
          <div style="padding:20px 22px;border-bottom:1px solid var(--pa-line)"><div class="pa-label" style="margin:0">Your order</div></div>
          <div style="padding:22px">
            <div class="pa-formrow"><label class="pa-label">Delivery address</label><input class="pa-field" type="text" name="delivery_address" required value="{{ auth()->user()->patientProfile->address ?? '' }}" /></div>
            <div class="pa-formrow"><label class="pa-label">Upload prescription (if required)</label><input class="pa-field" type="file" name="prescription" /></div>
            @if (\App\Support\CommerceSettings::bool('promo_codes_enabled'))
              <div class="pa-formrow">
                <label class="pa-label" for="promo-code-input">Promo code</label>
                <div style="display:flex;gap:8px">
                  <input class="pa-field" type="text" id="promo-code-input" name="promo_code" value="{{ old('promo_code') }}" placeholder="e.g. WELCOME10" autocomplete="off" style="text-transform:uppercase;flex:1;min-width:0" />
                  <button type="button" class="pa-btn-ghost pa-btn-sm" id="promo-apply" data-url="{{ route('promo-codes.validate') }}">Apply</button>
                </div>
                <div id="promo-msg" style="font-size:12px;margin-top:6px" aria-live="polite"></div>
              </div>
            @endif
            <div style="border-top:1px solid var(--pa-line);padding-top:16px;margin-bottom:18px">
              <div class="pa-spread" style="font-size:14px;margin-bottom:7px;gap:10px"><span style="color:var(--pa-muted)">Basket</span><span style="font-weight:700" id="order-subtotal">R 0.00</span></div>
              <div class="pa-spread" style="font-size:14px;margin-bottom:7px;gap:10px;display:none;color:var(--pa-go, #1F7A4C)" id="order-discount-row"><span>Discount <span id="order-discount-code"></span></span><span style="font-weight:700" id="order-discount">&minus; R 0.00</span></div>
              <div class="pa-spread" style="font-size:14px;margin-bottom:7px;gap:10px"><span style="color:var(--pa-muted)">Delivery</span><span style="font-weight:700">R {{ number_format($pharmacy->delivery_fee, 2) }}</span></div>
              <div class="pa-spread" style="border-top:1px solid var(--pa-line);padding-top:11px;align-items:baseline"><span class="pa-label" style="margin:0">You pay</span><span class="pa-num" style="font-size:27px" id="order-total">R {{ number_format($pharmacy->delivery_fee, 2) }}</span></div>
            </div>
            <button type="submit" class="pa-btn pa-btn-block pa-btn-lg">Place order</button>
          </div>
        </div>
      </div>
    </div>
  </form>
  <script>
    (function () {
      const deliveryFee = {{ (float) $pharmacy->delivery_fee }};
      const inputs = document.querySelectorAll('.order-qty');
      const form = document.getElementById('pharmacy-order-form');
      const promoInput = document.getElementById('promo-code-input');
      const promoBtn = document.getElementById('promo-apply');
      const promoMsg = document.getElementById('promo-msg');
      let discount = 0;
      let promoTimer = null;

      function recalc() {
        let subtotal = 0;
        inputs.forEach(i => { subtotal += (parseInt(i.value, 10) || 0) * parseFloat(i.dataset.price); });
        const d = Math.min(discount, subtotal);
        document.getElementById('order-subtotal').textContent = 'R ' + subtotal.toFixed(2);
        document.getElementById('order-discount-row').style.display = d > 0 ? '' : 'none';
        document.getElementById('order-discount').textContent = '\u2212 R ' + d.toFixed(2);
        document.getElementById('order-total').textContent = 'R ' + (subtotal - d + deliveryFee).toFixed(2);
      }

      async function applyPromo(silent) {
        if (!promoInput) return;
        const code = promoInput.value.trim();
        if (!code) { discount = 0; promoMsg.textContent = ''; recalc(); return; }
        const body = new FormData();
        body.append('code', code);
        body.append('pharmacy_id', '{{ $pharmacy->id }}');
        inputs.forEach(i => body.append(i.name, i.value || '0'));
        if (!silent) { promoBtn.disabled = true; promoMsg.textContent = 'Checking\u2026'; promoMsg.style.color = 'var(--pa-muted)'; }
        try {
          const res = await fetch(promoBtn.dataset.url, { method: 'POST', body, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': form.querySelector('input[name=_token]').value } });
          const json = await res.json().catch(() => ({}));
          if (res.ok && json.valid) {
            discount = parseFloat(json.discount) || 0;
            promoInput.value = json.code;
            document.getElementById('order-discount-code').textContent = '(' + json.code + ')';
            promoMsg.textContent = json.message;
            promoMsg.style.color = 'var(--pa-go, #1F7A4C)';
          } else {
            discount = 0;
            promoMsg.textContent = json.message || (json.errors ? Object.values(json.errors)[0][0] : 'That promo code is not valid.');
            promoMsg.style.color = 'var(--pa-signal)';
          }
        } catch (e) {
          discount = 0;
          promoMsg.textContent = 'Could not check the code. Please try again.';
          promoMsg.style.color = 'var(--pa-signal)';
        } finally {
          if (promoBtn) promoBtn.disabled = false;
          recalc();
        }
      }

      inputs.forEach(i => i.addEventListener('input', () => {
        recalc();
        if (promoInput && promoInput.value.trim()) { clearTimeout(promoTimer); promoTimer = setTimeout(() => applyPromo(true), 500); }
      }));
      if (promoBtn) {
        promoBtn.addEventListener('click', () => applyPromo(false));
        promoInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); applyPromo(false); } });
      }

      const preselect = new URLSearchParams(location.search).get('product');
      if (preselect) {
        const field = document.querySelector(`input[name="qty[${preselect}]"]`);
        if (field && parseInt(field.value, 10) === 0) { field.value = 1; recalc(); }
      }
      if (promoInput && promoInput.value.trim()) applyPromo(true);
    })();
  </script>
@endguest
</x-layout>
