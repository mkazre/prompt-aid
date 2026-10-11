@php $orderItems = $d['items'] ?? []; @endphp
<div class="paw-card">
  @include('dashboard.widgets._head', ['w' => $w, 'link' => route('account.orders')])
  @forelse ($orderItems as $order)
    <a class="paw-row" href="{{ route('account.orders') }}">
      <span class="paw-ico-circle" style="background:#E4701E1A;color:#E4701E">@include('dashboard.widgets._icon', ['name' => 'shopping-bag', 'size' => 18])</span>
      <span style="flex:1;min-width:0">
        <strong class="paw-ellipsis">{{ $order['order_no'] }}</strong>
        <span class="paw-muted paw-ellipsis">{{ $order['pharmacy_name'] ?? 'Pharmacy' }} · {{ $order['items_count'] }} item{{ $order['items_count'] === 1 ? '' : 's' }} · R{{ number_format($order['total'], 2) }}</span>
      </span>
      <span class="pa-badge {{ in_array($order['status'], ['delivered', 'confirmed']) ? 'is-go' : ($order['status'] === 'cancelled' ? 'is-stop' : 'is-wait') }}">{{ str($order['status'])->headline() }}</span>
    </a>
  @empty
    <p class="paw-empty">No orders yet. <a href="{{ route('shop.index') }}">Browse the shop</a>.</p>
  @endforelse
</div>
