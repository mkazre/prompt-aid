<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabRequestItem extends Model
{
    use HasFactory;

    protected $fillable = ['lab_request_id', 'test_name', 'sample_type', 'notes'];

    public function labRequest(): BelongsTo
    {
        return $this->belongsTo(LabRequest::class);
    }
}
