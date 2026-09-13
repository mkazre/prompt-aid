<x-layout :title="$doctor->user->name.' — Prompt Aid'">
    <section class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
        <div class="grid gap-10 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <div class="card">
                    <div class="flex items-center gap-5">
                        <img src="https://i.pravatar.cc/300?u=doctor{{ $doctor->id }}" alt="{{ $doctor->user->name }}" class="h-20 w-20 rounded-full object-cover avatar-ring">
                        <div>
                            <h1 class="text-2xl font-bold text-secondary-500">{{ $doctor->user->name }}</h1>
                            <p class="text-gray-500">{{ $doctor->specialization }} &middot; {{ $doctor->experience_years }} yrs experience</p>
                            <span class="badge badge-info mt-2">★ {{ number_format($doctor->rating_avg, 1) }} ({{ $doctor->rating_count }} reviews)</span>
                        </div>
                    </div>
                    <p class="mt-6 text-sm leading-relaxed text-gray-600">{{ $doctor->bio }}</p>
                    <div class="mt-6 border-t border-gray-100 pt-6">
                        <h3 class="font-semibold text-secondary-500">Practices at</h3>
                        <ul class="mt-3 space-y-2">
                            @foreach ($doctor->clinics as $clinic)
                                <li class="text-sm text-gray-600">📍 {{ $clinic->name }} — {{ $clinic->address }}, {{ $clinic->city }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>

            <div>
                <div class="card sticky top-24">
                    <h3 class="font-semibold text-secondary-500">Book an appointment</h3>
                    <p class="mt-1 text-sm text-gray-500">Consultation fee: <span class="font-semibold text-secondary-500">R{{ number_format($doctor->consultation_fee, 0) }}</span></p>

                    @guest
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
                    @endguest
                </div>
            </div>
        </div>
    </section>
</x-layout>
