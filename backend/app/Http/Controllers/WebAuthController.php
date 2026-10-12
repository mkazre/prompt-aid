<?php

namespace App\Http\Controllers;

use App\Models\DriverProfile;
use App\Models\PatientProfile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class WebAuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'These credentials do not match our records.'])->onlyInput('email');
        }

        $request->session()->regenerate();

        /** @var User $user */
        $user = Auth::user();

        // Staff accounts don't have a patient dashboard — send each role to
        // its own Filament panel instead of crashing on a null
        // patientProfile. This is the website's public login form; staff
        // should normally use their panel's own /login route directly.
        $panelPath = match ($user->role) {
            User::ROLE_SUPER_ADMIN, User::ROLE_CLINIC_ADMIN, User::ROLE_DOCTOR => '/staff',
            User::ROLE_PHARMACY_ADMIN => '/vendor',
            User::ROLE_THIRD_PARTY => '/partner',
            User::ROLE_DRIVER => '/driver',
            default => null,
        };

        if ($panelPath !== null) {
            return redirect($panelPath);
        }

        return redirect()->intended(route('dashboard'));
    }

    public function showRegister(Request $request)
    {
        $referrals = app(\App\Services\Commerce\ReferralService::class);
        $referrals->captureFromRequest($request);

        return view('auth.register', ['referralCode' => $referrals->pendingCode($request)]);
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'unique:users,phone'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in([User::ROLE_PATIENT, User::ROLE_DRIVER])],
            // POPIA consent capture (see ConsentService).
            'accept_terms' => ['accepted'],
            'accept_health_data' => ['accepted'],
            'referral_code' => ['nullable', 'string', 'max:20'],
        ], [
            'accept_terms.accepted' => 'Please accept the terms of use and privacy policy to continue.',
            'accept_health_data.accepted' => 'Please consent to the processing of personal information as set out in the POPIA notice.',
        ]);

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => Hash::make($data['password']),
            'role' => $data['role'],
            'status' => 'active',
        ]);

        if ($user->role === User::ROLE_PATIENT) {
            PatientProfile::query()->create(['user_id' => $user->id]);
        } else {
            DriverProfile::query()->create([
                'user_id' => $user->id,
                'license_no' => 'PENDING-'.$user->id,
                'status' => 'pending_approval',
            ]);
        }

        app(\App\Services\Privacy\ConsentService::class)->recordRegistration(
            $user, \App\Services\Privacy\ConsentService::purposesFromRequest($request), 'web', $request
        );

        app(\App\Services\Commerce\ReferralService::class)->attach(
            $user, $data['referral_code'] ?? $request->session()->get(\App\Services\Commerce\ReferralService::SESSION_KEY), $request
        );

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
