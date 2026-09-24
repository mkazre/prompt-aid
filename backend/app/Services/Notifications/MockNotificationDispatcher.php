<?php

namespace App\Services\Notifications;

use App\Contracts\NotificationDispatcherInterface;
use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Logs every SMS/email/push to notifications_log and to the application log
 * instead of calling a real provider. Swap the binding in
 * AppServiceProvider for Twilio/Vonage (SMS), a real Mailer (email), or
 * FCM/Expo push (mobile) later — callers never change.
 */
class MockNotificationDispatcher implements NotificationDispatcherInterface
{
    public function sms(User $user, string $body): bool
    {
        return $this->log($user, 'sms', null, $body);
    }

    public function smsToPhone(string $phone, string $body, ?string $label = null): bool
    {
        NotificationLog::query()->create([
            'user_id' => null,
            'channel' => 'sms',
            'subject' => $label ? "To {$label} ({$phone})" : "To {$phone}",
            'body' => $body,
            'status' => 'sent',
        ]);

        Log::info("[MockNotification][sms] to {$phone}".($label ? " ({$label})" : '').": {$body}");

        return true;
    }

    public function email(User $user, string $subject, string $body): bool
    {
        return $this->log($user, 'email', $subject, $body);
    }

    public function push(User $user, string $subject, string $body): bool
    {
        return $this->log($user, 'push', $subject, $body);
    }

    protected function log(User $user, string $channel, ?string $subject, string $body): bool
    {
        NotificationLog::query()->create([
            'user_id' => $user->id,
            'channel' => $channel,
            'subject' => $subject,
            'body' => $body,
            'status' => 'sent',
        ]);

        Log::info("[MockNotification][{$channel}] to {$user->email}: ".($subject ? "{$subject} — " : '').$body);

        return true;
    }
}
