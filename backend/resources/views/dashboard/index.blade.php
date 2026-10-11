<x-layout title="My care · Prompt Aid">
<div class="pa-account">
<x-account-nav active="overview" />
<div>
  <div class="pa-spread" style="margin-bottom:22px">
    <div><h1 style="font-size:32px">My care</h1><p class="pa-muted" style="margin:6px 0 0">Everything booked, ordered and recorded in one place.</p></div>
    <div style="display:flex;gap:10px;flex-wrap:wrap"><a class="pa-btn-ghost" href="{{ route('account.profile') }}">Profile</a><a class="pa-btn" href="{{ route('doctors.index') }}">Book care</a></div>
  </div>

  @if (\App\Support\DoctorNowSettings::bool('doctor_now_enabled'))
    <div class="pa-dn-cta">
      <div><h3>Need a doctor right now?</h3><p>Get matched with the next available doctor — video in minutes, or at rooms near you.</p></div>
      <a class="pa-btn-beacon" href="{{ route('account.doctor-now') }}">See a doctor now</a>
    </div>
  @endif

  {{-- Admin-configured widgets (Site → App Widgets, patient · home) — rendered per widget in DashboardController::widgetBlocks(). --}}
  @if (! empty($widgetBlocks ?? []))
    <div class="paw-grid">
      @foreach ($widgetBlocks as $block)
        <section class="paw-cell paw-{{ $block['span'] }}" data-widget="{{ $block['key'] }}">{!! $block['html'] !!}</section>
      @endforeach
    </div>
  @endif

  @php
    $nextAppt = $appointments->first(fn ($a) => $a->date->isToday() || $a->date->isFuture());
    $activeScripts = $labRequests->flatMap->items->count();
    $outstanding = $invoices->where('status', '!=', 'paid')->sum('total');
  @endphp
  {{-- Legacy summary row — the widgets above cover the same four numbers, so it only shows when no widgets are enabled. --}}
  @if (empty($widgetBlocks ?? []))
  <div class="pa-grid" style="grid-template-columns:repeat(auto-fit,minmax(190px,1fr));margin-bottom:24px">
    <div class="pa-stat">
      <div class="k">Next appointment</div>
      <div class="v">{{ $nextAppt ? \Illuminate\Support\Carbon::parse($nextAppt->start_time)->format('H:i') : '—' }}</div>
      <div class="d go">{{ $nextAppt ? $nextAppt->date->format('d M').' · '.($nextAppt->doctor?->user?->name ?? 'Finding your doctor…') : 'Nothing booked' }}</div>
    </div>
    <div class="pa-stat">
      <div class="k">Active ride</div>
      <div class="v">{{ $activeRide ? 'Yes' : 'No' }}</div>
      <div class="d {{ $activeRide ? 'wait' : 'go' }}">{{ $activeRide ? str($activeRide->status)->headline() : 'None in progress' }}</div>
    </div>
    <div class="pa-stat">
      <div class="k">Outstanding</div>
      <div class="v">R {{ number_format($outstanding, 0) }}</div>
      <div class="d {{ $outstanding > 0 ? 'stop' : 'go' }}">{{ $outstanding > 0 ? 'Across '.$invoices->where('status', '!=', 'paid')->count().' invoice(s)' : 'All settled' }}</div>
    </div>
    <div class="pa-stat">
      <div class="k">Lab requests</div>
      <div class="v">{{ $labRequests->count() }}</div>
      <div class="d wait">{{ $labRequests->flatMap->results->count() }} result(s) ready</div>
    </div>
  </div>
  @endif

  <div style="display:grid;grid-template-columns:minmax(0,1.5fr) minmax(0,1fr);gap:22px;align-items:start">
    <div>
      <div class="pa-card" style="margin-bottom:22px">
        <div class="pa-card-head"><h3>Upcoming appointments</h3></div>
        @forelse ($appointments as $appt)
          <div class="pa-spread" style="padding:16px 22px;border-bottom:1px solid var(--pa-line-soft)">
            <div>
              <div style="font-size:15px;font-weight:700">{{ $appt->doctor?->user?->name ?? 'Finding your doctor…' }}</div>
              <div style="font-size:13px;color:var(--pa-muted);margin-top:2px">{{ $appt->clinic->name }} · {{ $appt->date->format('d M Y') }} at {{ \Illuminate\Support\Carbon::parse($appt->start_time)->format('H:i') }}</div>
            </div>
            <span class="pa-badge {{ in_array($appt->status, ['completed', 'confirmed']) ? 'is-go' : (in_array($appt->status, ['cancelled', 'no_show']) ? 'is-stop' : 'is-wait') }}">{{ str($appt->status)->headline() }}</span>
          </div>
        @empty
          <p class="pa-muted" style="padding:24px;text-align:center;font-size:14px">No appointments yet. <a href="{{ route('doctors.index') }}">Book one now</a>.</p>
        @endforelse
      </div>

      <div class="pa-card" style="margin-bottom:22px">
        <div class="pa-card-head"><h3>Lab &amp; diagnostic results</h3></div>
        @forelse ($labRequests as $req)
          <div style="padding:16px 22px;border-bottom:1px solid var(--pa-line-soft)">
            <div class="pa-spread">
              <div style="font-size:15px;font-weight:700">{{ $req->doctor?->user?->name ?? '—' }}</div>
              <span class="pa-badge {{ $req->status === 'completed' ? 'is-go' : ($req->status === 'cancelled' ? 'is-stop' : 'is-wait') }}">{{ str($req->status)->headline() }}</span>
            </div>
            <div style="font-size:13px;color:var(--pa-muted);margin-top:4px">{{ $req->items->pluck('test_name')->implode(', ') }}</div>
            @foreach ($req->results as $result)
              <a href="{{ \Illuminate\Support\Facades\Storage::url($result->file_path) }}" target="_blank" style="display:inline-block;margin-top:8px;font-size:13px;font-weight:700;color:var(--pa-signal)">View report — {{ $result->label }}</a>
            @endforeach
          </div>
        @empty
          <p class="pa-muted" style="padding:24px;text-align:center;font-size:14px">No lab requests yet.</p>
        @endforelse
      </div>

      <div class="pa-card">
        <div class="pa-card-head"><h3>Pharmacy orders</h3><a class="pa-btn-ghost pa-btn-sm" href="{{ route('shop.index') }}">Browse the marketplace</a></div>
        @forelse ($orders as $order)
          <div class="pa-spread" style="padding:16px 22px;border-bottom:1px solid var(--pa-line-soft)">
            <div>
              <div style="font-size:15px;font-weight:700">{{ $order->order_no }}</div>
              <div style="font-size:13px;color:var(--pa-muted);margin-top:2px">{{ $order->pharmacy->name }} · R{{ number_format($order->total, 2) }}</div>
            </div>
            <span class="pa-badge {{ in_array($order->status, ['delivered', 'confirmed']) ? 'is-go' : ($order->status === 'cancelled' ? 'is-stop' : 'is-wait') }}">{{ str($order->status)->headline() }}</span>
          </div>
        @empty
          <p class="pa-muted" style="padding:24px;text-align:center;font-size:14px">No orders yet.</p>
        @endforelse
      </div>
    </div>

    <div>
      <div class="pa-card" style="margin-bottom:22px">
        <div class="pa-card-head"><h3>Quick actions</h3></div>
        <div style="padding:18px 22px;display:flex;flex-direction:column;gap:10px">
          <a class="pa-btn pa-btn-block" href="{{ route('doctors.index') }}">Book a doctor</a>
          <a class="pa-btn-ghost pa-btn-block" href="{{ route('shuttle') }}">Request a shuttle</a>
          <a class="pa-btn-ghost pa-btn-block" href="{{ route('shop.index') }}">Order medicine</a>
        </div>
      </div>

      <div class="pa-card">
        <div class="pa-card-head"><h3>Ride history</h3></div>
        @forelse ($rides as $ride)
          <div class="pa-spread" style="padding:14px 22px;border-bottom:1px solid var(--pa-line-soft)">
            <span style="font-size:13px">{{ $ride->dropoff_address }}</span>
            <span class="pa-badge {{ $ride->status === 'completed' ? 'is-go' : ($ride->status === 'cancelled' ? 'is-stop' : 'is-wait') }}">{{ str($ride->status)->headline() }}</span>
          </div>
        @empty
          <p class="pa-muted" style="padding:24px;text-align:center;font-size:14px">No rides yet.</p>
        @endforelse
      </div>

      @if ($activeRide)
        <div class="pa-note" style="margin-top:22px">
          Active ride <strong>{{ $activeRide->ride_ref }}</strong> — status: {{ str($activeRide->status)->headline() }}
          @if ($activeRide->driver)
            <br>Driver: {{ $activeRide->driver->user->name }} ({{ $activeRide->driver->vehicle_plate_no }})
          @endif
          <br><a href="{{ route('rides.track', $activeRide) }}" style="font-weight:700">Track live on map →</a>
        </div>
      @endif
    </div>
  </div>

  <div class="pa-card" style="margin-top:22px">
    <div class="pa-card-head"><h3>🚐 Request a shuttle</h3></div>
    @php
      // Real coordinates only: prefill pickup from the patient's saved address when it has coords.
      $shuttleProfile = auth()->user()->patientProfile;
      $shuttleHasHome = $shuttleProfile && $shuttleProfile->address && $shuttleProfile->lat !== null && $shuttleProfile->lng !== null;
      $shuttleClinics = \App\Models\Clinic::where('status', 'active')->orderBy('name')->get();
      $shuttleFirstClinic = $shuttleClinics->first();
    @endphp
    {{-- Quote first (real fares for every vehicle), then confirm on the shuttle page -> rides.store --}}
    <form method="POST" action="{{ route('shuttle.quote') }}" style="padding:18px 22px;display:grid;gap:16px" id="dashboard-shuttle-form">
      @csrf
      <div class="pa-formrow">
        <label class="pa-label" for="dash-pickup">Pickup address</label>
        <input type="text" id="dash-pickup" name="pickup_address" required maxlength="255" class="pa-field" placeholder="Start typing your address" value="{{ $shuttleHasHome ? $shuttleProfile->address : '' }}" data-pa-address-autocomplete>
        <input type="hidden" name="pickup_lat" value="{{ $shuttleHasHome ? $shuttleProfile->lat : '' }}">
        <input type="hidden" name="pickup_lng" value="{{ $shuttleHasHome ? $shuttleProfile->lng : '' }}">
        <button type="button" class="pa-ac-locate" data-pa-geolocate="pickup_address">Use my current location</button>
      </div>
      <div class="pa-formrow">
        <label class="pa-label" for="dash-clinic">Clinic</label>
        @if ($shuttleFirstClinic)
          <select id="dash-clinic" class="pa-field">
            @foreach ($shuttleClinics as $clinic)
              <option value="{{ $clinic->id }}" data-lat="{{ $clinic->lat }}" data-lng="{{ $clinic->lng }}" data-address="{{ $clinic->shuttleAddress() }}">{{ $clinic->name }}</option>
            @endforeach
          </select>
          <input type="hidden" name="dropoff_address" value="{{ $shuttleFirstClinic->shuttleAddress() }}">
          <input type="hidden" name="dropoff_lat" value="{{ $shuttleFirstClinic->lat }}">
          <input type="hidden" name="dropoff_lng" value="{{ $shuttleFirstClinic->lng }}">
        @else
          <input type="text" name="dropoff_address" required maxlength="255" class="pa-field" placeholder="Clinic, pharmacy or lab address" data-pa-address-autocomplete>
          <input type="hidden" name="dropoff_lat" value="">
          <input type="hidden" name="dropoff_lng" value="">
        @endif
      </div>
      <button class="pa-btn pa-btn-lg">Get fare estimate</button>
    </form>
  </div>
</div>
</div>

<script>
    document.getElementById('dash-clinic')?.addEventListener('change', function () {
        const opt = this.selectedOptions[0];
        const form = this.closest('form');
        form.querySelector('input[name="dropoff_address"]').value = opt.dataset.address || '';
        form.querySelector('input[name="dropoff_lat"]').value = opt.dataset.lat || '';
        form.querySelector('input[name="dropoff_lng"]').value = opt.dataset.lng || '';
    });
</script>
</x-layout>
