@php $contacts = $d['items'] ?? []; @endphp
@if (count($contacts))
<div>
  <div class="paw-head paw-head-bare">
    <div>
      <h3 class="paw-section-title" style="margin:0">{{ $w->title }}</h3>
      @if ($w->subtitle)<div class="paw-sub">{{ $w->subtitle }}</div>@endif
    </div>
    <a class="paw-link" href="{{ route('emergency') }}">Start triage</a>
  </div>
  <div class="paw-dial">
    @foreach ($contacts as $c)
      <a class="paw-dial-item {{ $c['is_primary'] ? 'is-primary' : '' }}" href="{{ $c['tel_url'] }}">
        <span class="paw-ico-circle">@include('dashboard.widgets._icon', ['name' => 'phone', 'size' => 18])</span>
        <span style="min-width:0"><strong class="paw-ellipsis">{{ $c['label'] }}</strong><span class="paw-ellipsis">{{ $c['phone'] }}</span></span>
      </a>
    @endforeach
  </div>
</div>
@endif
