<x-layout title="Find a Doctor — Prompt Aid">
    <section class="border-b border-gray-100 bg-white">
        <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
            <h1 class="text-3xl font-bold text-secondary-500">Find a Doctor</h1>
            <p class="mt-2 text-gray-500">Browse our network of verified doctors and book an appointment in minutes.</p>
            <form method="GET" class="mt-6 flex flex-wrap gap-3">
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Doctor name..." class="input max-w-xs">
                <input type="text" name="specialization" value="{{ request('specialization') }}" placeholder="Specialization..." class="input max-w-xs">
                <button class="btn-primary !px-6 !py-2.5 text-sm">Search</button>
            </form>
        </div>
    </section>

    <section class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
        <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @forelse ($doctors as $doctor)
                @php
                    $available = $doctor->isAvailableForBooking();
                    $schedule = $doctor->availabilitySummary();
                @endphp
                <a href="{{ route('doctors.show', $doctor) }}" class="card card-hover flex flex-col">
                    <div class="flex items-start justify-between">
                        <div class="flex items-center gap-4">
                            <img src="https://i.pravatar.cc/300?u=doctor{{ $doctor->id }}" alt="{{ $doctor->user->name }}" class="h-16 w-16 rounded-full object-cover avatar-ring">
                            <div>
                                <p class="font-semibold text-secondary-500">{{ $doctor->user->name }}</p>
                                <span class="badge badge-info mt-1">{{ $doctor->specialization }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4">
                        @if ($available)
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

                    @if ($schedule)
                        <p class="mt-2 text-xs text-gray-500">🕐 {{ $schedule }}</p>
                    @endif

                    <div class="mt-4 flex items-center justify-between text-sm border-t border-gray-100 pt-4">
                        <span class="inline-flex items-center gap-1 font-semibold text-secondary-500">
                            <svg class="h-4 w-4 text-warning-500" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.957a1 1 0 00.95.69h4.162c.969 0 1.371 1.24.588 1.81l-3.368 2.448a1 1 0 00-.364 1.118l1.287 3.957c.3.922-.755 1.688-1.54 1.118l-3.367-2.448a1 1 0 00-1.175 0l-3.367 2.448c-.784.57-1.838-.196-1.539-1.118l1.286-3.957a1 1 0 00-.363-1.118L2.98 9.384c-.783-.57-.38-1.81.588-1.81h4.163a1 1 0 00.95-.69l1.286-3.957z"/></svg>
                            {{ number_format($doctor->rating_avg, 1) }} <span class="text-gray-400 font-normal">({{ $doctor->rating_count }})</span>
                        </span>
                        <span class="font-semibold text-secondary-500">R{{ number_format($doctor->consultation_fee, 0) }}</span>
                    </div>
                    <p class="mt-3 text-xs text-gray-500">📍 {{ $doctor->clinics->pluck('name')->implode(', ') }}</p>
                </a>
            @empty
                <p class="col-span-full text-center text-gray-500">No doctors found. Try a different search.</p>
            @endforelse
        </div>
        <div class="mt-10">{{ $doctors->links() }}</div>
    </section>
</x-layout>
