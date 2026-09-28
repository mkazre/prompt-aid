<x-layout title="Pharmacy orders · Prompt Aid">
<div class="pa-account">
<x-account-nav active="orders" />
<div>
  <div class="pa-spread" style="margin-bottom:22px">
    <div><h1 style="font-size:32px">Pharmacy orders</h1><p class="pa-muted" style="margin:6px 0 0">Medicine, devices and test kits you have ordered.</p></div>
    <div style="display:flex;gap:10px;flex-wrap:wrap"><a class="pa-btn-ghost" href="{{ route('account.prescriptions') }}">Prescriptions</a><a class="pa-btn" href="{{ route('shop.index') }}">Shop</a></div>
  </div>

  <div class="pa-tablewrap"><table class="pa-table">
    <thead><tr><th>Order</th><th>Pharmacy</th><th>Items</th><th>Status</th><th>Total</th><th></th></tr></thead>
    <tbody>
      @forelse ($orders as $order)
        <tr>
          <td><div class="t-main">{{ $order->order_no }}</div><div class="t-sub">{{ $order->created_at->format('d M Y') }} · {{ $order->delivery_address ? 'delivery' : 'collection' }}</div></td>
          <td>{{ $order->pharmacy->name }}</td>
          <td>{{ $order->items->count() }} item(s)</td>
          <td><span class="pa-badge {{ in_array($order->status, ['delivered', 'confirmed']) ? 'is-go' : ($order->status === 'cancelled' ? 'is-stop' : 'is-wait') }}">{{ str($order->status)->headline() }}</span></td>
          <td>R{{ number_format($order->total, 2) }}</td>
          <td class="t-right"><a class="pa-btn-ghost pa-btn-sm" href="{{ route('account.invoices') }}">Invoice</a></td>
        </tr>
      @empty
        <tr><td colspan="6"><p class="pa-muted" style="padding:24px;text-align:center;font-size:14px">No orders yet. <a href="{{ route('shop.index') }}">Browse the marketplace</a>.</p></td></tr>
      @endforelse
    </tbody>
  </table></div>
</div>
</div>
</x-layout>
