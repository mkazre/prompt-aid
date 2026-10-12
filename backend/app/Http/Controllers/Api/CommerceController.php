<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\InvoicePrintController;
use App\Services\Commerce\DoctorDelayService;
use App\Services\Commerce\ReferralService;
use App\Support\CommerceSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/** App endpoints for referrals, invoice print links and the doctor "running late" alert. */
class CommerceController extends Controller
{
    /** GET /api/referrals — the signed-in user's code, share text and friends joined. */
    public function referrals(Request $request, ReferralService $referrals): JsonResponse
    {
        return response()->json(['data' => $referrals->summary($request->user())]);
    }

    /** GET /api/invoices/{id}/print-link — a short-lived signed URL to the printable invoice. */
    public function invoicePrintLink(Request $request, int $id): JsonResponse
    {
        $invoice = $request->user()->patientProfile?->invoices()->findOrFail($id);
        abort_unless($invoice, 404);

        return response()->json([
            'url' => InvoicePrintController::signedUrlFor($invoice),
            'expires_in_minutes' => InvoicePrintController::SIGNED_MINUTES,
        ]);
    }

    /** GET /api/doctor/running-late — what the modal needs (enabled, options, patients left today). */
    public function runningLateInfo(Request $request, DoctorDelayService $delays): JsonResponse
    {
        $doctor = $request->user()->doctorProfile;
        abort_unless($doctor, 403, 'No doctor profile on this account.');

        return response()->json([
            'enabled' => CommerceSettings::bool('running_late_enabled'),
            'options' => DoctorDelayService::MINUTE_OPTIONS,
            'remaining_patients' => $delays->remainingToday($doctor)->count(),
        ]);
    }

    /** POST /api/doctor/running-late {minutes, message?} */
    public function runningLate(Request $request, DoctorDelayService $delays): JsonResponse
    {
        $doctor = $request->user()->doctorProfile;
        abort_unless($doctor, 403, 'No doctor profile on this account.');

        $data = $request->validate([
            'minutes' => ['required', 'integer', 'min:5', 'max:240'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $notice = $delays->notify($doctor, (int) $data['minutes'], $data['message'] ?? null, $request->user(), 'app');
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => "{$notice->patients_notified} ".($notice->patients_notified === 1 ? 'patient was' : 'patients were')." told you're running {$notice->minutes} minutes late.",
            'patients_notified' => $notice->patients_notified,
            'id' => $notice->id,
        ], 201);
    }
}
