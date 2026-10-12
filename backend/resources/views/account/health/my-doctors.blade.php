<x-layout title="My doctors · Prompt Aid">
@push('meta')<link rel="stylesheet" href="{{ asset('assets/css/pa-health.css') }}" />@endpush
<div class="pa-account">
<x-account-nav active="my-doctors" />
<div>
  <div class="pa-spread" style="margin-bottom:22px">
    <div><h1 style="font-size:32px">My doctors</h1><p class="pa-muted" style="margin:6px 0 0">Doctors you saved with the heart button. Book again in one tap.</p></div>
    <a class="pa-btn" href="{{ route('doctors.index') }}">Find a doctor</a>
  </div>

  @include('account.health._flash')

  @if ($doctors->isEmpty())
    <div class="pa-card pa-card-pad"><p class="pa-muted" style="margin:0;text-align:center;font-size:14px">No saved doctors yet. Open a doctor’s profile and tap <b>♥ Save to My doctors</b>.</p></div>
  @else
    <div class="pa-hl-grid">
      @foreach ($doctors as $doctor)
        <div class="pa-hl-doc">
          @if ($doctor->user?->avatar)
            <img src="{{ \App\Support\Widgets\WidgetDataService::mediaUrl($doctor->user->avatar) }}" alt="" style="width:56px;height:56px;border-radius:50%;object-fit:cover">
          @else
            <div class="pa-avatar" style="width:56px;height:56px;font-size:18px;flex:none">{{ collect(explode(' ', $doctor->user?->name ?? '?'))->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('') }}</div>
          @endif
          <div style="min-width:0;flex:1">
            <div style="font-weight:900;font-size:15px">{{ $doctor->displayName() }}</div>
            <div class="meta">{{ $doctor->specialization }}@if($doctor->clinics->first()) · {{ $doctor->clinics->first()->name }}@endif</div>
            <div class="acts">
              <a class="pa-btn pa-btn-sm" href="{{ route('doctors.show', $doctor) }}#booking-form">Book</a>
              <form method="POST" action="{{ route('account.health.favourites.toggle', $doctor->id) }}" class="pa-hl-inline">
                @csrf
                <button class="pa-btn-ghost pa-btn-sm" type="submit">Remove</button>
              </form>
            </div>
          </div>
        </div>
      @endforeach
    </div>
  @endif
</div>
</div>
</x-layout>
