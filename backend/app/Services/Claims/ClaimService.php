<?php

namespace App\Services\Claims;

use App\Models\Claim;
use App\Models\Invoice;
use App\Models\SchemeMembership;
use Illuminate\Database\Eloquent\Model;

/**
 * Tier-1 claims (no switching partner yet — see HANDOVER.md "Medical aid"):
 * capture the scheme + member number against an invoice, produce a
 * compliant claim document (ICD-10, tariff codes, practice number), and
 * track status manually as the clinic/patient hears back from the scheme.
 * `Claim::claimable` is polymorphic so this same flow will cover pharmacy
 * orders later without a schema change.
 */
class ClaimService
{
    public function createFromInvoice(Invoice $invoice, SchemeMembership $membership): Claim
    {
        return Claim::query()->firstOrCreate(
            ['claimable_type' => Invoice::class, 'claimable_id' => $invoice->id],
            [
                'scheme_membership_id' => $membership->id,
                'status' => Claim::STATUS_DRAFT,
                'amount_claimed' => $invoice->total,
            ],
        );
    }

    public function submit(Claim $claim, ?string $schemeRef = null): Claim
    {
        $claim->update([
            'status' => Claim::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'scheme_ref' => $schemeRef ?? $claim->scheme_ref,
        ]);

        return $claim->fresh();
    }

    public function updateOutcome(Claim $claim, string $status, ?float $amountPaid = null, ?string $rejectionReason = null): Claim
    {
        $claim->update([
            'status' => $status,
            'amount_paid' => $amountPaid ?? $claim->amount_paid,
            'rejection_reason' => $status === Claim::STATUS_REJECTED ? $rejectionReason : null,
        ]);

        return $claim->fresh();
    }

    /**
     * Everything the claim PDF/print view needs, gathered in one place so
     * the web print view and any future API/admin export stay in sync.
     */
    public function documentData(Claim $claim): array
    {
        $claim->loadMissing(['membership.patient.user', 'membership.scheme', 'claimable']);

        /** @var Model&Invoice $invoice */
        $invoice = $claim->claimable;
        $invoice->loadMissing(['items', 'appointment.doctor.user']);

        return [
            'claim' => $claim,
            'membership' => $claim->membership,
            'invoice' => $invoice,
            'doctor' => $invoice->appointment?->doctor,
        ];
    }
}
