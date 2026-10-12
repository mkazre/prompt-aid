<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentExpiryNotice extends Model
{
    protected $fillable = ['document_type', 'document_id', 'expires_on', 'threshold_days', 'sent_at'];

    protected function casts(): array
    {
        return [
            'expires_on' => 'date',
            'sent_at' => 'datetime',
        ];
    }
}
