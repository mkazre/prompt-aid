@php
    $statusLabel = \App\Models\PracticeInvoice::STATUSES[$inv->status] ?? ucfirst((string) $inv->status);
    $methodLabel = \App\Models\PracticeInvoice::METHODS[$inv->method] ?? ($inv->method ?: '—');
    $period = ($inv->period_start?->format('d M Y') ?? '—').' – '.($inv->period_end?->format('d M Y') ?? '—');
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Receipt {{ $inv->number }} · PromptAid</title>
<style>
  body{margin:0;background:#f4f4f2;color:#101012;font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif}
  .doc{max-width:720px;margin:24px auto;background:#fff;border:1px solid #e3e1dc;border-radius:12px;padding:32px}
  h1{font-size:22px;margin:0 0 4px} .muted{color:#6e6a62;font-size:13px}
  table{width:100%;border-collapse:collapse;margin-top:24px;font-size:14px}
  th,td{text-align:left;padding:10px 8px;border-bottom:1px solid #eee} th{font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:#6e6a62}
  .total{font-size:18px;font-weight:700;text-align:right;margin-top:16px}
  .badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:700;background:#e8f5ee;color:#1f7a4c}
  .print{margin-top:24px} @media print{.print{display:none} body{background:#fff} .doc{border:0;margin:0}}
</style>
</head>
<body>
<div class="doc">
  <div style="display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap">
    <div>
      <h1>PromptAid</h1>
      <div class="muted">Practice subscription {{ $inv->status === 'paid' ? 'receipt' : 'statement' }}</div>
    </div>
    <div style="text-align:right">
      <div style="font-weight:700">{{ $inv->number }}</div>
      <div class="muted">{{ ($inv->paid_at ?? $inv->created_at)?->format('d M Y') }}</div>
      <div style="margin-top:6px"><span class="badge">{{ $statusLabel }}</span></div>
    </div>
  </div>
  <div style="margin-top:20px">
    <div class="muted">Billed to</div>
    <div style="font-weight:700">{{ $practice }}</div>
  </div>
  <table>
    <thead><tr><th>Description</th><th>Period</th><th>Seats</th><th style="text-align:right">Amount</th></tr></thead>
    <tbody>
      <tr>
        <td>{{ $inv->plan_name ?: 'Practice plan' }}<br><span class="muted">{{ $inv->description }}</span></td>
        <td>{{ $period }}</td>
        <td>{{ $inv->seats }}</td>
        <td style="text-align:right">{{ $inv->amountLabel() }}</td>
      </tr>
    </tbody>
  </table>
  <div class="total">Total {{ $inv->amountLabel() }}</div>
  <div class="muted" style="margin-top:12px">Payment method: {{ $methodLabel }}@if ($inv->gateway_ref) · Ref {{ $inv->gateway_ref }}@endif</div>
  <div class="muted" style="margin-top:4px">Platform subscription only. Consultation fees are charged and kept by the practice.</div>
  <div class="print"><button onclick="window.print()" style="padding:8px 16px;border-radius:8px;border:1px solid #ccc;background:#fff;cursor:pointer">Print</button></div>
</div>
</body>
</html>
