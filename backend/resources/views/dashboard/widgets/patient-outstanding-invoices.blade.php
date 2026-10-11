@php
    $invItems = $d['items'] ?? [];
    $invDue = (float) ($d['total_due'] ?? 0);
@endphp
<div class="paw-card">
  @include('dashboard.widgets._head', ['w' => $w, 'link' => route('account.invoices')])
  <div class="paw-due">
    <span class="paw-ico-circle" style="background:{{ $invDue > 0 ? '#D0211C1A' : '#1F7A4C1A' }};color:{{ $invDue > 0 ? '#D0211C' : '#1F7A4C' }}">@include('dashboard.widgets._icon', ['name' => 'credit-card'])</span>
    <div>
      <div class="paw-stat-value">R {{ number_format($invDue, 2) }}</div>
      <div class="paw-muted">{{ $invDue > 0 ? ($d['count'] ?? 0).' unpaid invoice(s)' : 'All settled' }}</div>
    </div>
  </div>
  @foreach ($invItems as $inv)
    <a class="paw-row" href="{{ route('account.invoices') }}">
      <span style="flex:1;min-width:0">
        <strong class="paw-ellipsis">{{ $inv['invoice_no'] }}</strong>
        <span class="paw-muted paw-ellipsis">{{ $inv['clinic_name'] ?? '' }}{{ $inv['due_date'] ? ' · due '.\Illuminate\Support\Carbon::parse($inv['due_date'])->format('d M') : '' }}</span>
      </span>
      <span class="pa-badge {{ $inv['overdue'] ? 'is-stop' : 'is-wait' }}">R{{ number_format($inv['total'], 2) }}</span>
    </a>
  @endforeach
</div>
