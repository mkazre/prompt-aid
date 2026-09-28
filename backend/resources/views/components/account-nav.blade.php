@props(['active' => null])
@php
    $acctUser = auth()->user();
    $acctPatient = $acctUser->patientProfile;
    $acctMembership = $acctPatient?->schemeMemberships()->with('scheme')->latest()->first();
    $acctSubtitle = $acctMembership?->scheme ? 'Patient · '.$acctMembership->scheme->name : 'Patient';
    $acctInitials = collect(explode(' ', trim($acctUser->name)))->filter()->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->take(2)->implode('');
@endphp
<aside class="pa-acctnav">
  <div class="who">
    <div class="pa-avatar" style="width:42px;height:42px;font-size:14px">{{ $acctInitials }}</div>
    <div style="min-width:0">
      <div style="font-size:14px;font-weight:700">{{ $acctUser->name }}</div>
      <div style="font-size:11px;color:var(--pa-muted)">{{ $acctSubtitle }}</div>
    </div>
  </div>
  <div class="grp">My care</div>
  <a href="{{ route('dashboard') }}" class="{{ $active === 'overview' ? 'is-on' : '' }}"><span class="d"></span>Overview</a>
  <a href="{{ route('account.appointments') }}" class="{{ $active === 'appointments' ? 'is-on' : '' }}"><span class="d"></span>Appointments</a>
  <a href="{{ route('account.prescriptions') }}" class="{{ $active === 'prescriptions' ? 'is-on' : '' }}"><span class="d"></span>Prescriptions &amp; scripts</a>
  <a href="{{ route('account.results') }}" class="{{ $active === 'results' ? 'is-on' : '' }}"><span class="d"></span>Results</a>
  <a href="{{ route('account.record') }}" class="{{ $active === 'record' ? 'is-on' : '' }}"><span class="d"></span>Medical record</a>
  <div class="grp">Orders &amp; trips</div>
  <a href="{{ route('account.orders') }}" class="{{ $active === 'orders' ? 'is-on' : '' }}"><span class="d"></span>Pharmacy orders</a>
  <a href="{{ route('account.rides') }}" class="{{ $active === 'rides' ? 'is-on' : '' }}"><span class="d"></span>Shuttle trips</a>
  <a href="{{ route('account.invoices') }}" class="{{ $active === 'invoices' ? 'is-on' : '' }}"><span class="d"></span>Invoices &amp; claims</a>
  <div class="grp">Account</div>
  <a href="{{ route('account.profile') }}" class="{{ $active === 'profile' ? 'is-on' : '' }}"><span class="d"></span>Profile &amp; scheme</a>
  <a href="{{ route('account.settings') }}" class="{{ $active === 'settings' ? 'is-on' : '' }}"><span class="d"></span>Privacy &amp; security</a>
  <div style="padding:14px 16px;border-top:1px solid var(--pa-line)">
    <form method="POST" action="{{ route('logout') }}">
      @csrf
      <button class="pa-btn-ghost pa-btn-block pa-btn-sm" type="submit">Log out</button>
    </form>
  </div>
</aside>
