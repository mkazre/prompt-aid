<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Services\Commerce\ReferralService;
use Illuminate\Http\Request;

/** Patient "Invite friends" page: their code, share link and how many friends joined. */
class ReferralsController extends Controller
{
    public function index(Request $request, ReferralService $referrals)
    {
        abort_unless($request->user()->patientProfile, 403);

        return view('account.referrals', ['summary' => $referrals->summary($request->user())]);
    }
}
