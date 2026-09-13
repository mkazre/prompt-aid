<x-layout title="Create your account — Prompt Aid">
    <section class="mx-auto flex max-w-md flex-col px-4 py-20 sm:px-6">
        <div class="card">
            <h1 class="text-2xl font-bold text-secondary-500">Create your free account</h1>
            <p class="mt-1 text-sm text-gray-500">Book doctors and request your patient shuttle in minutes.</p>

            @if ($errors->any())
                <div class="mt-4 rounded-lg bg-danger-500/10 p-3 text-sm text-danger-500">
                    <ul class="list-inside list-disc">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('register.store') }}" class="mt-6 space-y-4">
                @csrf
                <div>
                    <label class="text-xs font-semibold text-gray-500">Full name</label>
                    <input type="text" name="name" value="{{ old('name') }}" required class="input mt-1">
                </div>
                <div>
                    <label class="text-xs font-semibold text-gray-500">Email</label>
                    <input type="email" name="email" value="{{ old('email') }}" required class="input mt-1">
                </div>
                <div>
                    <label class="text-xs font-semibold text-gray-500">Phone</label>
                    <input type="text" name="phone" value="{{ old('phone') }}" class="input mt-1">
                </div>
                <div>
                    <label class="text-xs font-semibold text-gray-500">Password</label>
                    <input type="password" name="password" required class="input mt-1">
                </div>
                <div>
                    <label class="text-xs font-semibold text-gray-500">I am a</label>
                    <select name="role" class="input mt-1">
                        <option value="patient">Patient</option>
                        <option value="driver">Shuttle Driver</option>
                    </select>
                </div>
                <button class="btn-primary w-full">Create account</button>
            </form>

            <p class="mt-6 text-center text-sm text-gray-500">
                Already have an account? <a href="{{ route('login') }}" class="font-semibold text-primary-600">Sign in</a>
            </p>
        </div>
    </section>
</x-layout>
