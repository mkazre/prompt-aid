{{-- Provider earnings statement body (Filament provider pages + driver web console). Inline styles only.
     Expects: $d (App\Services\Finance\Statements::data()), $csvUrl, $pdfUrl --}}
@php
    $fmt = fn ($v) => ($v < 0 ? '-R ' : 'R ').number_format(abs((float) $v), 2, '.', ' ');
    $s = $d['summary'];
    $th = 'text-align:left;padding:8px 10px;font-size:12px;text-transform:uppercase;letter-spacing:.04em;opacity:.7;border-bottom:1px solid rgba(127,127,127,.25);white-space:nowrap';
    $thr = $th.';text-align:right';
    $td = 'padding:8px 10px;border-bottom:1px solid rgba(127,127,127,.12);font-size:14px;vertical-align:top';
    $tdr = $td.';text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap';
    $card = 'border:1px solid rgba(127,127,127,.25);border-radius:12px;padding:16px;margin-bottom:16px;overflow-x:auto';
    $labels = \App\Services\Finance\Statements::SERVICE_LABELS;
    $statusColor = fn ($st) => match ($st) { 'paid' => '#1F7A4C', 'failed' => '#C8102E', 'cancelled' => '#6E6A62', default => '#B07900' };
@endphp
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:16px">
    @foreach ([
        ['Gross earnings', $s['gross'], $d['range']],
        ['Commission', -$s['commission'], 'Prompt Aid platform fee'],
        ['Net earnings', $s['net'], 'after commission & refunds'],
        ['Available', $s['available'], 'paid in the next batch'],
        ['On hold', $s['on_hold'], 'recent — not payable yet'],
        ['Paid out', $s['paid_out'], 'in this period'],
    ] as [$l, $v, $sub])
        <div style="border:1px solid rgba(127,127,127,.25);border-radius:12px;padding:14px">
            <div style="font-size:12px;opacity:.7">{{ $l }}</div>
            <div style="font-size:21px;font-weight:700;font-variant-numeric:tabular-nums">{{ $fmt($v) }}</div>
            <div style="font-size:12px;opacity:.6">{{ $sub }}</div>
        </div>
    @endforeach
</div>
@if ($s['balance'] < 0)
    <div style="border:1px solid #F2C200;border-radius:12px;padding:12px;margin-bottom:16px;font-size:14px">
        Your balance is {{ $fmt($s['balance']) }}: commission on cash you collected. It is deducted from your next card / online earnings.
    </div>
@endif
@if ($s['in_payout'] > 0)
    <div style="border:1px solid rgba(127,127,127,.25);border-radius:12px;padding:12px;margin-bottom:16px;font-size:14px">
        {{ $fmt($s['in_payout']) }} is in a payout being processed.
    </div>
@endif

<div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px">
    <a href="{{ $csvUrl }}" style="display:inline-block;padding:8px 14px;border-radius:8px;border:1px solid rgba(127,127,127,.4);text-decoration:none;font-weight:600">Download CSV</a>
    <a href="{{ $pdfUrl }}" style="display:inline-block;padding:8px 14px;border-radius:8px;border:1px solid rgba(127,127,127,.4);text-decoration:none;font-weight:600">Download PDF statement</a>
</div>

<div style="{{ $card }}">
    <div style="font-weight:700;margin-bottom:8px">Totals by {{ $d['bucket'] }}</div>
    <table style="width:100%;border-collapse:collapse">
        <thead><tr><th style="{{ $th }}">{{ ucfirst($d['bucket']) }}</th><th style="{{ $thr }}">Items</th><th style="{{ $thr }}">Gross</th><th style="{{ $thr }}">Commission</th><th style="{{ $thr }}">Refunds</th><th style="{{ $thr }}">Net</th></tr></thead>
        <tbody>
        @forelse ($d['series'] as $r)
            <tr><td style="{{ $td }}">{{ $r['period'] }}</td><td style="{{ $tdr }}">{{ $r['items'] }}</td><td style="{{ $tdr }}">{{ $fmt($r['gross']) }}</td><td style="{{ $tdr }}">{{ $fmt($r['commission']) }}</td><td style="{{ $tdr }}">{{ $fmt($r['refunds']) }}</td><td style="{{ $tdr }};font-weight:700">{{ $fmt($r['net']) }}</td></tr>
        @empty
            <tr><td style="{{ $td }}" colspan="6">No earnings in this period.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div style="{{ $card }}">
    <div style="font-weight:700;margin-bottom:8px">Items</div>
    <table style="width:100%;border-collapse:collapse">
        <thead><tr><th style="{{ $th }}">Date</th><th style="{{ $th }}">Service</th><th style="{{ $th }}">Description</th><th style="{{ $thr }}">Gross</th><th style="{{ $thr }}">Commission</th><th style="{{ $thr }}">Refunded</th><th style="{{ $thr }}">Net</th></tr></thead>
        <tbody>
        @forelse ($d['items'] as $i)
            <tr>
                <td style="{{ $td }};white-space:nowrap">{{ \Illuminate\Support\Carbon::parse($i['date'])->timezone('Africa/Johannesburg')->format('d M Y H:i') }}</td>
                <td style="{{ $td }}">{{ $labels[$i['service']] ?? $i['service'] }}</td>
                <td style="{{ $td }}">{{ $i['description'] }}@if ($i['cash']) <span style="font-size:12px;opacity:.7">(cash)</span>@endif</td>
                <td style="{{ $tdr }}">{{ $fmt($i['gross']) }}</td>
                <td style="{{ $tdr }}">{{ $fmt($i['commission']) }}</td>
                <td style="{{ $tdr }}">{{ $i['refunded'] ? $fmt($i['refunded']) : '—' }}</td>
                <td style="{{ $tdr }};font-weight:700">{{ $fmt($i['net']) }}</td>
            </tr>
        @empty
            <tr><td style="{{ $td }}" colspan="7">No earnings in this period.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div style="{{ $card }}">
    <div style="font-weight:700;margin-bottom:8px">Payout history</div>
    <table style="width:100%;border-collapse:collapse">
        <thead><tr><th style="{{ $th }}">Reference</th><th style="{{ $th }}">Status</th><th style="{{ $th }}">Paid</th><th style="{{ $th }}">Bank reference</th><th style="{{ $thr }}">Amount</th></tr></thead>
        <tbody>
        @forelse ($d['payouts'] as $p)
            <tr>
                <td style="{{ $td }}">{{ $p['reference'] }}</td>
                <td style="{{ $td }}"><span style="color:{{ $statusColor($p['status']) }};font-weight:700">{{ ucfirst($p['status']) }}</span>@if ($p['failed_reason'] && in_array($p['status'], ['failed', 'cancelled'], true))<div style="font-size:12px;opacity:.7">{{ $p['failed_reason'] }}</div>@endif</td>
                <td style="{{ $td }}">{{ $p['paid_at'] ? \Illuminate\Support\Carbon::parse($p['paid_at'])->timezone('Africa/Johannesburg')->format('d M Y') : '—' }}</td>
                <td style="{{ $td }}">{{ $p['bank_reference'] ?: '—' }}</td>
                <td style="{{ $tdr }}">{{ $fmt($p['amount']) }}</td>
            </tr>
        @empty
            <tr><td style="{{ $td }}" colspan="5">No payouts yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
