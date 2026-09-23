<?php

namespace App\Http\Controllers;

use App\Models\Ride;
use App\Models\User;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        $patient = $user->patientProfile;

        // Defense in depth: staff/vendor/partner accounts have no patient
        // profile. WebAuthController already redirects them to their own
        // panel on login, but guard here too in case this route is hit any
        // other way, so it degrades to a redirect instead of a crash.
        if (! $patient) {
            $panelPath = match ($user->role) {
                User::ROLE_SUPER_ADMIN, User::ROLE_CLINIC_ADMIN, User::ROLE_DOCTOR => '/staff',
                User::ROLE_PHARMACY_ADMIN => '/vendor',
                User::ROLE_THIRD_PARTY => '/partner',
                default => '/',
            };

            return redirect($panelPath);
        }

        return view('dashboard.index', [
            'appointments' => $patient->appointments()->with(['doctor.user', 'clinic'])->orderByDesc('date')->limit(5)->get(),
            'invoices' => $patient->invoices()->latest()->limit(5)->get(),
            'rides' => $patient->rides()->latest('requested_at')->limit(5)->get(),
            'activeRide' => $patient->rides()->with('driver.user')->whereNotIn('status', [Ride::STATUS_COMPLETED, Ride::STATUS_CANCELLED])->latest('requested_at')->first(),
            'labRequests' => $patient->labRequests()->with(['doctor.user', 'thirdParty', 'items', 'results'])->latest()->limit(5)->get(),
            'orders' => $patient->orders()->with('pharmacy')->latest()->limit(5)->get(),
        ]);
    }
}
