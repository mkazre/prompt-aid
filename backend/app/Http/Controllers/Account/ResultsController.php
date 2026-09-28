<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ResultsController extends Controller
{
    public function index(Request $request)
    {
        $patient = $request->user()->patientProfile;

        $labRequests = $patient->labRequests()
            ->with(['doctor.user', 'thirdParty', 'items', 'results'])
            ->latest()
            ->get();

        return view('account.results', compact('labRequests'));
    }
}
