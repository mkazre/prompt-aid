<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class RidesController extends Controller
{
    public function index(Request $request)
    {
        $patient = $request->user()->patientProfile;

        $rides = $patient->rides()->with('driver.user')->latest('requested_at')->get();

        return view('account.rides', compact('rides'));
    }
}
