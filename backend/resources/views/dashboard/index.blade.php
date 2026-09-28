<x-layout title="My care · Prompt Aid">
<div class="pa-account">
<x-account-nav active="overview" />
<div>
  <div class="pa-spread" style="margin-bottom:22px">
    <div><h1 style="font-size:32px">My care</h1><p class="pa-muted" style="margin:6px 0 0">Everything booked, ordered and recorded in one place.</p></div>
    <div style="display:flex;gap:10px;flex-wrap:wrap"><a class="pa-btn-ghost" href="{{ route('account.profile') }}">Profile</a><a class="pa-btn" href="{{ route('doctors.index') }}">Book care</a></div>
  </div>

  @php
    $nextAppt = $appointments->first(fn ($a) => $a->date->isToday() || $a->date->isFuture());
    $activeScripts = $labRequests->flatMap->items->count();
    $outstanding = $invoices->where('status', '!=', 'paid')->sum('total');
  @endphp
  <div class="pa-grid" style="grid-template-columns:repeat(auto-fit,minmax(190px,1fr));margin-bottom:24px">
    <div class="pa-stat">
      <div class="k">Next appointment</div>
      <div class="v">{{ $nextAppt ? \Illuminate\Support\Carbon::parse($nextAppt->start_time)->format('H:i') : '—' }}</div>
      <div class="d go">{{ $nextAppt ? $nextAppt->date->format('d M').' · '.$nextAppt->doctor->user->name : 'Nothing booked' }}</div>
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

  <div style="display:grid;grid-template-columns:minmax(0,1.5fr) minmax(0,1fr);gap:22px;align-items:start">
    <div>
      <div class="pa-card" style="margin-bottom:22px">
        <div class="pa-card-head"><h3>Upcoming appointments</h3></div>
        @forelse ($appointments as $appt)
          <div class="pa-spread" style="padding:16px 22px;border-bottom:1px solid var(--pa-line-soft)">
            <div>
              <div style="font-size:15px;font-weight:700">{{ $appt->doctor->user->name }}</div>
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
              <div style="font-size:15px;font-weight:700">{{ $req->doctor->user->name }}</div>
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
    <form method="POST" action="{{ route('rides.store') }}" style="padding:18px 22px;display:grid;gap:16px">
      @csrf
      <div class="pa-formrow">
        <label class="pa-label">Pickup address</label>
        <input type="text" name="pickup_address" required class="pa-field" placeholder="Your address">
        <input type="hidden" name="pickup_lat" value="{{ auth()->user()->patientProfile->lat ?? -26.1076 }}">
        <input type="hidden" name="pickup_lng" value="{{ auth()->user()->patientProfile->lng ?? 28.0567 }}">
      </div>
      <div class="pa-formgrid">
        <div>
          <label class="pa-label">Clinic</label>
          <select name="clinic_id" class="pa-field">
            @foreach (\App\Models\Clinic::where('status', 'active')->get() as $clinic)
              <option value="{{ $clinic->id }}" data-lat="{{ $clinic->lat }}" data-lng="{{ $clinic->lng }}" data-address="{{ $clinic->address }}">{{ $clinic->name }}</option>
            @endforeach
          </select>
        </div>
        <div>
          <label class="pa-label">Vehicle type</label>
          <select name="vehicle_type" class="pa-field">
            @foreach (\App\Models\RideRateCard::where('is_active', true)->get() as $card)
              <option value="{{ $card->vehicle_type }}">{{ str($card->vehicle_type)->headline() }} — from R{{ number_format($card->minimum_fare, 0) }}</option>
            @endforeach
          </select>
        </div>
      </div>
      <button class="pa-btn pa-btn-lg">Request ride</button>
    </form>
  </div>
</div>
</div>

<script>
    document.querySelector('select[name="clinic_id"]')?.addEventListener('change', function () {
        const opt = this.selectedOptions[0];
        const form = this.closest('form');
        let latInput = form.querySelector('input[name="dropoff_lat"]');
        let lngInput = form.querySelector('input[name="dropoff_lng"]');
        let addrInput = form.querySelector('input[name="dropoff_address"]');
        if (!latInput) { latInput = document.createElement('input'); latInput.type = 'hidden'; latInput.name = 'dropoff_lat'; form.appendChild(latInput); }
        if (!lngInput) { lngInput = document.createElement('input'); lngInput.type = 'hidden'; lngInput.name = 'dropoff_lng'; form.appendChild(lngInput); }
        if (!addrInput) { addrInput = document.createElement('input'); addrInput.type = 'hidden'; addrInput.name = 'dropoff_address'; form.appendChild(addrInput); }
        latInput.value = opt.dataset.lat; lngInput.value = opt.dataset.lng; addrInput.value = opt.dataset.address;
    });
</script>
</x-layout>
