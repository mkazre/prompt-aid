<x-filament-panels::page>
@php
    $fmt = fn ($v) => ($v < 0 ? '-R ' : 'R ').number_format(abs((float) $v), 2, '.', ' ');
    $th = 'text-align:left;padding:8px 10px;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;border-bottom:1px solid rgba(127,127,127,.25)';
    $thr = $th.';text-align:right';
    $td = 'padding:8px 10px;border-bottom:1px solid rgba(127,127,127,.12);font-size:14px';
    $tdr = $td.';text-align:right;font-variant-numeric:tabular-nums';
    $card = 'border:1px solid rgba(127,127,127,.25);border-radius:12px;padding:16px;margin-bottom:16px;overflow-x:auto';
@endphp
<div>
    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:end;margin-bottom:16px">
        <label style="font-size:13px">Period<br>
            <select wire:model.live="period" style="min-width:160px;padding:6px 8px;border-radius:8px;border:1px solid rgba(127,127,127,.4);background:transparent">
                <option value="today">Today</option>
                <option value="week">This week</option>
                <option value="last_week">Last week</option>
                <option value="month">This month</option>
                <option value="last_month">Last month</option>
                <option value="year">This year</option>
                <option value="custom">Custom…</option>
            </select>
        </label>
        @if ($period === 'custom')
            <label style="font-size:13px">From<br><input type="date" wire:model.live="from" style="padding:6px 8px;border-radius:8px;border:1px solid rgba(127,127,127,.4);background:transparent"></label>
            <label style="font-size:13px">To<br><input type="date" wire:model.live="to" style="padding:6px 8px;border-radius:8px;border:1px solid rgba(127,127,127,.4);background:transparent"></label>
        @endif
        <div style="font-size:13px;color:#6b7280;padding-bottom:6px">{{ $range }}</div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:16px">
        @foreach ([['Platform revenue', $totals['total']], ['Commission', $totals['commission']], ['Patient fees', $totals['fees']], ['Refunds (reversed)', $totals['refunds']]] as [$l, $v])
            <div style="border:1px solid rgba(127,127,127,.25);border-radius:12px;padding:14px">
                <div style="font-size:12px;color:#6b7280">{{ $l }}</div>
                <div style="font-size:22px;font-weight:700;font-variant-numeric:tabular-nums">{{ $fmt($v) }}</div>
            </div>
        @endforeach
    </div>

    <div style="{{ $card }}">
        <div style="font-weight:700;margin-bottom:8px">Revenue by service</div>
        <table style="width:100%;border-collapse:collapse">
            <thead><tr><th style="{{ $th }}">Service</th><th style="{{ $thr }}">Commission</th><th style="{{ $thr }}">Patient fees</th><th style="{{ $thr }}">Other platform revenue</th><th style="{{ $thr }}">Refunds</th><th style="{{ $thr }}">Total</th></tr></thead>
            <tbody>
            @forelse ($revenue as $svc => $r)
                <tr><td style="{{ $td }}">{{ $labels[$svc] ?? ucfirst($svc) }}</td><td style="{{ $tdr }}">{{ $fmt($r['commission']) }}</td><td style="{{ $tdr }}">{{ $fmt($r['fees']) }}</td><td style="{{ $tdr }}">{{ $fmt($r['other']) }}</td><td style="{{ $tdr }}">{{ $fmt($r['refunds']) }}</td><td style="{{ $tdr }};font-weight:700">{{ $fmt($r['total']) }}</td></tr>
            @empty
                <tr><td style="{{ $td }}" colspan="6">No captured payments in this period.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px">
        <div style="{{ $card }}">
            <div style="font-weight:700;margin-bottom:8px">Outstanding provider balances</div>
            <table style="width:100%;border-collapse:collapse">
                <thead><tr><th style="{{ $th }}">Provider</th><th style="{{ $thr }}">Balance</th><th style="{{ $thr }}">On hold</th><th style="{{ $thr }}">Available</th></tr></thead>
                <tbody>
                @forelse ($balances as $b)
                    <tr><td style="{{ $td }}">{{ $b['label'] }} <span style="color:#6b7280;font-size:12px">{{ $b['account_type'] }}</span></td><td style="{{ $tdr }}">{{ $fmt($b['balance']) }}</td><td style="{{ $tdr }}">{{ $fmt($b['on_hold']) }}</td><td style="{{ $tdr }}">{{ $fmt($b['available']) }}</td></tr>
                @empty
                    <tr><td style="{{ $td }}" colspan="4">No outstanding balances.</td></tr>
                @endforelse
                </tbody>
            </table>
            <div style="font-size:12px;color:#6b7280;margin-top:8px">Negative balance = the provider owes commission on cash they collected; it is recovered from future earnings.</div>
        </div>
        <div style="{{ $card }}">
            <div style="font-weight:700;margin-bottom:8px">Open payout batches</div>
            @forelse ($openBatches as $b)
                <div style="display:flex;justify-content:space-between;gap:8px;padding:6px 0;border-bottom:1px solid rgba(127,127,127,.12)">
                    <a href="{{ $batchUrl($b) }}" style="text-decoration:underline">{{ $b->reference }}</a>
                    <span>{{ $b->payout_count }} payout(s) · {{ $fmt($b->total_amount) }} · {{ $b->status }}</span>
                </div>
            @empty
                <div style="font-size:14px;color:#6b7280">None — generate one under Finance → Payout batches.</div>
            @endforelse
            <div style="margin-top:12px;font-size:14px">
                <a href="{{ $bankUrl }}" style="text-decoration:underline">{{ $unverified }} bank detail submission(s) waiting for verification</a>
            </div>
        </div>
    </div>
</div>
</x-filament-panels::page>
