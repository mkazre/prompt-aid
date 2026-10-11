<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One continuous doctor–patient thread (unique per patient+doctor pair).
 * Access rules live in App\Policies\ConversationPolicy.
 */
class Conversation extends Model
{
    protected $fillable = ['patient_profile_id', 'doctor_profile_id', 'appointment_id', 'last_message_at'];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(PatientProfile::class, 'patient_profile_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(DoctorProfile::class, 'doctor_profile_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    /**
     * Conversations the given user takes part in (as the patient or the
     * doctor). Everyone else — including admins — gets nothing here; admin
     * read-only access goes through the Filament resource instead.
     */
    public function scopeForParticipant(Builder $query, User $user): Builder
    {
        if ($user->isPatient() && $user->patientProfile) {
            return $query->where('patient_profile_id', $user->patientProfile->id);
        }

        if ($user->isDoctor() && $user->doctorProfile) {
            return $query->where('doctor_profile_id', $user->doctorProfile->id);
        }

        return $query->whereRaw('1 = 0');
    }

    public function patientUserId(): ?int
    {
        return $this->patient?->user_id;
    }

    public function doctorUserId(): ?int
    {
        return $this->doctor?->user_id;
    }

    public function isParticipant(User $user): bool
    {
        return in_array($user->id, array_filter([$this->patientUserId(), $this->doctorUserId()]), true);
    }

    /** The user on the other side of the thread from $user. */
    public function counterpartFor(User $user): ?User
    {
        return $user->id === $this->doctorUserId() ? $this->patient?->user : $this->doctor?->user;
    }

    /**
     * A patient may only chat with a doctor they have (or had) an
     * appointment with — any appointment that actually has this doctor
     * assigned (on-demand requests still in `matching` have none).
     */
    public static function relationshipExists(int $patientProfileId, int $doctorProfileId): bool
    {
        return Appointment::query()
            ->where('patient_profile_id', $patientProfileId)
            ->where('doctor_profile_id', $doctorProfileId)
            ->where('status', '!=', Appointment::STATUS_MATCHING)
            ->exists();
    }

    public function unreadCountFor(User $user): int
    {
        return $this->messages()
            ->whereNull('read_at')
            ->where('sender_user_id', '!=', $user->id)
            ->count();
    }
}
