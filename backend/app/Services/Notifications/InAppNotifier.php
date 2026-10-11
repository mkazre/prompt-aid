<?php

namespace App\Services\Notifications;

use App\Models\Setting;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Writes rows to `user_notifications` — the feed behind the mobile
 * Notifications screen and the web header bell. Every per-user SMS/email/
 * push that goes through the NotificationDispatcherInterface also lands
 * here (see MockNotificationDispatcher), plus a few events that only ever
 * alerted staff via Filament before (new booking → doctor, etc.).
 *
 * Each event type can be switched off platform-wide from Theme settings →
 * Notifications (Setting key `inapp_notify_{type}`, default on).
 */
class InAppNotifier
{
    /** @var array<string, string> type => admin label */
    public const TYPES = [
        'appointment' => 'Appointments (booked, confirmed, cancelled, matched)',
        'chat_message' => 'New chat messages',
        'video' => 'Video consult reminders / links',
        'ride' => 'Shuttle rides (driver assigned, arrived, completed)',
        'order' => 'Pharmacy orders (placed, status changes, delivered)',
        'prescription' => 'Prescription reviews (approved / rejected)',
        'lab' => 'Lab requests & results',
        'triage' => 'Triage alerts (staff)',
        'account' => 'Account & provider verification',
        'broadcast' => 'Admin broadcasts',
        'general' => 'Other notifications',
    ];

    public static function enabled(string $type): bool
    {
        $key = array_key_exists($type, self::TYPES) ? $type : 'general';

        return (bool) Setting::get("inapp_notify_{$key}", true);
    }

    /**
     * @param  array{route?: string, params?: array, url?: string}  $data  deep-link payload
     */
    public function send(User|int|null $user, string $type, string $title, ?string $body = null, array $data = []): ?UserNotification
    {
        $userId = $user instanceof User ? $user->id : $user;

        if (! $userId || ! self::enabled($type)) {
            return null;
        }

        try {
            return UserNotification::query()->create([
                'user_id' => $userId,
                'type' => $type,
                'title' => mb_substr($title, 0, 255),
                'body' => $body,
                'data' => $data ?: null,
            ]);
        } catch (\Throwable $e) {
            // Never let the in-app feed break the business action that
            // triggered it (booking, payment, dispatch...).
            Log::warning('[InAppNotifier] failed: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Chunked bulk insert for admin broadcasts — no queue worker on shared
     * hosting, so this runs inline in 500-row batches.
     *
     * @param  iterable<int>|\Illuminate\Database\Eloquent\Builder  $userIds
     * @return int number of notifications written
     */
    public function broadcast($userIds, string $title, ?string $body, array $data = []): int
    {
        $count = 0;
        $now = now();
        $payload = $data ? json_encode($data) : null;

        $write = function (array $ids) use (&$count, $now, $payload, $title, $body) {
            if ($ids === []) {
                return;
            }
            $rows = array_map(fn ($id) => [
                'user_id' => (int) $id,
                'type' => 'broadcast',
                'title' => mb_substr($title, 0, 255),
                'body' => $body,
                'data' => $payload,
                'created_at' => $now,
                'updated_at' => $now,
            ], $ids);
            DB::table('user_notifications')->insert($rows);
            $count += count($rows);
        };

        if ($userIds instanceof \Illuminate\Database\Eloquent\Builder) {
            $userIds->select('id')->orderBy('id')->chunk(500, function ($users) use ($write) {
                $write($users->pluck('id')->all());
            });

            return $count;
        }

        foreach (array_chunk(array_values(array_unique(is_array($userIds) ? $userIds : iterator_to_array($userIds))), 500) as $chunk) {
            $write($chunk);
        }

        return $count;
    }
}
