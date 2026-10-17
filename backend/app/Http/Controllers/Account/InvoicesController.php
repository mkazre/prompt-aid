<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class InvoicesController extends Controller
{
    public function index(Request $request)
    {
        $patient = $request->user()->patientProfile;

        $invoices = $patient->invoices()->with(['clinic', 'claim'])->latest()->get();

        // Shuttle fares / cancellation / no-show fees still owed (payable from the payment sheet).
        $rideCharges = \App\Models\RideCharge::query()->with('ride')
            ->where('patient_profile_id', $patient->id)
            ->whereIn('status', [\App\Models\RideCharge::STATUS_PENDING, \App\Models\RideCharge::STATUS_FAILED])
            ->where('amount', '>', 0)->latest()->get();

        return view('account.invoices', compact('invoices', 'rideCharges'));
    }
}
