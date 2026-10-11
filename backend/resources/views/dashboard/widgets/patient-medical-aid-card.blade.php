@php
    $member = $d['membership'] ?? null;
    $aidAccent = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) ($s['accent'] ?? '')) ? $s['accent'] : '#101012';
@endphp
@if ($member)
<div class="paw-aid" style="--paw-accent: {{ $aidAccent }}">
  <div class="paw-aid-top">
    <span class="paw-eyebrow" style="color:rgba(255,255,255,.7)">{{ $w->title }}</span>
    <span class="pa-badge {{ $member['verified'] ? 'is-go' : 'is-wait' }}">{{ $member['verified'] ? 'Verified' : 'Pending' }}</span>
  </div>
  <div class="paw-aid-scheme">{{ $member['scheme_name'] ?? 'Medical aid' }}</div>
  <div class="paw-aid-number">{{ $member['member_number'] }}@if ($member['dependant_code']) <span>/ {{ $member['dependant_code'] }}</span>@endif</div>
  <div class="paw-aid-name">{{ $member['main_member_name'] }}</div>
</div>
@else
<div class="paw-card">
  @include('dashboard.widgets._head', ['w' => $w])
  <p class="paw-empty">No medical aid on file. <a href="{{ route('account.profile') }}">Add your scheme</a> to claim directly.</p>
</div>
@endif
