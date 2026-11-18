<x-filament-panels::page>
@php
    $card = 'border:1px solid rgba(127,127,127,.25);border-radius:12px;padding:16px;margin-bottom:16px';
    $btn = 'display:inline-block;padding:8px 14px;border-radius:8px;border:0;background:#1D5E99;color:#fff;font-weight:700;font-size:13px;cursor:pointer;text-decoration:none';
    $btnGhost = 'display:inline-block;padding:8px 14px;border-radius:8px;border:1px solid rgba(127,127,127,.45);background:transparent;color:inherit;font-weight:700;font-size:13px;cursor:pointer;text-decoration:none';
    $inp = 'padding:7px 9px;border-radius:8px;border:1px solid rgba(127,127,127,.4);background:transparent;color:inherit';
    $muted = 'font-size:13px;opacity:.75';
    $th = 'text-align:left;padding:8px;font-size:12px;opacity:.7;border-bottom:1px solid rgba(127,127,127,.25)';
    $td = 'padding:8px;font-size:13px;border-bottom:1px solid rgba(127,127,127,.15)';
@endphp
<div id="pa-practice-sub" @if ($pendingIntent) wire:poll.5s="checkPending" @endif>
    <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:end;margin-bottom:16px">
        @if (count($practices) > 1)
            <label style="font-size:13px">Practice<br>
                <select wire:model.live="practice" style="min-width:220px;{{ $inp }}">
                    @foreach ($practices as $p)
                        <option value="{{ $p['type'] }}:{{ $p['id'] }}">{{ $p['label'] }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if ($hasAnnual)
            <label style="font-size:13px">Billing<br>
                <select wire:model.live="cycle" style="min-width:140px;{{ $inp }}">
                    <option value="monthly">Monthly</option>
                    <option value="annual">Yearly</option>
                </select>
            </label>
        @endif
        <div style="{{ $muted }};padding-bottom:8px">PromptAid charges practices a platform subscription only. Consultation fees are agreed and collected by your practice.</div>
    </div>

    {{-- Current status --}}
    <div style="{{ $card }}">
        @if ($status)
            <div style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:12px;align-items:flex-start">
                <div>
                    <div style="font-size:13px;opacity:.7">Current plan</div>
                    <div style="font-size:20px;font-weight:700">{{ $status['plan'] ?? 'No plan yet' }}
                        <span style="display:inline-block;margin-left:8px;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:700;color:#fff;background:{{ $status['color'] }}">{{ $status['label'] }}</span>
                    </div>
                    <div style="{{ $muted }};margin-top:6px">
                        @if ($status['amount']) {{ $status['amount'] }} · @endif
                        {{ $status['seats'] }} doctor seat{{ $status['seats'] === 1 ? '' : 's' }} billed · {{ $doctors }} doctor{{ $doctors === 1 ? '' : 's' }} in this practice
                    </div>
                    <div style="font-size:13px;margin-top:6px">
                        @if ($status['trial_ends'])Free trial ends <strong>{{ $status['trial_ends'] }}</strong> ({{ $status['trial_days_left'] }} day{{ $status['trial_days_left'] === 1 ? '' : 's' }} left).@endif
                        @if ($status['comped_until'])Waived by PromptAid until <strong>{{ $status['comped_until'] }}</strong>.@endif
                        @if ($status['paid_until'])Paid until <strong>{{ $status['paid_until'] }}</strong>.@endif
                        @if ($status['next_billing'] && ! $status['cancelling'] && $status['card'])Next card debit {{ $status['next_billing'] }}.@endif
                        @if ($status['cancelling'])<span style="color:#B07900;font-weight:700">Cancelled — ends {{ $status['paid_until'] }}.</span>@endif
                        @if ($status['grace_ends'])<span style="color:#B07900;font-weight:700">Payment failed — pay before {{ $status['grace_ends'] }} to avoid suspension.</span>@endif
                    </div>
                    <div style="font-size:13px;margin-top:6px">
                        Receiving patient requests:
                        @if ($receiving)<strong style="color:#1F7A4C">Yes</strong>@else<strong style="color:#C8102E">No — subscription lapsed</strong>@endif
                    </div>
                    @if ($status['seat_mismatch'])
                        <div style="font-size:13px;margin-top:6px;color:#B07900">Your number of doctors changed. <button type="button" wire:click="syncSeats" style="{{ $btnGhost }};padding:4px 10px">Update seats</button></div>
                    @endif
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:8px">
                    @if ($status['update_card_url'])
                        <a href="{{ $status['update_card_url'] }}" target="_blank" rel="noopener" style="{{ $status['needs_payment'] ? $btn : $btnGhost }}">Update card</a>
                    @endif
                    @if ($status['can_cancel'])
                        <button type="button" wire:click="cancelSubscription" wire:confirm="Cancel your PromptAid subscription? Paid-up practices keep access until the end of the period." style="{{ $btnGhost }}">Cancel subscription</button>
                    @endif
                </div>
            </div>
            @if ($status['needs_payment'] && ! $status['card'])
                <div style="font-size:13px;margin-top:10px;color:#B07900">Choose a plan below and pay by card to reactivate immediately, or pay by EFT and ask PromptAid to record it.</div>
            @endif
        @else
            <div style="font-size:18px;font-weight:700">No subscription yet</div>
            <div style="{{ $muted }};margin-top:4px">Choose a plan below. Plans with a free trial start immediately, no card needed.
                @if ($enforced) Practices without an active subscription do not receive new patient requests. @endif</div>
        @endif
    </div>

    @if ($pendingIntent && ! $payUrl)
        <div style="{{ $card }};background:rgba(29,94,153,.08)">Confirming your payment with the bank… this page updates automatically.</div>
    @endif

    {{-- Plans --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:16px;margin-bottom:16px">
        @forelse ($planCards as $pc)
            <div style="{{ $card }};margin-bottom:0;display:flex;flex-direction:column;{{ $pc['current'] ? 'border-color:#1D5E99;border-width:2px' : '' }}">
                <div style="font-weight:700;font-size:16px">{{ $pc['name'] }} @if ($pc['current'])<span style="font-size:12px;color:#1D5E99">(current)</span>@endif</div>
                <div style="font-size:22px;font-weight:700;margin-top:6px">{{ $pc['price'] }}</div>
                @if ($pc['annual_only_monthly'])<div style="font-size:12px;opacity:.7">Monthly billing only</div>@endif
                <div style="{{ $muted }};margin-top:4px">{{ $pc['basis'] }}</div>
                @if ($pc['description'])<div style="font-size:13px;margin-top:8px">{{ $pc['description'] }}</div>@endif
                <ul style="font-size:13px;margin:10px 0 0;padding-left:18px;list-style:disc">
                    @foreach ($pc['features'] as $f)<li>{{ $f }}</li>@endforeach
                </ul>
                @if ($pc['over_limit'])<div style="font-size:12px;color:#C8102E;margin-top:6px">Your practice has more doctors than this plan allows.</div>@endif
                <div style="margin-top:auto;padding-top:14px;display:flex;flex-wrap:wrap;gap:8px">
                    @if ($pc['can_trial'])
                        <button type="button" wire:click="startTrial({{ $pc['id'] }})" style="{{ $btn }}">Start {{ $pc['trial_days'] }}-day free trial</button>
                    @endif
                    @if ($pc['can_switch'])
                        <button type="button" wire:click="changePlan({{ $pc['id'] }})" wire:confirm="Switch to {{ $pc['name'] }}? The new amount applies from your next payment." style="{{ $btnGhost }}">Switch to this plan</button>
                    @elseif ($pc['can_subscribe'])
                        <button type="button" wire:click="subscribe({{ $pc['id'] }})" wire:loading.attr="disabled" style="{{ $pc['can_trial'] ? $btnGhost : $btn }}">Subscribe by card</button>
                    @endif
                </div>
            </div>
        @empty
            <div style="{{ $muted }}">No practice plans are available yet. PromptAid will contact you.</div>
        @endforelse
    </div>

    {{-- Receipts --}}
    <div style="{{ $card }}">
        <div style="font-weight:700;margin-bottom:8px">Invoices & receipts</div>
        @if ($invoices)
            <div style="overflow-x:auto">
            <table style="width:100%;border-collapse:collapse">
                <thead><tr><th style="{{ $th }}">Number</th><th style="{{ $th }}">Date</th><th style="{{ $th }}">Period</th><th style="{{ $th }}">Amount</th><th style="{{ $th }}">Status</th><th style="{{ $th }}"></th></tr></thead>
                <tbody>
                @foreach ($invoices as $i)
                    <tr>
                        <td style="{{ $td }}">{{ $i['number'] }}</td>
                        <td style="{{ $td }}">{{ $i['date'] }}</td>
                        <td style="{{ $td }}">{{ $i['period'] }}</td>
                        <td style="{{ $td }}">{{ $i['amount'] }}</td>
                        <td style="{{ $td }}">{{ $i['status'] }}</td>
                        <td style="{{ $td }}"><a href="{{ $i['url'] }}" target="_blank" rel="noopener" style="color:#1D5E99;font-weight:700">Receipt</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        @else
            <div style="{{ $muted }}">No payments yet.</div>
        @endif
    </div>

    @if ($events)
        <div style="{{ $card }}">
            <div style="font-weight:700;margin-bottom:8px">Recent activity</div>
            @foreach ($events as $e)
                <div style="font-size:13px;padding:4px 0"><span style="opacity:.6">{{ $e['at'] }}</span> — {{ $e['message'] }}</div>
            @endforeach
        </div>
    @endif

    {{-- Payment sheet: the existing secure embed page (/pay/{intent}/embed) in a modal. --}}
    @if ($payUrl)
        <div style="position:fixed;inset:0;z-index:60;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;padding:16px">
            <div style="background:#fff;color:#101012;border-radius:14px;width:100%;max-width:520px;max-height:92vh;display:flex;flex-direction:column;overflow:hidden">
                <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:1px solid #eee">
                    <strong>Secure payment</strong>
                    <div style="display:flex;gap:8px">
                        <a href="{{ $payUrl }}" target="_blank" rel="noopener" style="font-size:12px;color:#1D5E99">Open in new tab</a>
                        <button type="button" wire:click="paymentFinished('closed')" style="border:0;background:transparent;font-size:20px;line-height:1;cursor:pointer" aria-label="Close">&times;</button>
                    </div>
                </div>
                <iframe src="{{ $payUrl }}" title="Payment" style="border:0;width:100%;height:620px;max-height:80vh" allow="payment"></iframe>
            </div>
        </div>
    @endif
</div>
<script>
    (function () {
        if (window.__paPracticePayListener) return;
        window.__paPracticePayListener = true;
        window.addEventListener('message', function (ev) {
            if (ev.origin !== window.location.origin) return;
            var d; try { d = typeof ev.data === 'string' ? JSON.parse(ev.data) : ev.data; } catch (e) { return; }
            if (!d || d.type !== 'pa-payment' || ['completed', 'cancelled', 'error'].indexOf(d.event) === -1) return;
            var root = document.getElementById('pa-practice-sub');
            var el = root ? root.closest('[wire\\:id]') : null;
            var c = el && window.Livewire ? window.Livewire.find(el.getAttribute('wire:id')) : null;
            if (c) setTimeout(function () { c.call('paymentFinished', d.event); }, d.event === 'completed' ? 1200 : 0);
        });
    })();
</script>
</x-filament-panels::page>
