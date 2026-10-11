{{-- Any Feather icon (287, same set as the app) — App\Support\Icons. Usage:
     @include('dashboard.widgets._icon', ['name' => 'search', 'size' => 20])
     Pass 'image' => url to show an uploaded icon image instead. --}}
@if (! empty($image))
<img class="paw-ico paw-ico-img" src="{{ $image }}" width="{{ $size ?? 20 }}" height="{{ $size ?? 20 }}" alt="" loading="lazy" style="object-fit:contain">
@else
{{ \App\Support\Icons::svg($name ?? 'grid', $size ?? 20) }}
@endif
