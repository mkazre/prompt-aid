<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A practice (clinic) reception team member — see App\Services\PracticeCalls\PracticeTeam. */
class PracticeStaff extends Model
{
    protected $table = 'practice_staff';

    protected $fillable = ['clinic_id', 'user_id', 'role', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
