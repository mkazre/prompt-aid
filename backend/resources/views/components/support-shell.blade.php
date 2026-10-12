@props(['active' => 'support'])
{{-- Wrapper for the signed-in support pages (/account/support, /account/feedback):
     patients get the normal account sidebar; other roles (doctor, driver,
     pharmacy, partner) have no patient account, so they get a plain column. --}}
@if (auth()->user()?->patientProfile)
<div class="pa-account">
<x-account-nav :active="$active" />
<div>
{{ $slot }}
</div>
</div>
@else
<div class="pa-container" style="padding-top:28px;padding-bottom:72px;max-width:920px">
{{ $slot }}
</div>
@endif
