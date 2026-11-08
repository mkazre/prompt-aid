<x-filament-panels::page>
@php
    $inp = 'width:100%;padding:7px 9px;border-radius:8px;border:1px solid rgba(127,127,127,.4);background:transparent;color:inherit';
    $lbl = 'display:block;font-size:13px;margin-bottom:4px';
    $err = 'color:#C8102E;font-size:12px;margin-top:2px';
    $bank = $d['bank'];
@endphp
<div>
    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:end;margin-bottom:16px">
        @if (count($accounts) > 1)
            <label style="font-size:13px">Account<br>
                <select wire:model.live="account" style="min-width:200px;{{ $inp }}">
                    @foreach ($accounts as $a)
                        <option value="{{ $a['type'] }}:{{ $a['id'] }}">{{ $a['label'] }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        <label style="font-size:13px">Period<br>
            <select wire:model.live="period" style="min-width:160px;{{ $inp }}">
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
            <label style="font-size:13px">From<br><input type="date" wire:model.live="from" style="{{ $inp }}"></label>
            <label style="font-size:13px">To<br><input type="date" wire:model.live="to" style="{{ $inp }}"></label>
        @endif
        <div style="font-size:13px;opacity:.7;padding-bottom:8px">{{ $d['account']['label'] }} · {{ $d['range'] }}</div>
    </div>

    @include('finance.statement-body', ['d' => $d, 'csvUrl' => $csvUrl, 'pdfUrl' => $pdfUrl])

    <div style="border:1px solid rgba(127,127,127,.25);border-radius:12px;padding:16px;margin-bottom:16px">
        <div style="font-weight:700;margin-bottom:4px">Bank details for payouts</div>
        <div style="font-size:13px;opacity:.75;margin-bottom:12px">
            @if ($bank)
                {{ $bank['bank_name'] }} · {{ $bank['account_number_masked'] }} ·
                @if ($bank['verified'])<span style="color:#1F7A4C;font-weight:700">Verified</span>@else<span style="color:#B07900;font-weight:700">Waiting for verification</span>@endif
                — changing any detail needs a new verification before the next payout.
            @else
                No bank details yet — add them to receive payouts.
            @endif
        </div>
        <form wire:submit="saveBank">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px">
                <div><label style="{{ $lbl }}">Account holder</label><input type="text" wire:model="bank.account_holder" style="{{ $inp }}" maxlength="150">@error('bank.account_holder')<div style="{{ $err }}">{{ $message }}</div>@enderror</div>
                <div><label style="{{ $lbl }}">Bank</label>
                    <select wire:model="bank.bank_name" style="{{ $inp }}"><option value="">Choose…</option>@foreach ($banks as $b)<option value="{{ $b }}">{{ $b }}</option>@endforeach</select>
                    @error('bank.bank_name')<div style="{{ $err }}">{{ $message }}</div>@enderror</div>
                <div><label style="{{ $lbl }}">Branch code</label><input type="text" inputmode="numeric" wire:model="bank.branch_code" style="{{ $inp }}" maxlength="12">@error('bank.branch_code')<div style="{{ $err }}">{{ $message }}</div>@enderror</div>
                <div><label style="{{ $lbl }}">Account number</label><input type="text" inputmode="numeric" autocomplete="off" wire:model="bank.account_number" style="{{ $inp }}" maxlength="24" placeholder="{{ $bank ? 'Leave empty to keep '.$bank['account_number_masked'] : '' }}">@error('bank.account_number')<div style="{{ $err }}">{{ $message }}</div>@enderror</div>
                <div><label style="{{ $lbl }}">Account type</label>
                    <select wire:model="bank.bank_account_type" style="{{ $inp }}">@foreach ($bankTypes as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select>
                    @error('bank.bank_account_type')<div style="{{ $err }}">{{ $message }}</div>@enderror</div>
            </div>
            <div style="margin-top:12px">
                <x-filament::button type="submit" icon="heroicon-o-check" wire:loading.attr="disabled">Save bank details</x-filament::button>
            </div>
        </form>
    </div>
</div>
</x-filament-panels::page>
