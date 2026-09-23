<?php

namespace App\Models;

use App\Contracts\Payable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Invoice extends Model implements Payable
{
    use HasFactory;

    public const STATUS_UNPAID = 'unpaid';

    public const STATUS_PAID = 'paid';

    public const STATUS_PARTIALLY_PAID = 'partially_paid';

    public const STATUS_REFUNDED = 'refunded';

    protected $fillable = [
        'invoice_no', 'appointment_id', 'patient_profile_id', 'clinic_id',
        'subtotal', 'tax', 'discount', 'total', 'status', 'due_date',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $invoice) {
            $invoice->invoice_no ??= 'INV-'.strtoupper(uniqid());
        });
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(PatientProfile::class, 'patient_profile_id');
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function claim(): MorphOne
    {
        return $this->morphOne(Claim::class, 'claimable');
    }

    public function getTotal(): float
    {
        return (float) $this->total;
    }

    public function getPayableReference(): string
    {
        return $this->invoice_no;
    }
}
