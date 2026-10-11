<?php

namespace App\Contracts;

use App\Models\User;

/**
 * Every method that targets a registered User also writes an in-app
 * notification (user_notifications) for that user. The optional `$context`
 * says which feed type it is and where tapping it should deep-link:
 * ['type' => 'appointment', 'data' => ['route' => 'Appointments', 'params' => [...], 'url' => '/account/appointments']].
 */
interface NotificationDispatcherInterface
{
    /**
     * Send (or simulate sending) an SMS to the user and log it.
     */
    public function sms(User $user, string $body, array $context = []): bool;

    /**
     * Send (or simulate sending) an SMS to a phone number that isn't a
     * registered user — e.g. an emergency contact given during triage.
     */
    public function smsToPhone(string $phone, string $body, ?string $label = null): bool;

    /**
     * Send (or simulate sending) an email to the user and log it.
     */
    public function email(User $user, string $subject, string $body, array $context = []): bool;

    /**
     * Send (or simulate sending) a push notification to the user and log it.
     */
    public function push(User $user, string $subject, string $body, array $context = []): bool;

    /**
     * Send (or simulate sending) a WhatsApp message to a phone number
     * (e.g. the call centre) that isn't necessarily a registered user.
     */
    public function whatsApp(string $toPhone, string $body): bool;
}
