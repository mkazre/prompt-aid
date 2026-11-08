<x-layout :title="'Earnings · Driver console · Prompt Aid'">
@php
    $q = array_filter(['period' => $d['period'], 'from' => request('from'), 'to' => request('to')]);
    $csvUrl = route('finance.statement', ['format' => 'csv'] + $q);
    $pdfUrl = route('finance.statement', ['format' => 'pdf'] + $q);
    $bank = $d['bank'];
    $inp = 'width:100%;padding:9px 10px;border-radius:10px;border:1px solid rgba(127,127,127,.4);background:transparent;color:inherit;font-size:15px';
    $lbl = 'display:block;font-size:13px;margin-bottom:4px;font-weight:600';
@endphp
<div style="max-width:960px;margin:0 auto;padding:16px">
  <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:10px;margin-bottom:14px">
    <div>
      <h1 style="font-size:26px;margin:0">Earnings</h1>
      <div class="pa-muted" style="font-size:14px">{{ $d['account']['label'] }} · {{ $d['range'] }}</div>
    </div>
    <a href="{{ route('driver.console') }}" class="pa-btn-ghost pa-btn-sm">Back to console</a>
  </div>

  @if (session('success'))<div class="pa-note" style="margin-bottom:14px">{{ session('success') }}</div>@endif

  <form method="GET" action="{{ route('driver.earnings') }}" style="display:flex;flex-wrap:wrap;gap:8px;align-items:end;margin-bottom:16px">
    <label style="font-size:13px">Period<br>
      <select name="period" style="{{ $inp }};min-width:150px" onchange="this.form.submit()">
        @foreach (['today' => 'Today', 'week' => 'This week', 'last_week' => 'Last week', 'month' => 'This month', 'last_month' => 'Last month', 'year' => 'This year', 'custom' => 'Custom…'] as $k => $v)
          <option value="{{ $k }}" @selected($d['period'] === $k)>{{ $v }}</option>
        @endforeach
      </select>
    </label>
    @if ($d['period'] === 'custom')
      <label style="font-size:13px">From<br><input type="date" name="from" value="{{ request('from') }}" style="{{ $inp }}"></label>
      <label style="font-size:13px">To<br><input type="date" name="to" value="{{ request('to') }}" style="{{ $inp }}"></label>
      <button type="submit" class="pa-btn-ghost pa-btn-sm">Show</button>
    @endif
  </form>

  @include('finance.statement-body', ['d' => $d, 'csvUrl' => $csvUrl, 'pdfUrl' => $pdfUrl])

  <div style="border:1px solid rgba(127,127,127,.25);border-radius:12px;padding:16px;margin-bottom:24px">
    <div style="font-weight:700;margin-bottom:4px">Bank details for payouts</div>
    <div class="pa-muted" style="font-size:13px;margin-bottom:12px">
      @if ($bank)
        {{ $bank['bank_name'] }} · {{ $bank['account_number_masked'] }} ·
        @if ($bank['verified'])<strong style="color:#1F7A4C">Verified</strong>@else<strong style="color:#B07900">Waiting for verification</strong>@endif
        — changing any detail needs a new verification before the next payout.
      @else
        No bank details yet — add them to receive payouts.
      @endif
    </div>
    @if ($errors->any())<div class="pa-pay-error" style="margin:0 0 12px">{{ $errors->first() }}</div>@endif
    <form method="POST" action="{{ route('driver.earnings.bank') }}">
      @csrf
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px">
        <div><label style="{{ $lbl }}" for="b-holder">Account holder</label><input id="b-holder" name="account_holder" maxlength="150" required value="{{ old('account_holder', $bank['account_holder'] ?? '') }}" style="{{ $inp }}"></div>
        <div><label style="{{ $lbl }}" for="b-bank">Bank</label>
          <select id="b-bank" name="bank_name" required style="{{ $inp }}"><option value="">Choose…</option>
            @foreach ($banks as $b)<option value="{{ $b }}" @selected(old('bank_name', $bank['bank_name'] ?? '') === $b)>{{ $b }}</option>@endforeach
          </select></div>
        <div><label style="{{ $lbl }}" for="b-branch">Branch code</label><input id="b-branch" name="branch_code" inputmode="numeric" maxlength="12" required value="{{ old('branch_code', $bank['branch_code'] ?? '') }}" style="{{ $inp }}"></div>
        <div><label style="{{ $lbl }}" for="b-acc">Account number</label><input id="b-acc" name="account_number" inputmode="numeric" autocomplete="off" maxlength="24" @if (! $bank) required @endif placeholder="{{ $bank ? 'Leave empty to keep '.$bank['account_number_masked'] : '' }}" style="{{ $inp }}"></div>
        <div><label style="{{ $lbl }}" for="b-type">Account type</label>
          <select id="b-type" name="bank_account_type" style="{{ $inp }}">
            @foreach ($bankTypes as $k => $v)<option value="{{ $k }}" @selected(old('bank_account_type', $bank['bank_account_type'] ?? 'cheque') === $k)>{{ $v }}</option>@endforeach
          </select></div>
      </div>
      <button type="submit" class="pa-btn" style="margin-top:12px">Save bank details</button>
    </form>
  </div>
</div>
</x-layout>
