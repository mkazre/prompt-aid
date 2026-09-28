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
            @if ($invoice->status !== 'paid')
              <span class="pa-muted" style="font-size:12px">Pay via reception</span>
            @endif
          </td>
        </tr>
      @empty
        <tr><td colspan="6"><p class="pa-muted" style="padding:24px;text-align:center;font-size:14px">No invoices yet.</p></td></tr>
      @endforelse
    </tbody>
  </table></div>
</div>
</div>
</x-layout>
