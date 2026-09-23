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
        @foreach ($products->groupBy('category') as $category => $items)
          <div class="pa-card" style="margin-bottom:24px">
            <div class="pa-card-head"><h3>{{ $category ?? 'Other' }}</h3></div>
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
            <div style="border-top:1px solid var(--pa-line);padding-top:16px;margin-bottom:18px">
              <div class="pa-spread" style="font-size:14px;margin-bottom:7px;gap:10px"><span style="color:var(--pa-muted)">Basket</span><span style="font-weight:700" id="order-subtotal">R 0.00</span></div>
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
      function recalc() {
        let subtotal = 0;
        inputs.forEach(i => { subtotal += (parseInt(i.value, 10) || 0) * parseFloat(i.dataset.price); });
        document.getElementById('order-subtotal').textContent = 'R ' + subtotal.toFixed(2);
        document.getElementById('order-total').textContent = 'R ' + (subtotal + deliveryFee).toFixed(2);
      }
      inputs.forEach(i => i.addEventListener('input', recalc));

      const preselect = new URLSearchParams(location.search).get('product');
      if (preselect) {
        const field = document.querySelector(`input[name="qty[${preselect}]"]`);
        if (field && parseInt(field.value, 10) === 0) { field.value = 1; recalc(); }
      }
    })();
  </script>
@endguest
</x-layout>
