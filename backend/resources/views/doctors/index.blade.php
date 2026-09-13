<x-layout title="Find a Doctor — Prompt Aid">
    <section class="border-b border-gray-100 bg-white">
        <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
            <h1 class="text-3xl font-bold text-secondary-500">Find a Doctor</h1>
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
                <a href="{{ route('doctors.show', $doctor) }}" class="card card-hover flex flex-col">
                    <div class="flex items-center gap-4">
                        <img src="https://i.pravatar.cc/300?u=doctor{{ $doctor->id }}" alt="{{ $doctor->user->name }}" class="h-14 w-14 rounded-full object-cover avatar-ring">
                        <div>
                            <p class="font-semibold text-secondary-500">{{ $doctor->user->name }}</p>
                            <p class="text-sm text-gray-500">{{ $doctor->specialization }}</p>
                        </div>
                    </div>
                    <div class="mt-4 flex items-center justify-between text-sm">
                        <span class="badge badge-info">★ {{ number_format($doctor->rating_avg, 1) }} ({{ $doctor->rating_count }})</span>
                        <span class="font-semibold text-secondary-500">R{{ number_format($doctor->consultation_fee, 0) }}</span>
                    </div>
                    <p class="mt-3 text-xs text-gray-500">{{ $doctor->clinics->pluck('name')->implode(', ') }}</p>
                </a>
            @empty
                <p class="col-span-full text-center text-gray-500">No doctors found. Try a different search.</p>
            @endforelse
        </div>
        <div class="mt-10">{{ $doctors->links() }}</div>
    </section>
</x-layout>
