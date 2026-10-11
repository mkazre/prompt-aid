<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

/**
 * Doctor–patient chat access rules (auto-discovered for App\Models\Conversation):
 *  - the thread's patient and doctor can read, post and mark read;
 *  - super admins can read everything (Filament, read-only) but never post;
 *  - nobody else — clinic admins, other doctors, drivers, vendors — sees a thing.
 * Threads can only be created where Conversation::relationshipExists() holds
 * (enforced in ChatService), so "participant" already implies the patient
 * has, or had, an appointment with that doctor.
 */
class ConversationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->status === 'active';
    }

    public function view(User $user, Conversation $conversation): bool
    {
        if ($user->status !== 'active') {
            return false;
        }

        return $conversation->isParticipant($user) || $user->isSuperAdmin();
    }

    /** Posting a message (also covers marking the thread read). */
    public function participate(User $user, Conversation $conversation): bool
    {
        return $user->status === 'active' && $conversation->isParticipant($user);
    }

    public function create(User $user): bool
    {
        return $user->status === 'active' && ($user->isPatient() || $user->isDoctor());
    }

    public function update(User $user, Conversation $conversation): bool
    {
        return false;
    }

    public function delete(User $user, Conversation $conversation): bool
    {
        return false;
    }
}
