<?php

namespace App\Contracts;

use App\Models\User;

interface NotificationDispatcherInterface
{
    /**
     * Send (or simulate sending) an SMS to the user and log it.
     */
    public function sms(User $user, string $body): bool;

    /**
     * Send (or simulate sending) an email to the user and log it.
     */
    public function email(User $user, string $subject, string $body): bool;

    /**
     * Send (or simulate sending) a push notification to the user and log it.
     */
    public function push(User $user, string $subject, string $body): bool;
}
