<x-layout>
    {{-- Hero --}}
    <section class="relative overflow-hidden bg-gradient-to-br from-primary-50 via-white to-accent-50">
        <div class="mx-auto grid max-w-7xl items-center gap-12 px-4 py-20 sm:px-6 lg:grid-cols-2 lg:px-8">
            <div>
                <span class="badge badge-info">Clinics &middot; Doctors &middot; Patient Shuttle</span>
                <h1 class="mt-5 text-4xl font-extrabold leading-tight text-secondary-500 sm:text-5xl">
                    Healthcare that comes to you, <span class="text-primary-500">start to finish.</span>
                </h1>
                <p class="mt-5 max-w-xl text-lg text-gray-600">
                    Find trusted doctors and clinics, book appointments in seconds, and get a free door-to-door
                    shuttle so getting to your appointment is never the hard part.
                </p>
                <div class="mt-8 flex flex-wrap gap-4">
                    <a href="{{ route('doctors.index') }}" class="btn-primary">Find a Doctor</a>
                    <a href="{{ route('register') }}" class="btn-outline">Create Free Account</a>
                </div>
                <div class="mt-10 flex flex-wrap gap-8 text-sm text-gray-500">
                    <div><span class="text-2xl font-bold text-secondary-500">{{ $stats['clinics'] }}+</span><br>Partner Clinics</div>
                    <div><span class="text-2xl font-bold text-secondary-500">{{ $stats['doctors'] }}+</span><br>Verified Doctors</div>
                    <div><span class="text-2xl font-bold text-secondary-500">{{ $stats['drivers'] }}+</span><br>Shuttle Drivers</div>
                </div>
            </div>
            <div class="relative">
                <img src="https://picsum.photos/seed/promptaid-hero/800/560" alt="Doctor consulting a patient" class="w-full rounded-[20px] object-cover shadow-[0_20px_50px_-15px_rgba(0,31,77,0.35)]" style="aspect-ratio: 4/3;">
                <div class="card absolute -bottom-10 -left-6 hidden w-72 sm:block">
                    <div class="flex items-center gap-3 border-b border-gray-100 pb-4">
                        <div class="flex h-10 w-10 items-center justify-center rounded-full bg-primary-500/10 text-primary-600">🩺</div>
                        <div>
                            <p class="text-sm font-semibold text-secondary-500">Book an appointment</p>
                            <p class="text-xs text-gray-500">Pick a doctor, date and time</p>
                        </div>
                    </div>
                    <div class="mt-4 flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-full bg-accent-500/10 text-accent-600">🚐</div>
                        <div>
                            <p class="text-sm font-semibold text-secondary-500">Request your free ride</p>
                            <p class="text-xs text-gray-500">Tracked live to your door</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- How it works --}}
    <section id="how-it-works" class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8">
        <h2 class="text-center text-3xl font-bold text-secondary-500">How Prompt Aid works</h2>
        <div class="mt-12 grid gap-8 md:grid-cols-3">
            <div class="card text-center">
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-primary-500 text-lg font-bold text-white">1</div>
                <h3 class="mt-4 font-semibold text-secondary-500">Find your doctor</h3>
                <p class="mt-2 text-sm text-gray-500">Search by specialty or clinic, check availability, and book instantly.</p>
            </div>
            <div class="card text-center">
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-primary-500 text-lg font-bold text-white">2</div>
                <h3 class="mt-4 font-semibold text-secondary-500">Request a shuttle</h3>
                <p class="mt-2 text-sm text-gray-500">Need a ride? A nearby driver is matched to take you door-to-door.</p>
            </div>
            <div class="card text-center">
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-primary-500 text-lg font-bold text-white">3</div>
                <h3 class="mt-4 font-semibold text-secondary-500">Manage your care</h3>
                <p class="mt-2 text-sm text-gray-500">Prescriptions, invoices and appointment history — all in your dashboard.</p>
            </div>
        </div>
    </section>

    {{-- Specialties --}}
    <section class="bg-white py-20">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <h2 class="text-center text-3xl font-bold text-secondary-500">Popular specialties</h2>
            <div class="mt-10 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                @foreach (['General Practitioner' => '🩺', 'Pediatrician' => '🧒', 'Dermatologist' => '🧴', 'Cardiologist' => '❤️', 'Gynaecologist' => '🌸', 'ENT Specialist' => '👂'] as $spec => $icon)
                    <a href="{{ route('doctors.index', ['specialization' => $spec]) }}" class="card flex flex-col items-center gap-2 text-center transition hover:-translate-y-1 hover:shadow-lg">
                        <span class="text-3xl">{{ $icon }}</span>
                        <span class="text-sm font-medium text-secondary-500">{{ $spec }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Ride CTA --}}
    <section id="ride" class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8">
        <div class="overflow-hidden rounded-2xl bg-secondary-500 px-8 py-14 text-center text-white sm:px-16">
            <h2 class="text-3xl font-bold">Never miss an appointment for lack of a ride.</h2>
            <p class="mx-auto mt-4 max-w-2xl text-gray-300">Prompt Aid's patient shuttle connects you with a nearby driver, tracked live from pickup to the clinic door — free for every booked appointment.</p>
            <a href="{{ route('register') }}" class="btn-primary mt-8 inline-flex">Get Started Free</a>
        </div>
    </section>
</x-layout>
