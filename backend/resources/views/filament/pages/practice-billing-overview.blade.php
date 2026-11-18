<x-filament-panels::page>
@php
    $card = 'border:1px solid rgba(127,127,127,.25);border-radius:12px;padding:16px';
    $th = 'text-align:left;padding:8px;font-size:12px;opacity:.7;border-bottom:1px solid rgba(127,127,127,.25)';
    $td = 'padding:8px;font-size:13px;border-bottom:1px solid rgba(127,127,127,.15)';
    $colors = ['success' => '#1F7A4C', 'info' => '#1D5E99', 'warning' => '#B07900', 'danger' => '#C8102E', 'gray' => '#6E6A62'];
    $gatedLabels = ['instant' => 'instant requests', 'listing' => 'search listing', 'bookings' => 'online bookings'];
    $gatedText = $gated ? implode(', ', array_map(fn ($g) => $gatedLabels[$g] ?? $g, $gated)) : 'nothing';
@endphp
<div>
    <div style="{{ $card }};margin-bottom:16px;background:{{ $enforced ? 'rgba(31,122,76,.08)' : 'rgba(176,121,0,.08)' }}">
        <strong>Enforcement is {{ $enforced ? 'ON' : 'OFF' }}.</strong>
        @if ($enforced)
            Lapsed practices (suspended / cancelled) lose: {{ $gatedText }}. Practices with no subscription are {{ $unsubscribedPolicy === 'block' ? 'blocked' : 'allowed' }}.
        @else
            Every practice keeps receiving requests regardless of payment. Switch it on in the settings when practices have subscribed.
        @endif
        Missed payments: {{ $retryHours }}h payment-failed window, then {{ $graceDays }}-day grace, then suspension.
        <div style="margin-top:8px;display:flex;gap:10px;flex-wrap:wrap">
            @if ($settingsUrl)<a href="{{ $settingsUrl }}" style="color:#1D5E99;font-weight:700">Settings</a>@endif
            <a href="{{ $listUrl }}" style="color:#1D5E99;font-weight:700">All practice subscriptions</a>
            <button type="button" wire:click="runCheck" style="border:0;background:transparent;color:#1D5E99;font-weight:700;cursor:pointer;padding:0">Run billing check now</button>
        </div>
        @if ($unsubClinics + $unsubDoctors > 0)
            <div style="margin-top:8px;font-size:13px">{{ $unsubClinics }} active clinic(s) and {{ $unsubDoctors }} independent doctor(s) have no subscription yet{{ $enforced && $unsubscribedPolicy === 'block' ? ' and are NOT receiving requests' : '' }}. Use “Start trials for unsubscribed practices” on the subscriptions list.</div>
        @endif
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;margin-bottom:16px">
        <div style="{{ $card }}"><div style="font-size:12px;opacity:.7">MRR</div><div style="font-size:24px;font-weight:700">{{ $mrr }}</div><div style="font-size:12px;opacity:.7">ARR {{ $arr }}</div></div>
        <div style="{{ $card }}"><div style="font-size:12px;opacity:.7">Collected (30 days)</div><div style="font-size:24px;font-weight:700">{{ $paid30 }}</div></div>
        @foreach ($counts as $k => $c)
            <div style="{{ $card }}"><div style="font-size:12px;opacity:.7">{{ $c['label'] }}</div><div style="font-size:24px;font-weight:700;color:{{ $colors[$c['color']] ?? '#6E6A62' }}">{{ $c['n'] }}</div></div>
        @endforeach
    </div>

    <div style="{{ $card }};margin-bottom:16px">
        <div style="font-weight:700;margin-bottom:8px">Failed payments / at risk ({{ count($failing) }})</div>
        @if ($failing)
            <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse">
                <thead><tr><th style="{{ $th }}">Practice</th><th style="{{ $th }}">Plan</th><th style="{{ $th }}">Status</th><th style="{{ $th }}">Amount</th><th style="{{ $th }}">Grace ends</th><th style="{{ $th }}">Pays by</th></tr></thead>
                <tbody>
                @foreach ($failing as $r)
                    <tr><td style="{{ $td }}"><a href="{{ $r['url'] }}" style="color:#1D5E99;font-weight:700">{{ $r['practice'] }}</a></td><td style="{{ $td }}">{{ $r['plan'] }}</td><td style="{{ $td }}">{{ $r['status'] }}</td><td style="{{ $td }}">{{ $r['amount'] }}</td><td style="{{ $td }}">{{ $r['grace'] ?? '—' }}</td><td style="{{ $td }}">{{ $r['card'] }}</td></tr>
                @endforeach
                </tbody>
            </table></div>
        @else
            <div style="font-size:13px;opacity:.7">None.</div>
        @endif
    </div>

    <div style="{{ $card }}">
        <div style="font-weight:700;margin-bottom:8px">Upcoming renewals & trial ends (14 days)</div>
        @if ($upcoming)
            <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse">
                <thead><tr><th style="{{ $th }}">Practice</th><th style="{{ $th }}">Plan</th><th style="{{ $th }}">Status</th><th style="{{ $th }}">Amount</th><th style="{{ $th }}">Date</th><th style="{{ $th }}">Pays by</th></tr></thead>
                <tbody>
                @foreach ($upcoming as $r)
                    <tr><td style="{{ $td }}"><a href="{{ $r['url'] }}" style="color:#1D5E99;font-weight:700">{{ $r['practice'] }}</a></td><td style="{{ $td }}">{{ $r['plan'] }}</td><td style="{{ $td }}">{{ $r['status'] }}</td><td style="{{ $td }}">{{ $r['amount'] }}</td><td style="{{ $td }}">{{ $r['next'] ?? '—' }}</td><td style="{{ $td }}">{{ $r['card'] }}</td></tr>
                @endforeach
                </tbody>
            </table></div>
        @else
            <div style="font-size:13px;opacity:.7">Nothing due in the next 14 days.</div>
        @endif
    </div>
</div>
</x-filament-panels::page>
