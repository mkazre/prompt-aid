<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Prompt Aid — Clinics, Doctors & Patient Shuttle' }}</title>
    <link rel="icon" href="{{ asset('images/favicon.ico') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-gray-50 font-sans">
    <header class="sticky top-0 z-40 border-b border-gray-100 bg-white/90 backdrop-blur">
        <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-3 sm:px-6 lg:px-8">
            <a href="{{ url('/') }}" class="flex items-center gap-2">
                <img src="{{ asset('images/logo.png') }}" alt="Prompt Aid" class="h-8 w-auto">
            </a>
            <nav class="hidden items-center gap-8 text-sm font-medium text-gray-600 md:flex">
                <a href="{{ route('doctors.index') }}" class="hover:text-primary-600">Find a Doctor</a>
                <a href="{{ route('clinics.index') }}" class="hover:text-primary-600">Clinics</a>
                <a href="{{ route('pharmacies.index') }}" class="hover:text-primary-600">Pharmacy</a>
                <a href="{{ url('/#how-it-works') }}" class="hover:text-primary-600">How it Works</a>
                <a href="{{ url('/#ride') }}" class="hover:text-primary-600">Patient Shuttle</a>
            </nav>
            <div class="flex items-center gap-3">
                @auth
                    <a href="{{ route('dashboard') }}" class="btn-outline !px-4 !py-2 text-xs">My Dashboard</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="text-xs font-semibold text-gray-500 hover:text-danger-500">Sign out</button>
                    </form>
                @else
                    <a href="{{ route('login') }}" class="text-sm font-semibold text-gray-600 hover:text-primary-600">Sign in</a>
                    <a href="{{ route('register') }}" class="btn-primary !px-5 !py-2.5 text-xs">Book an Appointment</a>
                @endauth
            </div>
        </div>
    </header>

    <main>
        {{ $slot }}
    </main>

    <footer class="mt-24 border-t border-gray-100 bg-secondary-500 text-secondary-50">
        <div class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
            <div class="grid gap-8 md:grid-cols-4">
                <div>
                    <img src="{{ asset('images/logo.png') }}" alt="Prompt Aid" class="h-8 w-auto brightness-0 invert">
                    <p class="mt-3 text-sm text-gray-300">Book doctors, manage your care, and get a free shuttle to and from your appointment — all in one place.</p>
                </div>
                <div>
                    <h4 class="text-sm font-semibold text-white">Patients</h4>
                    <ul class="mt-3 space-y-2 text-sm text-gray-300">
                        <li><a href="{{ route('doctors.index') }}" class="hover:text-white">Find a Doctor</a></li>
                        <li><a href="{{ route('clinics.index') }}" class="hover:text-white">Our Clinics</a></li>
                        <li><a href="{{ route('register') }}" class="hover:text-white">Create an account</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="text-sm font-semibold text-white">Company</h4>
                    <ul class="mt-3 space-y-2 text-sm text-gray-300">
                        <li><a href="/admin" class="hover:text-white">Staff / Admin Login</a></li>
                        <li><a href="#" class="hover:text-white">Careers</a></li>
                        <li><a href="#" class="hover:text-white">Contact</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="text-sm font-semibold text-white">Contact</h4>
                    <p class="mt-3 text-sm text-gray-300">{{ \App\Models\Setting::get('support_phone') }}<br>{{ \App\Models\Setting::get('support_email') }}</p>
                </div>
            </div>
            <p class="mt-10 border-t border-white/10 pt-6 text-xs text-gray-400">&copy; {{ date('Y') }} Prompt Aid. All rights reserved.</p>
        </div>
    </footer>
</body>
</html>
