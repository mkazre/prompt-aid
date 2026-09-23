<x-layout :title="$doctor->user->name.' — Prompt Aid'">
    <section class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
        <div class="grid gap-10 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <div class="card">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="flex items-center gap-5">
                            <img src="https://i.pravatar.cc/300?u=doctor{{ $doctor->id }}" alt="{{ $doctor->user->name }}" class="h-20 w-20 rounded-full object-cover avatar-ring">
                            <div>
                                <h1 class="text-2xl font-bold text-secondary-500">{{ $doctor->user->name }}</h1>
                                <p class="text-gray-500">{{ $doctor->specialization }} &middot; {{ $doctor->experience_years }} yrs experience</p>
                                <span class="badge badge-info mt-2">★ {{ number_format($doctor->rating_avg, 1) }} ({{ $doctor->rating_count }} reviews)</span>
                            </div>
                        </div>
                        @if ($doctor->isAvailableForBooking())
                            <span class="inline-flex items-center gap-1.5 badge badge-success">
                                <span class="h-1.5 w-1.5 rounded-full bg-success-500"></span>
                                Accepting appointments
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1.5 badge bg-gray-100 text-gray-500">
                                <span class="h-1.5 w-1.5 rounded-full bg-gray-400"></span>
                                Not accepting new patients
                            </span>
                        @endif
                    </div>
                    <p class="mt-6 text-sm leading-relaxed text-gray-600">{{ $doctor->bio }}</p>

                    @if ($schedule = $doctor->availabilitySummary())
                        <div class="mt-6 border-t border-gray-100 pt-6">
                            <h3 class="font-semibold text-secondary-500">Availability</h3>
                            <p class="mt-2 text-sm text-gray-600">🕐 {{ $schedule }}</p>
                        </div>
                    @endif

                    <div class="mt-6 border-t border-gray-100 pt-6">
                        <h3 class="font-semibold text-secondary-500">Practices at</h3>
                        <ul class="mt-3 space-y-2">
                            @foreach ($doctor->clinics as $clinic)
                                <li class="text-sm text-gray-600">📍 {{ $clinic->name }} — {{ $clinic->address }}, {{ $clinic->city }}</li>
                            @endforeach
                        </ul>
                    </div>

                    @if ($doctor->reviews->isNotEmpty())
                        <div class="mt-6 border-t border-gray-100 pt-6">
                            <h3 class="font-semibold text-secondary-500">Patient reviews</h3>
                            <div class="mt-3 space-y-4">
                                @foreach ($doctor->reviews as $review)
                                    <div class="rounded-[10px] bg-gray-50 p-4">
                                        <div class="flex items-center justify-between">
                                            <span class="text-sm font-semibold text-secondary-500">{{ $review->patient->user->name ?? 'Patient' }}</span>
                                            <span class="text-xs text-warning-500">{{ str_repeat('★', $review->rating) }}{{ str_repeat('☆', 5 - $review->rating) }}</span>
                                        </div>
                                        @if ($review->comment)
                                            <p class="mt-1 text-sm text-gray-600">{{ $review->comment }}</p>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            <div>
                <div class="card sticky top-24">
                    <h3 class="font-semibold text-secondary-500">Book an appointment</h3>
                    <p class="mt-1 text-sm text-gray-500">Consultation fee: <span class="font-semibold text-secondary-500">R{{ number_format($doctor->consultation_fee, 0) }}</span></p>

                    @unless ($doctor->isAvailableForBooking())
                        <div class="mt-5 rounded-[10px] bg-gray-50 p-4 text-sm text-gray-600">
                            This doctor is not currently accepting new appointments. Please check back later or browse other available doctors.
                        </div>
                    @elseif (! auth()->check())
                        <a href="{{ route('login') }}" class="btn-primary mt-5 w-full">Sign in to book</a>
                    @else
                        <form method="POST" action="{{ route('appointments.store') }}" class="mt-5 space-y-4" id="booking-form">
                            @csrf
                            <input type="hidden" name="doctor_profile_id" value="{{ $doctor->id }}">

                            <div>
                                <label class="text-xs font-semibold text-gray-500">Clinic</label>
                                <select name="clinic_id" id="clinic_id" class="input mt-1">
                                    @foreach ($doctor->clinics as $clinic)
                                        <option value="{{ $clinic->id }}">{{ $clinic->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-gray-500">Date</label>
                                <input type="date" name="date" id="date" class="input mt-1" min="{{ now()->toDateString() }}" value="{{ now()->addDay()->toDateString() }}">
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-gray-500">Time slot</label>
                                <select name="start_time" id="start_time" class="input mt-1">
                                    <option value="">Select date & clinic first</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-gray-500">Visit type</label>
                                <select name="visit_type" class="input mt-1">
                                    <option value="clinic">At Clinic</option>
                                    <option value="telemed">Telemedicine</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-gray-500">Reason for visit (optional)</label>
                                <textarea name="reason" rows="2" class="input mt-1"></textarea>
                            </div>
                            <button class="btn-primary w-full">Confirm Booking</button>
                        </form>

                        <script>
                            const clinicSelect = document.getElementById('clinic_id');
                            const dateInput = document.getElementById('date');
                            const slotSelect = document.getElementById('start_time');

                            async function loadSlots() {
                                slotSelect.innerHTML = '<option>Loading...</option>';
                                const res = await fetch(`{{ route('doctors.slots', $doctor) }}?clinic_id=${clinicSelect.value}&date=${dateInput.value}`);
                                const data = await res.json();
                                slotSelect.innerHTML = '';
                                if (!data.slots.length) {
                                    slotSelect.innerHTML = '<option value="">No slots available this day</option>';
                                    return;
                                }
                                data.slots.forEach(s => {
                                    const opt = document.createElement('option');
                                    opt.value = s; opt.textContent = s;
                                    slotSelect.appendChild(opt);
                                });
                            }
                            clinicSelect.addEventListener('change', loadSlots);
                            dateInput.addEventListener('change', loadSlots);
                            loadSlots();
                        </script>
                    @endunless
                </div>
            </div>
        </div>
    </section>
</x-layout>
