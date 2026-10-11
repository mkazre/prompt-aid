{{-- Information Officer contact block. Expects $officer (array|null) from PrivacySettings::informationOfficer(). --}}
@php
  $ioOfficer = $officer ?? \App\Support\PrivacySettings::informationOfficer();
  $ioRegulator = \App\Support\PrivacySettings::get('privacy_regulator_contact');
@endphp
<div class="pa-privacy-io">
  @if ($ioOfficer)
    <div class="t-main">Information Officer: {{ $ioOfficer['name'] ?: 'Prompt Aid Information Officer' }}</div>
    <div class="pa-privacy-sub">
      @if ($ioOfficer['email'])<a href="mailto:{{ $ioOfficer['email'] }}">{{ $ioOfficer['email'] }}</a>@endif
      @if ($ioOfficer['email'] && $ioOfficer['phone']) · @endif
      @if ($ioOfficer['phone']){{ $ioOfficer['phone'] }}@endif
    </div>
  @else
    <div class="pa-privacy-sub">Contact us via the <a href="{{ route('contact') }}">contact page</a> and ask for the Information Officer.</div>
  @endif
  @if ($ioRegulator)
    <div class="pa-privacy-sub pa-muted" style="margin-top:6px">If you're unhappy with our response you may complain to the {{ $ioRegulator }}</div>
  @endif
</div>
