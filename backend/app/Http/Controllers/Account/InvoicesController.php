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

        return view('account.invoices', compact('invoices'));
    }
}
