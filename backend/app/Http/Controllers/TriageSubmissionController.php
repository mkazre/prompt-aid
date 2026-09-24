<?php

namespace App\Http\Controllers;

use App\Models\TriageSubmission;
use App\Models\User;
use App\Support\StaffNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Records the outcome of the client-side pre-triage tool (assets/js/triage.js
 * — see its own header comment) so staff can see who assessed themselves as
 * Red/Orange, even though the tool itself only ever tells the patient to
 * call 10177 rather than dispatching anything. Fire-and-forget from the
 * patient's side: the JS never waits on this before showing the verdict.
 */
class TriageSubmissionController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'level' => ['required', 'in:red,orange,yellow,green'],
            'age_band' => ['nullable', 'string', 'max:50'],
            'pregnant' => ['nullable', 'boolean'],
            'symptoms' => ['nullable', 'array'],
            'discriminators' => ['nullable', 'array'],
            'observations' => ['nullable', 'array'],
            'reasons' => ['nullable', 'array'],
            'facility_types' => ['nullable', 'array'],
            'pickup_lat' => ['nullable', 'numeric'],
            'pickup_lng' => ['nullable', 'numeric'],
            'pickup_address' => ['nullable', 'string', 'max:255'],
        ]);

        $submission = TriageSubmission::query()->create([
            ...$data,
            'reference' => 'SATS-'.strtoupper(Str::random(6)),
            'user_id' => $request->user()?->id,
            'ip' => $request->ip(),
        ]);

        if (in_array($data['level'], [TriageSubmission::LEVEL_RED, TriageSubmission::LEVEL_ORANGE], true)) {
            $admins = User::query()->where('role', User::ROLE_SUPER_ADMIN)->where('status', 'active')->get();

            if ($admins->isNotEmpty()) {
                StaffNotifier::alert(
                    $admins,
                    strtoupper($data['level'])." pre-triage result — {$submission->reference}",
                    'A patient self-assessed as '.$data['level'].'. This tool always tells them to call 10177/112 directly — no dispatch is triggered automatically.',
                    icon: 'heroicon-o-exclamation-triangle',
                    color: $data['level'] === 'red' ? 'danger' : 'warning',
                );
                $submission->update(['staff_notified' => true]);
            }
        }

        return response()->json(['reference' => $submission->reference]);
    }
}
