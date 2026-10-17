<x-layout title="Invoices & claims · Prompt Aid">
<div class="pa-account">
<x-account-nav active="invoices" />
<div>
  <div class="pa-spread" style="margin-bottom:22px">
    <div><h1 style="font-size:32px">Invoices &amp; claims</h1><p class="pa-muted" style="margin:6px 0 0">What you owe, and where each scheme claim sits.</p></div>
  </div>

  @php
    $outstanding = $invoices->whereNotIn('status', ['paid', 'refunded']);
    $paid = $invoices->where('status', 'paid');
  @endphp
  <div class="pa-grid" style="grid-template-columns:repeat(auto-fit,minmax(190px,1fr));margin-bottom:24px">
    <div class="pa-stat"><div class="k">Outstanding</div><div class="v">R{{ number_format($outstanding->sum('total'), 0) }}</div><div class="d {{ $outstanding->count() ? 'stop' : 'go' }}">{{ $outstanding->count() }} invoice(s)</div></div>
    <div class="pa-stat"><div class="k">Paid</div><div class="v">R{{ number_format($paid->sum('total'), 0) }}</div><div class="d flat">{{ $paid->count() }} invoice(s)</div></div>
    <div class="pa-stat"><div class="k">Claims</div><div class="v">{{ $invoices->filter(fn ($i) => $i->claim)->count() }}</div><div class="d flat">Submitted to scheme</div></div>
  </div>

  <div class="pa-tablewrap"><table class="pa-table">
    <thead><tr><th>Invoice</th><th>Provider</th><th>Status</th><th>Claim</th><th>Amount</th><th></th></tr></thead>
    <tbody>
      @forelse ($invoices as $invoice)
        <tr>
          <td><div class="t-main">{{ $invoice->invoice_no }}</div><div class="t-sub">{{ $invoice->created_at->format('d M Y') }}</div></td>
          <td>{{ $invoice->clinic->name ?? '—' }}</td>
          <td><span class="pa-badge {{ $invoice->status === 'paid' ? 'is-go' : ($invoice->status === 'refunded' ? 'is-ink' : 'is-stop') }}">{{ str($invoice->status)->headline() }}</span></td>
          <td>
            @if ($invoice->claim)
              <span class="pa-badge {{ in_array($invoice->claim->status, ['accepted', 'part_paid']) ? 'is-go' : ($invoice->claim->status === 'rejected' ? 'is-stop' : 'is-wait') }}">{{ str($invoice->claim->status)->headline() }}</span>
            @else
              <span class="pa-muted" style="font-size:12px">No claim</span>
            @endif
          </td>
          <td>R{{ number_format($invoice->total, 2) }}</td>
          <td class="t-right">
            <a class="pa-btn-ghost pa-btn-sm" href="{{ route('account.invoices.print', $invoice) }}" target="_blank" rel="noopener">View / print</a>
            @if (! in_array($invoice->status, ['paid', 'refunded'], true) && (float) $invoice->total > 0)
              <button type="button" class="pa-btn pa-btn-sm" style="margin-top:6px" data-pa-pay="invoice" data-id="{{ $invoice->id }}" data-amount="{{ $invoice->total }}" data-title="Invoice {{ $invoice->invoice_no }}">Pay now</button>
            @endif
          </td>
        </tr>
      @empty
        <tr><td colspan="6"><p class="pa-muted" style="padding:24px;text-align:center;font-size:14px">No invoices yet.</p></td></tr>
      @endforelse
    </tbody>
  </table></div>

  @if (isset($rideCharges) && $rideCharges->isNotEmpty())
    <h2 style="font-size:22px;margin:32px 0 14px">Shuttle fees due</h2>
    <div class="pa-tablewrap"><table class="pa-table">
      <thead><tr><th>Trip</th><th>Type</th><th>Amount</th><th></th></tr></thead>
      <tbody>
        @foreach ($rideCharges as $charge)
          <tr>
            <td><div class="t-main">{{ $charge->ride->ride_ref ?? 'Trip' }}</div><div class="t-sub">{{ $charge->created_at->format('d M Y') }}</div></td>
            <td>{{ $charge->typeLabel() }}</td>
            <td>R{{ number_format($charge->amount, 2) }}</td>
            <td class="t-right"><button type="button" class="pa-btn pa-btn-sm" data-pa-pay="ride_charge" data-id="{{ $charge->id }}" data-amount="{{ $charge->amount }}" data-title="{{ $charge->typeLabel() }}">Pay now</button></td>
          </tr>
        @endforeach
      </tbody>
    </table></div>
  @endif
</div>
</div>
@include('payments.sheet')
</x-layout>
