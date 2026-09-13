<x-layout title="Pharmacy Marketplace — Prompt Aid">
    <section class="border-b border-gray-100 bg-white">
        <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
            <span class="section-eyebrow">Marketplace</span>
            <h1 class="mt-3 text-3xl font-bold text-secondary-500">Pharmacy Marketplace</h1>
            <p class="mt-2 text-gray-500">Order medication and health products for delivery from licensed partner pharmacies.</p>
        </div>
    </section>

    <section class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
        <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($pharmacies as $pharmacy)
                <a href="{{ route('pharmacies.show', $pharmacy) }}" class="card card-hover !p-0 overflow-hidden">
                    <img src="https://picsum.photos/seed/pharmacy{{ $pharmacy->id }}/600/320" alt="{{ $pharmacy->name }}" class="h-40 w-full object-cover">
                    <div class="p-6">
                        <h3 class="font-semibold text-secondary-500">{{ $pharmacy->name }}</h3>
                        <p class="mt-1 text-sm text-gray-500">{{ $pharmacy->city }}</p>
                        <div class="mt-3 flex items-center justify-between text-sm">
                            <span class="badge badge-info">★ {{ number_format($pharmacy->rating_avg, 1) }}</span>
                            <span class="text-xs text-gray-500">{{ $pharmacy->products_count }} products</span>
                        </div>
                        <p class="mt-2 text-xs text-gray-500">Delivery from R{{ number_format($pharmacy->delivery_fee, 0) }}</p>
                    </div>
                </a>
            @endforeach
        </div>
        <div class="mt-10">{{ $pharmacies->links() }}</div>
    </section>
</x-layout>
