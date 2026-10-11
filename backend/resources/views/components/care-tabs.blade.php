@props(['active' => null])
{{-- Find care tab strip — labels are admin-editable (Site content → Find care). --}}
@php
  $careTabs = \App\Support\SiteContent::get('care.tabs');
  $careRoutes = ['gp' => 'care.gp', 'dentists' => 'care.dentists', 'specialists' => 'specialists.index', 'allied' => 'allied.index', 'radiology' => 'radiology.index', 'laboratories' => 'laboratories.index'];
@endphp
<div class="pa-tabs" style="gap:2px">
  @foreach ($careRoutes as $key => $routeName)
    <a class="pa-tab {{ $active === $key ? 'is-on' : '' }}" href="{{ route($routeName) }}" style="border-bottom:0">{{ $careTabs[$key]['label'] ?? $key }}</a>
  @endforeach
</div>
