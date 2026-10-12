@php
    $money = fn ($v) => 'R '.number_format((float) $v, 2);
    $patientUser = $doc['patient']?->user;
    $statusLabel = str($doc['status'] ?? 'unpaid')->headline();
    $balance = max(0, round((float) $doc['total'] - (float) $doc['paid'], 2));
    $hasBank = collect([$company['bank_name'] ?? '', $company['bank_account_number'] ?? ''])->filter()->isNotEmpty();
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{{ $doc['kind'] }} {{ $doc['number'] }} · {{ $company['company_name'] ?: 'PromptAid' }}</title>
<style>
  @page { size: A4; margin: 14mm; }
  * { box-sizing: border-box; }
  body { margin: 0; background: #F5F2EC; color: #101012; font: 13px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Lato, Helvetica, Arial, sans-serif; }
  .toolbar { max-width: 210mm; margin: 0 auto; padding: 16px; display: flex; gap: 10px; justify-content: space-between; align-items: center; flex-wrap: wrap; }
  .toolbar a, .toolbar button { font: 700 13px/1 inherit; font-family: inherit; padding: 11px 18px; border: 1px solid #101012; background: #fff; color: #101012; text-decoration: none; cursor: pointer; border-radius: 2px; }
  .toolbar button.primary { background: #D0211C; border-color: #D0211C; color: #fff; }
  .sheet { width: 210mm; max-width: 100%; min-height: 297mm; margin: 0 auto 32px; background: #fff; padding: 16mm 15mm; box-shadow: 0 2px 18px rgba(16,16,18,.08); }
  .head { display: flex; justify-content: space-between; gap: 24px; border-bottom: 3px solid #101012; padding-bottom: 16px; margin-bottom: 20px; }
  .brand { font-size: 22px; font-weight: 900; letter-spacing: -.4px; }
  .muted { color: #6E6A62; }
  .small { font-size: 11px; }
  .kind { text-align: right; }
  .kind h1 { margin: 0 0 4px; font-size: 26px; letter-spacing: -.5px; text-transform: uppercase; }
  .badge { display: inline-block; padding: 3px 9px; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .8px; border-radius: 2px; }
  .badge.paid { background: #E7F3EC; color: #1F7A4C; }
  .badge.unpaid, .badge.partially-paid { background: #FBEDEC; color: #9E1813; }
  .badge.refunded { background: #F0EBE0; color: #3A3A3E; }
  .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 22px; }
  .label { font-size: 10px; font-weight: 900; letter-spacing: 1.3px; text-transform: uppercase; color: #6E6A62; margin-bottom: 4px; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
  th { text-align: left; font-size: 10px; letter-spacing: 1.2px; text-transform: uppercase; color: #6E6A62; border-bottom: 1px solid #101012; padding: 8px 6px; }
  td { padding: 9px 6px; border-bottom: 1px solid #E0DACB; vertical-align: top; }
  .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
  .totals { margin-left: auto; width: 300px; max-width: 100%; }
  .totals .row { display: flex; justify-content: space-between; padding: 5px 0; }
  .totals .row.discount { color: #1F7A4C; }
  .totals .row.grand { border-top: 2px solid #101012; margin-top: 6px; padding-top: 10px; font-size: 18px; font-weight: 900; }
  .foot { margin-top: 28px; display: grid; grid-template-columns: 1fr 1fr; gap: 24px; border-top: 1px solid #E0DACB; padding-top: 16px; }
  .note { margin-top: 22px; font-size: 12px; color: #3A3A3E; }
  @media (max-width: 640px) {
    .sheet { padding: 20px 16px; min-height: 0; }
    .head, .grid, .foot { display: block; }
    .kind { text-align: left; margin-top: 12px; }
    .grid > div, .foot > div { margin-bottom: 14px; }
    th:nth-child(2), td:nth-child(2), th:nth-child(3), td:nth-child(3) { display: none; }
  }
  @media print {
    body { background: #fff; }
    .toolbar { display: none !important; }
    .sheet { box-shadow: none; margin: 0; padding: 0; width: auto; min-height: 0; }
    a { color: inherit; text-decoration: none; }
  }
</style>
</head>
<body>
  <div class="toolbar">
    <div>
      @if ($doc['back_url'])
        <a href="{{ $doc['back_url'] }}">&larr; Back</a>
      @endif
    </div>
    <button type="button" class="primary" onclick="window.print()">Print / save as PDF</button>
  </div>

  <main class="sheet">
    <div class="head">
      <div>
        <div class="brand">{{ $company['company_name'] ?: 'PromptAid' }}</div>
        @if ($company['company_address'])<div class="muted" style="white-space:pre-line">{{ $company['company_address'] }}</div>@endif
        <div class="muted small">
          @if ($company['company_phone']){{ $company['company_phone'] }}@endif
          @if ($company['company_phone'] && $company['company_email']) · @endif
          @if ($company['company_email']){{ $company['company_email'] }}@endif
        </div>
        <div class="muted small" style="margin-top:4px">
          @if ($company['vat_number'])VAT no. {{ $company['vat_number'] }}<br>@endif
          @if ($company['registration_number'])Reg. no. {{ $company['registration_number'] }}<br>@endif
          @if ($company['practice_number'])Practice no. {{ $company['practice_number'] }}@endif
        </div>
      </div>
      <div class="kind">
        <h1>{{ $doc['kind'] }}</h1>
        <div style="font-weight:800;font-size:15px">{{ $doc['number'] }}</div>
        <div class="muted">Date: {{ optional($doc['date'])->format('d M Y') }}</div>
        @if ($doc['due'])<div class="muted">Due: {{ $doc['due']->format('d M Y') }}</div>@endif
        <div style="margin-top:6px"><span class="badge {{ str($doc['status'])->slug() }}">{{ $statusLabel }}</span></div>
      </div>
    </div>

    <div class="grid">
      <div>
        <div class="label">Billed to</div>
        <div style="font-weight:800">{{ $patientUser?->name ?? 'Patient' }}</div>
        @if ($doc['patient']?->address)<div class="muted">{{ $doc['patient']->address }}</div>@endif
        <div class="muted small">{{ collect([$patientUser?->email, $patientUser?->phone])->filter()->implode(' · ') }}</div>
      </div>
      <div>
        <div class="label">Provider</div>
        <div style="font-weight:800">{{ $doc['provider'] ?: ($company['company_name'] ?: 'PromptAid') }}</div>
        @if ($doc['provider_line'])<div class="muted">{{ $doc['provider_line'] }}</div>@endif
        @if ($doc['practitioner'])<div class="muted small">Practitioner: {{ $doc['practitioner'] }}</div>@endif
        @if ($doc['reference'])<div class="muted small">{{ $doc['reference'] }}</div>@endif
      </div>
    </div>

    <table>
      <thead><tr><th>Description</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Amount</th></tr></thead>
      <tbody>
        @foreach ($doc['lines'] as $line)
          <tr>
            <td>{{ $line['description'] }}</td>
            <td class="num">{{ rtrim(rtrim(number_format($line['qty'], 2), '0'), '.') }}</td>
            <td class="num">{{ $money($line['unit']) }}</td>
            <td class="num">{{ $money($line['amount']) }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>

    <div class="totals">
      <div class="row"><span class="muted">Subtotal</span><span class="num">{{ $money($doc['subtotal']) }}</span></div>
      @if ($doc['discount'] > 0)
        <div class="row discount"><span>{{ $doc['discount_label'] }}</span><span class="num">&minus; {{ $money($doc['discount']) }}</span></div>
      @endif
      @if ($doc['delivery'] !== null)
        <div class="row"><span class="muted">Delivery</span><span class="num">{{ $money($doc['delivery']) }}</span></div>
      @endif
      @if ($doc['tax'] > 0)
        <div class="row"><span class="muted">VAT</span><span class="num">{{ $money($doc['tax']) }}</span></div>
      @endif
      <div class="row grand"><span>Total</span><span class="num">{{ $money($doc['total']) }}</span></div>
      @if ($doc['paid'] > 0)
        <div class="row"><span class="muted">Paid</span><span class="num">{{ $money($doc['paid']) }}</span></div>
      @endif
      <div class="row" style="font-weight:800"><span>Balance due</span><span class="num">{{ $money($balance) }}</span></div>
    </div>

    <div class="foot">
      <div>
        @if ($hasBank)
          <div class="label">Banking details</div>
          @if ($company['bank_account_name'])<div>Account name: {{ $company['bank_account_name'] }}</div>@endif
          @if ($company['bank_name'])<div>Bank: {{ $company['bank_name'] }}</div>@endif
          @if ($company['bank_account_number'])<div>Account no.: {{ $company['bank_account_number'] }}</div>@endif
          @if ($company['bank_branch_code'])<div>Branch code: {{ $company['bank_branch_code'] }}</div>@endif
          <div>Reference: <strong>{{ $doc['number'] }}</strong></div>
        @endif
      </div>
      <div class="small muted" style="text-align:right">
        Generated {{ now()->setTimezone(\App\Support\CommerceSettings::timezone())->format('d M Y H:i') }}
      </div>
    </div>

    @if ($company['footer_note'])
      <div class="note">{{ $company['footer_note'] }}</div>
    @endif
  </main>

  @if ($autoPrint)
    <script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });</script>
  @endif
</body>
</html>
