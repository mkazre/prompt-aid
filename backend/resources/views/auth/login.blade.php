<x-layout title="Sign in — Prompt Aid">
    <section class="mx-auto flex max-w-md flex-col px-4 py-20 sm:px-6">
        <div class="card">
            <h1 class="text-2xl font-bold text-secondary-500">Welcome back</h1>
            <p class="mt-1 text-sm text-gray-500">Sign in to manage your appointments and rides.</p>

            @if ($errors->any())
                <div class="mt-4 rounded-lg bg-danger-500/10 p-3 text-sm text-danger-500">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('login.store') }}" class="mt-6 space-y-4">
                @csrf
                <div>
                    <label class="text-xs font-semibold text-gray-500">Email</label>
                    <input type="email" name="email" value="{{ old('email') }}" required class="input mt-1">
                </div>
                <div>
                    <label class="text-xs font-semibold text-gray-500">Password</label>
                    <input type="password" name="password" required class="input mt-1">
                </div>
                <button class="btn-primary w-full">Sign in</button>
            </form>

            <p class="mt-6 text-center text-sm text-gray-500">
                No account yet? <a href="{{ route('register') }}" class="font-semibold text-primary-600">Create one free</a>
            </p>
            <p class="mt-2 text-center text-xs text-gray-400">Doctor, clinic or driver staff? <a href="/admin" class="text-primary-600">Staff login</a></p>
        </div>
    </section>
</x-layout>
