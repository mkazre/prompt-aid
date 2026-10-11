<?php

namespace App\Models;

use App\Contracts\Payable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ride ledger line: a trip fare, cancellation fee or no-show fee, charged
 * through PaymentGatewayInterface (mock today) and waivable/refundable by
 * an admin from the ride view.
 */
class RideCharge extends Model implements Payable
{
    use \App\Concerns\Auditable;

    public const TYPE_FARE = 'fare';

    public const TYPE_CANCELLATION = 'cancellation_fee';

    public const TYPE_NO_SHOW = 'no_show_fee';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_WAIVED = 'waived';

    public const STATUS_REFUNDED = 'refunded';

    protected $fillable = [
        'ride_id', 'patient_profile_id', 'type', 'amount', 'status', 'method', 'gateway',
        'gateway_ref', 'note', 'actioned_by_user_id', 'refunded_at',
    ];

    protected function casts(): array
    {
        return ['refunded_at' => 'datetime', 'amount' => 'float'];
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(PatientProfile::class, 'patient_profile_id');
    }

    public function getTotal(): float
    {
        return (float) $this->amount;
    }

    public function getPayableReference(): string
    {
        return ($this->ride?->ride_ref ?? 'RIDE').'-'.strtoupper(str_replace('_', '', $this->type)).'-'.$this->id;
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_FARE => 'Trip fare',
            self::TYPE_CANCELLATION => 'Cancellation fee',
            self::TYPE_NO_SHOW => 'No-show fee',
            default => ucfirst(str_replace('_', ' ', $this->type)),
        };
    }
}
