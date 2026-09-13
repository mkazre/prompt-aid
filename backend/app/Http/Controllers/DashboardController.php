<?php

namespace App\Http\Controllers;

use App\Models\Ride;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $patient = $request->user()->patientProfile;

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
