<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Prompt Aid — Clinics, Doctors & Patient Shuttle' }}</title>
    <link rel="icon" href="{{ asset('images/favicon.ico') }}">
    @stack('meta')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-gray-50 font-sans">
    <div class="hidden border-b border-gray-100 bg-gray-50 md:block">
        <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-2 text-xs text-gray-500 sm:px-6 lg:px-8">
            <div class="flex items-center gap-5">
                <a href="tel:{{ \App\Models\Setting::get('support_phone') }}" class="flex items-center gap-1.5 hover:text-primary-600">
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 6.75c0 8.284 6.716 15 15 15h1.5a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106a1.125 1.125 0 00-1.173.417l-.97 1.293a11.25 11.25 0 01-5.223-5.223l1.293-.97a1.125 1.125 0 00.417-1.173L9.213 3.102a1.125 1.125 0 00-1.091-.852H6.75A2.25 2.25 0 004.5 4.5v2.25z"/></svg>
                    {{ \App\Models\Setting::get('support_phone') }}
                </a>
                <a href="mailto:{{ \App\Models\Setting::get('support_email') }}" class="flex items-center gap-1.5 hover:text-primary-600">
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/></svg>
                    {{ \App\Models\Setting::get('support_email') }}
                </a>
            </div>
            <div class="flex items-center gap-3">
                <span class="font-medium text-gray-400">Follow us:</span>
                <a href="#" aria-label="Facebook" class="flex h-6 w-6 items-center justify-center rounded-full border border-gray-200 hover:border-primary-400 hover:text-primary-600">f</a>
                <a href="#" aria-label="X" class="flex h-6 w-6 items-center justify-center rounded-full border border-gray-200 hover:border-primary-400 hover:text-primary-600">x</a>
                <a href="#" aria-label="Instagram" class="flex h-6 w-6 items-center justify-center rounded-full border border-gray-200 hover:border-primary-400 hover:text-primary-600">in</a>
            </div>
        </div>
    </div>
    <header class="sticky top-0 z-40 border-b border-gray-100 bg-white/90 backdrop-blur">
        <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-3 sm:px-6 lg:px-8">
            <a href="{{ url('/') }}" class="flex items-center gap-2">
                <img src="{{ asset('images/logo.png') }}" alt="Prompt Aid" class="h-8 w-auto">
            </a>
            <nav class="hidden items-center gap-8 text-sm font-medium text-gray-600 lg:flex">
                <a href="{{ route('doctors.index') }}" class="hover:text-primary-600">Find a Doctor</a>
                <a href="{{ route('clinics.index') }}" class="hover:text-primary-600">Clinics</a>
                <a href="{{ route('pharmacies.index') }}" class="hover:text-primary-600">Pharmacy</a>
                <a href="{{ url('/#how-it-works') }}" class="hover:text-primary-600">How it Works</a>
                <a href="{{ url('/#ride') }}" class="hover:text-primary-600">Patient Shuttle</a>
                <a href="{{ route('contact') }}" class="hover:text-primary-600">Contact</a>
            </nav>
            <div class="flex items-center gap-3">
                @auth
                    <a href="{{ route('dashboard') }}" class="btn-outline !px-4 !py-2 text-xs">My Dashboard</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="text-xs font-semibold text-gray-500 hover:text-danger-500">Sign out</button>
                    </form>
                @else
                    <a href="{{ route('login') }}" class="hidden text-sm font-semibold text-gray-600 hover:text-primary-600 sm:block">Sign in</a>
                    <a href="{{ route('register') }}" class="btn-primary !px-5 !py-2.5 text-xs">Book an Appointment</a>
                @endauth
            </div>
        </div>
    </header>

    <main>
        {{ $slot }}
    </main>

    <footer class="mt-24 border-t border-gray-100 bg-secondary-500 text-secondary-50">
        <div class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
            <div class="grid gap-10 md:grid-cols-4">
                <div>
                    <img src="{{ asset('images/logo.png') }}" alt="Prompt Aid" class="h-8 w-auto brightness-0 invert">
                    <p class="mt-4 text-sm leading-relaxed text-gray-300">Book doctors, manage your care, order medication and get a free shuttle to and from your appointment — all in one place.</p>
                    <div class="mt-5 flex items-center gap-3">
                        <a href="#" aria-label="Facebook" class="flex h-8 w-8 items-center justify-center rounded-full border border-white/20 text-xs hover:border-primary-400 hover:text-primary-300">f</a>
                        <a href="#" aria-label="X" class="flex h-8 w-8 items-center justify-center rounded-full border border-white/20 text-xs hover:border-primary-400 hover:text-primary-300">x</a>
                        <a href="#" aria-label="Instagram" class="flex h-8 w-8 items-center justify-center rounded-full border border-white/20 text-xs hover:border-primary-400 hover:text-primary-300">in</a>
                    </div>
                </div>
                <div>
                    <h4 class="text-sm font-semibold text-white">Useful Links</h4>
                    <ul class="mt-4 space-y-2.5 text-sm text-gray-300">
                        <li><a href="{{ route('doctors.index') }}" class="hover:text-white">Find a Doctor</a></li>
                        <li><a href="{{ route('clinics.index') }}" class="hover:text-white">Our Clinics</a></li>
                        <li><a href="{{ route('pharmacies.index') }}" class="hover:text-white">Pharmacy</a></li>
                        <li><a href="/how-it-works" class="hover:text-white">How It Works</a></li>
                        <li><a href="/about" class="hover:text-white">About Us</a></li>
                        <li><a href="/for-providers" class="hover:text-white">For Providers</a></li>
                        <li><a href="{{ route('register') }}" class="hover:text-white">Create an account</a></li>
                        <li><a href="{{ route('contact') }}" class="hover:text-white">Contact Us</a></li>
                        <li><a href="{{ url('/staff/login') }}" class="hover:text-white">Staff / Admin Login</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="text-sm font-semibold text-white">Working Hours</h4>
                    <ul class="mt-4 space-y-2.5 text-sm">
                        <li class="flex items-center justify-between border-b border-white/10 pb-2.5 text-gray-300">
                            <span>Monday – Friday</span><span class="font-medium text-white">08:00 – 18:00</span>
                        </li>
                        <li class="flex items-center justify-between border-b border-white/10 pb-2.5 text-gray-300">
                            <span>Saturday</span><span class="font-medium text-white">08:00 – 13:00</span>
                        </li>
                        <li class="flex items-center justify-between text-gray-300">
                            <span>Sunday</span><span class="font-medium text-white">Closed</span>
                        </li>
                    </ul>
                </div>
                <div>
                    <h4 class="text-sm font-semibold text-white">Reach Us</h4>
                    <ul class="mt-4 space-y-3 text-sm text-gray-300">
                        <li>
                            <a href="tel:{{ \App\Models\Setting::get('support_phone') }}" class="flex items-center gap-2 hover:text-white">
                                <svg class="h-4 w-4 shrink-0 text-primary-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 6.75c0 8.284 6.716 15 15 15h1.5a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106a1.125 1.125 0 00-1.173.417l-.97 1.293a11.25 11.25 0 01-5.223-5.223l1.293-.97a1.125 1.125 0 00.417-1.173L9.213 3.102a1.125 1.125 0 00-1.091-.852H6.75A2.25 2.25 0 004.5 4.5v2.25z"/></svg>
                                {{ \App\Models\Setting::get('support_phone') }}
                            </a>
                        </li>
                        <li>
                            <a href="mailto:{{ \App\Models\Setting::get('support_email') }}" class="flex items-center gap-2 hover:text-white">
                                <svg class="h-4 w-4 shrink-0 text-primary-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/></svg>
                                {{ \App\Models\Setting::get('support_email') }}
                            </a>
                        </li>
                        <li class="flex items-start gap-2">
                            <svg class="mt-0.5 h-4 w-4 shrink-0 text-primary-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg>
                            <span>South Africa</span>
                        </li>
                    </ul>
                </div>
            </div>
            <div class="mt-12 flex flex-wrap items-center justify-between gap-3 border-t border-white/10 pt-6 text-xs text-gray-400">
                <p>&copy; {{ date('Y') }} Prompt Aid. All rights reserved.</p>
                <div class="flex gap-4">
                    <a href="/privacy" class="hover:text-white">Privacy Policy</a>
                    <a href="/terms" class="hover:text-white">Terms of Service</a>
                </div>
            </div>
        </div>
    </footer>
</body>
</html>
