<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class OrdersController extends Controller
{
    public function index(Request $request)
    {
        $patient = $request->user()->patientProfile;

        $orders = $patient->orders()->with(['pharmacy', 'items'])->latest()->get();

        return view('account.orders', compact('orders'));
    }
}
