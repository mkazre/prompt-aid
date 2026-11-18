<?php

namespace App\Http\Controllers;

use App\Models\PracticeInvoice;
use App\Services\Billing\PracticeBilling;
use Illuminate\Http\Request;

/** Printable receipt for a practice subscription payment (practice billing contact or super admin). */
class PracticeBillingReceiptController extends Controller
{
    public function __invoke(Request $request, PracticeInvoice $invoice)
    {
        $user = $request->user();
        $sub = $invoice->subscription;
        abort_unless($sub, 404);
        $admin = $user?->isSuperAdmin() && $user->hasPermission('practice-subscriptions.view');
        abort_unless($admin || PracticeBilling::canManage($user, $sub), 403);

        return response()->view('practice-billing.receipt', [
            'inv' => $invoice,
            'sub' => $sub,
            'practice' => $sub->subscriberLabel(),
        ])->header('Cache-Control', 'private, no-store');
    }
}
