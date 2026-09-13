<x-layout title="My Dashboard — Prompt Aid">
    <section class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <h1 class="text-2xl font-bold text-secondary-500">Welcome back, {{ auth()->user()->name }}</h1>

        <div class="mt-8 grid gap-6 lg:grid-cols-3">
            {{-- Appointments --}}
            <div class="card lg:col-span-2">
                <div class="flex items-center justify-between">
                    <h2 class="font-semibold text-secondary-500">Upcoming appointments</h2>
                    <a href="{{ route('doctors.index') }}" class="text-xs font-semibold text-primary-600">+ Book new</a>
                </div>
                <div class="mt-4 divide-y divide-gray-100">
                    @forelse ($appointments as $appt)
                        <div class="flex items-center justify-between py-4">
                            <div>
                                <p class="font-medium text-secondary-500">{{ $appt->doctor->user->name }}</p>
                                <p class="text-xs text-gray-500">{{ $appt->clinic->name }} &middot; {{ $appt->date->format('d M Y') }} at {{ $appt->start_time }}</p>
                            </div>
                            <span @class([
                                'badge',
                                'badge-success' => in_array($appt->status, ['completed', 'confirmed']),
                                'badge-warning' => in_array($appt->status, ['pending', 'checked_in']),
                                'badge-danger' => in_array($appt->status, ['cancelled', 'no_show']),
                            ])>{{ str($appt->status)->headline() }}</span>
                        </div>
                    @empty
                        <p class="py-6 text-center text-sm text-gray-500">No appointments yet. <a href="{{ route('doctors.index') }}" class="text-primary-600">Book one now</a>.</p>
                    @endforelse
                </div>
            </div>

            {{-- Request a ride --}}
            <div class="card">
                <h2 class="font-semibold text-secondary-500">🚐 Request a shuttle</h2>
                <p class="mt-1 text-xs text-gray-500">Get picked up and taken to your next appointment, free.</p>
                <form method="POST" action="{{ route('rides.store') }}" class="mt-4 space-y-3">
                    @csrf
                    <div>
                        <label class="text-xs font-semibold text-gray-500">Pickup address</label>
                        <input type="text" name="pickup_address" required class="input mt-1" placeholder="Your address">
                        <input type="hidden" name="pickup_lat" value="{{ auth()->user()->patientProfile->lat ?? -26.1076 }}">
                        <input type="hidden" name="pickup_lng" value="{{ auth()->user()->patientProfile->lng ?? 28.0567 }}">
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-gray-500">Clinic</label>
                        <select name="clinic_id" class="input mt-1">
                            @foreach (\App\Models\Clinic::where('status', 'active')->get() as $clinic)
                                <option value="{{ $clinic->id }}" data-lat="{{ $clinic->lat }}" data-lng="{{ $clinic->lng }}" data-address="{{ $clinic->address }}">{{ $clinic->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-gray-500">Vehicle type</label>
                        <select name="vehicle_type" class="input mt-1">
                            @foreach (\App\Models\RideRateCard::where('is_active', true)->get() as $card)
                                <option value="{{ $card->vehicle_type }}">{{ str($card->vehicle_type)->headline() }} — from R{{ number_format($card->minimum_fare, 0) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button class="btn-primary w-full !py-2.5 text-sm">Request Ride</button>
                </form>

                @if ($activeRide)
                    <a href="{{ route('rides.track', $activeRide) }}" class="mt-4 block rounded-lg bg-accent-50 p-3 text-xs text-accent-600 hover:bg-accent-100">
                        Active ride <strong>{{ $activeRide->ride_ref }}</strong> — status: {{ str($activeRide->status)->headline() }}
                        @if ($activeRide->driver)
                            <br>Driver: {{ $activeRide->driver->user->name }} ({{ $activeRide->driver->vehicle_plate_no }})
                        @endif
                        <span class="mt-1 block font-semibold">📍 Track live on map →</span>
                    </a>
                @endif
            </div>
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-2">
            {{-- Invoices --}}
            <div class="card">
                <h2 class="font-semibold text-secondary-500">Recent invoices</h2>
                <div class="mt-4 divide-y divide-gray-100">
                    @forelse ($invoices as $invoice)
                        <div class="flex items-center justify-between py-3 text-sm">
                            <span>{{ $invoice->invoice_no }}</span>
                            <span class="font-semibold">R{{ number_format($invoice->total, 2) }}</span>
                            <span @class(['badge', 'badge-success' => $invoice->status === 'paid', 'badge-warning' => $invoice->status !== 'paid'])>{{ str($invoice->status)->headline() }}</span>
                        </div>
                    @empty
                        <p class="py-6 text-center text-sm text-gray-500">No invoices yet.</p>
                    @endforelse
                </div>
            </div>

            {{-- Ride history --}}
            <div class="card">
                <h2 class="font-semibold text-secondary-500">Ride history</h2>
                <div class="mt-4 divide-y divide-gray-100">
                    @forelse ($rides as $ride)
                        <div class="flex items-center justify-between py-3 text-sm">
                            <span>{{ $ride->dropoff_address }}</span>
                            <span @class(['badge', 'badge-success' => $ride->status === 'completed', 'badge-warning' => !in_array($ride->status, ['completed','cancelled']), 'badge-danger' => $ride->status === 'cancelled'])>{{ str($ride->status)->headline() }}</span>
                        </div>
                    @empty
                        <p class="py-6 text-center text-sm text-gray-500">No rides yet.</p>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-2">
            {{-- Lab / diagnostic requests --}}
            <div class="card">
                <h2 class="font-semibold text-secondary-500">🧪 Lab &amp; diagnostic results</h2>
                <div class="mt-4 divide-y divide-gray-100">
                    @forelse ($labRequests as $req)
                        <div class="py-3 text-sm">
                            <div class="flex items-center justify-between">
                                <span class="font-medium text-secondary-500">{{ $req->doctor->user->name }}</span>
                                <span @class(['badge', 'badge-success' => $req->status === 'completed', 'badge-warning' => !in_array($req->status, ['completed','cancelled']), 'badge-danger' => $req->status === 'cancelled'])>{{ str($req->status)->headline() }}</span>
                            </div>
                            <p class="text-xs text-gray-500 mt-1">{{ $req->items->pluck('test_name')->implode(', ') }}</p>
                            @foreach ($req->results as $result)
                                <a href="{{ \Illuminate\Support\Facades\Storage::url($result->file_path) }}" target="_blank" class="mt-2 inline-flex items-center gap-1 text-xs font-semibold text-primary-600">📄 {{ $result->label }} — View report</a>
                            @endforeach
                        </div>
                    @empty
                        <p class="py-6 text-center text-sm text-gray-500">No lab requests yet.</p>
                    @endforelse
                </div>
            </div>

            {{-- Pharmacy orders --}}
            <div class="card">
                <h2 class="font-semibold text-secondary-500">💊 Pharmacy orders</h2>
                <p class="mt-1 text-xs text-gray-500"><a href="{{ route('pharmacies.index') }}" class="text-primary-600 font-semibold">Browse the marketplace →</a></p>
                <div class="mt-4 divide-y divide-gray-100">
                    @forelse ($orders as $order)
                        <div class="flex items-center justify-between py-3 text-sm">
                            <div>
                                <span class="font-medium text-secondary-500">{{ $order->order_no }}</span>
                                <p class="text-xs text-gray-500">{{ $order->pharmacy->name }} &middot; R{{ number_format($order->total, 2) }}</p>
                            </div>
                            <span @class(['badge', 'badge-success' => in_array($order->status, ['delivered','confirmed']), 'badge-warning' => in_array($order->status, ['pending_payment','awaiting_prescription_review','preparing','out_for_delivery']), 'badge-danger' => $order->status === 'cancelled'])>{{ str($order->status)->headline() }}</span>
                        </div>
                    @empty
                        <p class="py-6 text-center text-sm text-gray-500">No orders yet.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </section>

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
