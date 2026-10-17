<?php

namespace App\Services\Dispatch;

use App\Models\User;
use App\Models\UserNotification;
use App\Services\Notifications\ExpoPush;
use App\Services\Notifications\InAppNotifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Incoming-request alerts for providers.
 *
 *  - In-app feed row (user_notifications) so it is in their history.
 *  - High-priority Expo push on the "requests" Android channel with the
 *    request_alert sound and a data payload {type, service, request_id,
 *    offer_id} the app uses to open the IncomingRequestModal.
 *  - Bumps a per-user "inbox version" cache key so the web/app inbox poll
 *    can answer "nothing new" without touching the database.
 *
 * The full-screen modal with the looping sound itself is driven by the
 * inbox poll (GET /dispatch/inbox web, /api/dispatch/inbox app).
 */
class ProviderAlerts
{
    public const PUSH_CHANNEL = 'requests';

    public const PUSH_SOUND = 'request_alert.wav';

    /** @param  array<string, mixed>  $data */
    public static function offer(User|int $user, string $title, string $body, array $data): void
    {
        $id = $user instanceof User ? $user->id : $user;
        self::bump($id);

        try {
            $type = match ($data['service'] ?? '') {
                'shuttle' => 'ride',
                'pharmacy_order' => 'order',
                'script_review' => 'prescription',
                'lab_request' => 'lab',
                default => 'appointment',
            };
            if (InAppNotifier::enabled($type)) {
                UserNotification::query()->create([
                    'user_id' => $id,
                    'type' => $type,
                    'title' => mb_substr($title, 0, 255),
                    'body' => $body,
                    'data' => $data,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('[ProviderAlerts] feed row failed: '.$e->getMessage());
        }

        ExpoPush::toUsers($id, $title, $body, $data + ['type' => 'dispatch_offer'], self::PUSH_CHANNEL, self::PUSH_SOUND);
    }

    /** "Assigned to you by operations" (manual mode) — same channel, one-off. */
    public static function assigned(User|int $user, string $title, string $body, array $data): void
    {
        self::offer($user, $title, $body, $data + ['assigned' => true]);
    }

    /** Any change that affects what a provider's inbox shows (taken, expired...). */
    public static function bump(int $userId): void
    {
        try {
            Cache::put('dispatch:inbox:v:'.$userId, (string) microtime(true), 3600);
        } catch (\Throwable) {
        }
    }

    public static function version(int $userId): string
    {
        try {
            return (string) Cache::get('dispatch:inbox:v:'.$userId, '0');
        } catch (\Throwable) {
            return '0';
        }
    }
}
