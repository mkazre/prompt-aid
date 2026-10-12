{{-- Admin-managed announcement bars (/staff → Support → Announcements).
     Dismissal is remembered per announcement (id + last edit) in localStorage. --}}
@php $paAnnouncements = \App\Models\Announcement::liveFor(auth()->user()); @endphp
@foreach ($paAnnouncements as $a)
  @php $aLink = $a->safeLink(); @endphp
  <div class="pa-annc" data-pa-annc="{{ $a->id }}-{{ $a->updated_at?->timestamp }}" style="background:{{ $a->safeColor() }};color:{{ $a->safeTextColor() }}" role="region" aria-label="Announcement"{{ $a->is_dismissible ? ' hidden' : '' }}>
    <div class="inner">
      <div class="pa-annc-text">
        @if ($a->title)<strong>{{ $a->title }}</strong> @endif
        <span>{{ $a->message }}</span>
        @if ($aLink)<a href="{{ $aLink }}" style="color:{{ $a->safeTextColor() }}"@if(! str_starts_with($aLink, '/')) target="_blank" rel="noopener"@endif>{{ $a->link_label ?: 'Learn more' }} →</a>@endif
      </div>
      @if ($a->is_dismissible)
        <button type="button" class="pa-annc-x" data-pa-annc-close aria-label="Dismiss announcement" style="color:{{ $a->safeTextColor() }}">&times;</button>
      @endif
    </div>
  </div>
@endforeach
