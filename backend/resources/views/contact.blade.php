<x-layout title="Contact Us — Prompt Aid">
    <section class="border-b border-gray-100 bg-white">
        <div class="mx-auto max-w-7xl px-4 py-16 text-center sm:px-6 lg:px-8">
            <div class="grid gap-10 sm:grid-cols-3">
                <div>
                    <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-primary-50 text-primary-600">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg>
                    </div>
                    <p class="mt-4 text-xs font-semibold uppercase tracking-wide text-primary-600">Location</p>
                    <p class="mt-2 font-semibold text-secondary-500">South Africa</p>
                </div>
                <div>
                    <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-primary-50 text-primary-600">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/></svg>
                    </div>
                    <p class="mt-4 text-xs font-semibold uppercase tracking-wide text-primary-600">Email</p>
                    <p class="mt-2 font-semibold text-secondary-500">{{ \App\Models\Setting::get('support_email') }}</p>
                </div>
                <div>
                    <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-primary-50 text-primary-600">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 6.75c0 8.284 6.716 15 15 15h1.5a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106a1.125 1.125 0 00-1.173.417l-.97 1.293a11.25 11.25 0 01-5.223-5.223l1.293-.97a1.125 1.125 0 00.417-1.173L9.213 3.102a1.125 1.125 0 00-1.091-.852H6.75A2.25 2.25 0 004.5 4.5v2.25z"/></svg>
                    </div>
                    <p class="mt-4 text-xs font-semibold uppercase tracking-wide text-primary-600">Call anytime</p>
                    <p class="mt-2 font-semibold text-secondary-500">{{ \App\Models\Setting::get('support_phone') }}</p>
                </div>
            </div>
        </div>
    </section>

    <section class="mx-auto max-w-3xl px-4 py-16 text-center sm:px-6 lg:px-8">
        <span class="section-eyebrow">Just a call away</span>
        <h1 class="mt-4 text-3xl font-bold text-secondary-500">We'd love to hear from you</h1>
        <p class="mt-3 text-gray-500">We are here and always ready to help. Let us know how we can serve you and we'll get back to you shortly.</p>

        @if (session('success'))
            <div class="mt-8 rounded-[10px] bg-success-500/10 p-4 text-sm font-medium text-success-500">
                {{ session('success') }}
            </div>
        @endif

        <form method="POST" action="{{ route('contact.submit') }}" class="mt-8 space-y-4 text-left">
            @csrf
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <input type="text" name="first_name" value="{{ old('first_name') }}" placeholder="First Name" class="input" required>
                    @error('first_name') <p class="mt-1 text-xs text-danger-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <input type="text" name="last_name" value="{{ old('last_name') }}" placeholder="Last Name" class="input" required>
                    @error('last_name') <p class="mt-1 text-xs text-danger-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <input type="tel" name="phone" value="{{ old('phone') }}" placeholder="Phone Number" class="input" required>
                    @error('phone') <p class="mt-1 text-xs text-danger-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <input type="email" name="email" value="{{ old('email') }}" placeholder="Your Email" class="input" required>
                    @error('email') <p class="mt-1 text-xs text-danger-500">{{ $message }}</p> @enderror
                </div>
            </div>
            <div>
                <textarea name="message" rows="5" placeholder="Your Message" class="input" required>{{ old('message') }}</textarea>
                @error('message') <p class="mt-1 text-xs text-danger-500">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="btn-kivi">
                <span>Send Message</span>
                <span>
                    <svg class="h-2.5 w-2.5" fill="currentColor" viewBox="0 0 8 8"><path d="M7.32046 4.70834H4.74952V7.25698C4.74952 7.66734 4.41395 8 4 8C3.58605 8 3.25048 7.66734 3.25048 7.25698V4.70834H0.679545C0.293423 4.6687 0 4.34614 0 3.96132C0 3.5765 0.293423 3.25394 0.679545 3.21431H3.24242V0.673653C3.28241 0.290878 3.60778 0 3.99597 0C4.38416 0 4.70954 0.290878 4.74952 0.673653V3.21431H7.32046C7.70658 3.25394 8 3.5765 8 3.96132C8 4.34614 7.70658 4.6687 7.32046 4.70834Z"/></svg>
                </span>
            </button>
        </form>
    </section>
</x-layout>
