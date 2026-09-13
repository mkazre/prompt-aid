<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RideStatusEvent extends Model
{
    use HasFactory;

    protected $fillable = ['ride_id', 'status', 'lat', 'lng'];

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }
}
