<x-layout :title="$pharmacy->name.' — Prompt Aid'">
    <section class="border-b border-gray-100 bg-white">
        <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
            <h1 class="text-3xl font-bold text-secondary-500">{{ $pharmacy->name }}</h1>
            <p class="mt-2 text-gray-500">{{ $pharmacy->address }}, {{ $pharmacy->city }} &middot; ★ {{ number_format($pharmacy->rating_avg, 1) }} &middot; Delivery R{{ number_format($pharmacy->delivery_fee, 0) }}</p>
        </div>
    </section>

    @guest
        <section class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
            <div class="card text-center">
                <p class="text-gray-600">Please <a href="{{ route('login') }}" class="font-semibold text-primary-600">sign in</a> to order from this pharmacy.</p>
            </div>
        </section>
    @else
        <form method="POST" action="{{ route('pharmacies.checkout', $pharmacy) }}" enctype="multipart/form-data" class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
            @csrf

            @if ($errors->any())
                <div class="mb-6 rounded-lg bg-danger-500/10 p-4 text-sm text-danger-500">{{ $errors->first() }}</div>
            @endif

            <div class="grid gap-8 lg:grid-cols-3">
                <div class="lg:col-span-2">
                    <div class="grid gap-4 sm:grid-cols-2">
                        @foreach ($products->groupBy('category') as $category => $items)
                            <div class="sm:col-span-2">
                                <h2 class="mt-4 mb-2 text-sm font-bold uppercase tracking-wide text-gray-400">{{ $category ?? 'Other' }}</h2>
                            </div>
                            @foreach ($items as $product)
                                <div class="card flex gap-4">
                                    <img src="https://picsum.photos/seed/product{{ $product->id }}/160/160" alt="{{ $product->name }}" class="h-16 w-16 rounded-lg object-cover">
                                    <div class="flex-1">
                                        <p class="font-semibold text-secondary-500 text-sm">{{ $product->name }}</p>
                                        <p class="text-xs text-gray-500 mt-1">R{{ number_format($product->price, 2) }}</p>
                                        @if ($product->requires_prescription)
                                            <span class="badge badge-warning mt-1">Prescription required</span>
                                        @endif
                                        <div class="mt-2 flex items-center gap-2">
                                            <label class="text-xs text-gray-500">Qty</label>
                                            <input type="number" name="qty[{{ $product->id }}]" min="0" max="{{ $product->stock }}" value="0" class="input !py-1 !px-2 w-20 text-sm">
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        @endforeach
                    </div>
                </div>

                <div>
                    <div class="card sticky top-24">
                        <h3 class="font-semibold text-secondary-500">Checkout</h3>
                        <div class="mt-4">
                            <label class="text-xs font-semibold text-gray-500">Delivery address</label>
                            <input type="text" name="delivery_address" required class="input mt-1" value="{{ auth()->user()->patientProfile->address ?? '' }}">
                        </div>
                        <div class="mt-4">
                            <label class="text-xs font-semibold text-gray-500">Upload prescription (if required)</label>
                            <input type="file" name="prescription" class="input mt-1 !py-1.5">
                            <p class="mt-1 text-xs text-gray-400">Only needed if your order includes a prescription item.</p>
                        </div>
                        <button class="btn-primary w-full mt-5">Place Order</button>
                    </div>
                </div>
            </div>
        </form>
    @endguest
</x-layout>
