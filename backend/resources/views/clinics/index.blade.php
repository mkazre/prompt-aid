<x-layout title="Our Clinics — Prompt Aid">
    <section class="border-b border-gray-100 bg-white">
        <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
            <h1 class="text-3xl font-bold text-secondary-500">Our Clinics</h1>
            <p class="mt-2 text-gray-500">Modern, patient-first clinics — each with a free door-to-door shuttle.</p>
        </div>
    </section>

    <section class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
        <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($clinics as $clinic)
                <div class="card card-hover !p-0 overflow-hidden">
                    <img src="https://picsum.photos/seed/clinic{{ $clinic->id }}/600/320" alt="{{ $clinic->name }}" class="h-40 w-full object-cover">
                    <div class="p-6">
                    <h3 class="font-semibold text-secondary-500">{{ $clinic->name }}</h3>
                    <p class="mt-1 text-sm text-gray-500">{{ $clinic->address }}, {{ $clinic->city }}</p>
                    <p class="mt-3 text-xs text-gray-500">{{ $clinic->doctors_count }} doctors available</p>
                    <div class="mt-3 flex flex-wrap gap-1">
                        @foreach ($clinic->specialties ?? [] as $s)
                            <span class="badge badge-info">{{ $s }}</span>
                        @endforeach
                    </div>
                    <a href="{{ route('doctors.index', ['clinic_id' => $clinic->id]) }}" class="btn-outline mt-5 w-full !py-2 text-xs">View Doctors</a>
                    </div>
                </div>
            @endforeach
        </div>
        <div class="mt-10">{{ $clinics->links() }}</div>
    </section>
</x-layout>
