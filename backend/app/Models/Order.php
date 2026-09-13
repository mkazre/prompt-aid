<?php

namespace App\Models;

use App\Contracts\Payable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model implements Payable
{
    use HasFactory;

    public const STATUS_PENDING_PAYMENT = 'pending_payment';

    public const STATUS_AWAITING_PRESCRIPTION = 'awaiting_prescription_review';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_PREPARING = 'preparing';

    public const STATUS_OUT_FOR_DELIVERY = 'out_for_delivery';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'order_no', 'patient_profile_id', 'pharmacy_id', 'prescription_upload_id',
        'subtotal', 'delivery_fee', 'total', 'commission_amount',
        'delivery_address', 'delivery_lat', 'delivery_lng', 'status', 'payment_status',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $order) {
            $order->order_no ??= 'ORD-'.strtoupper(uniqid());
        });
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(PatientProfile::class, 'patient_profile_id');
    }

    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class);
    }

    public function prescriptionUpload(): BelongsTo
    {
        return $this->belongsTo(PrescriptionUpload::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function requiresPrescriptionReview(): bool
    {
        return $this->items()->whereHas('product', fn ($q) => $q->where('requires_prescription', true))->exists();
    }

    public function getTotal(): float
    {
        return (float) $this->total;
    }

    public function getPayableReference(): string
    {
        return $this->order_no;
    }
}
