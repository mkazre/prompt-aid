<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Platform commission / patient fee for one service type at one scope.
 * Field-level inheritance: a null percent / fixed_amount / patient_fee on an
 * override falls back to the next less specific rule (see CommissionResolver).
 */
class CommissionRule extends Model
{
    public const SERVICES = [
        'consult' => 'Doctor consults (video, voice, chat, call-back, instant)',
        'appointment' => 'In-person appointments',
        'shuttle' => 'Shuttle rides',
        'pharmacy' => 'Pharmacy orders',
        'lab' => 'Labs / x-ray / third-party bookings',
    ];

    public const SCOPES = [
        'global' => 'Global default',
        'clinic' => 'Clinic',
        'doctor' => 'Doctor',
        'pharmacy' => 'Pharmacy',
        'partner' => 'Partner (lab / allied)',
        'driver' => 'Driver',
    ];

    /** Which override scopes make sense for each service type. */
    public const SCOPES_FOR = [
        'consult' => ['doctor', 'clinic'],
        'appointment' => ['doctor', 'clinic'],
        'shuttle' => ['driver'],
        'pharmacy' => ['pharmacy'],
        'lab' => ['partner'],
    ];

    protected $fillable = [
        'service_type', 'scope', 'scope_id', 'percent', 'fixed_amount', 'patient_fee',
        'active', 'effective_from', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'effective_from' => 'datetime',
            'scope_id' => 'integer',
        ];
    }

    public function scopeLabel(): string
    {
        if ($this->scope === 'global') {
            return 'Global default';
        }

        return (self::SCOPES[$this->scope] ?? $this->scope).': '.\App\Services\Finance\Accounts::label($this->scope, (int) $this->scope_id);
    }
}
